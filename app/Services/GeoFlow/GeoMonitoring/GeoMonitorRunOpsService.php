<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Jobs\ProcessGeoMonitorProbeJob;
use App\Models\Admin;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * GEO 监测批次运维：重跑、取消、运行日志。
 */
class GeoMonitorRunOpsService
{
    /**
     * 允许批量/单条重跑的观测状态。
     *
     * @var list<string>
     */
    private const RETRYABLE_STATUSES = [
        'failed',
        'partial',
        'captcha_required',
        'needs_login',
        'selector_miss',
    ];

    /**
     * 向批次 meta 追加一条运行日志。
     *
     * @param  GeoMonitorRun  $run  批次运行
     * @param  string  $level  日志级别
     * @param  string  $message  日志内容
     */
    public function appendRunLog(GeoMonitorRun $run, string $level, string $message): void
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $logs = is_array($meta['logs'] ?? null) ? $meta['logs'] : [];
        $logs[] = [
            'at' => now()->toIso8601String(),
            'level' => $level,
            'message' => $message,
        ];

        if (count($logs) > 200) {
            $logs = array_slice($logs, -200);
        }

        $meta['logs'] = $logs;
        $run->update(['meta' => $meta]);
        $run->refresh();
    }

    /**
     * 单条观测重跑：创建新 observation，保留原记录与证据。
     *
     * @param  GeoMonitorObservation  $source  源观测
     * @param  Admin|null  $admin  操作管理员
     */
    public function retryObservation(GeoMonitorObservation $source, ?Admin $admin = null): GeoMonitorObservation
    {
        if (! in_array($source->status, self::RETRYABLE_STATUSES, true)) {
            throw new InvalidArgumentException(__('admin.geo_monitoring.error.observation_not_retryable'));
        }

        if ($source->retries()->whereIn('status', ['pending', 'running'])->exists()) {
            throw new InvalidArgumentException(__('admin.geo_monitoring.error.observation_retry_in_progress'));
        }

        $retry = DB::transaction(function () use ($source): GeoMonitorObservation {
            $retry = GeoMonitorObservation::query()->create([
                'run_id' => $source->run_id,
                'project_id' => $source->project_id,
                'prompt_id' => $source->prompt_id,
                'platform_id' => $source->platform_id,
                'account_id' => $source->account_id,
                'retried_from_observation_id' => $source->id,
                'prompt_text_snapshot' => $source->prompt_text_snapshot,
                'status' => 'pending',
                'login_status' => 'unknown',
            ]);

            $run = $source->run;
            $run->update([
                'status' => 'running',
                'observation_count' => $run->observation_count + 1,
                'finished_at' => null,
            ]);

            return $retry;
        });

        ProcessGeoMonitorProbeJob::dispatch($retry->id);

        $this->appendRunLog(
            $retry->run,
            'info',
            __('admin.geo_monitoring.log.observation_retry_queued', [
                'source_id' => $source->id,
                'retry_id' => $retry->id,
                'admin' => $admin?->display_name ?? 'system',
            ]),
        );

        return $retry;
    }

    /**
     * 批量重跑批次内可重试的失败观测。
     *
     * @param  GeoMonitorRun  $run  批次运行
     * @param  Admin|null  $admin  操作管理员
     * @return int 新创建的重跑观测数量
     */
    public function retryFailedObservations(GeoMonitorRun $run, ?Admin $admin = null): int
    {
        $sources = GeoMonitorObservation::query()
            ->where('run_id', $run->id)
            ->whereIn('status', self::RETRYABLE_STATUSES)
            ->whereDoesntHave('retries', fn ($query) => $query->whereIn('status', ['pending', 'running']))
            ->orderBy('id')
            ->get();

        if ($sources->isEmpty()) {
            throw new InvalidArgumentException(__('admin.geo_monitoring.error.no_retryable_observations'));
        }

        $created = 0;

        foreach ($sources as $source) {
            $this->retryObservation($source, $admin);
            $created++;
        }

        $this->appendRunLog(
            $run->fresh() ?? $run,
            'info',
            __('admin.geo_monitoring.log.batch_retry_queued', [
                'count' => $created,
                'admin' => $admin?->display_name ?? 'system',
            ]),
        );

        return $created;
    }

    /**
     * 取消尚未完成的批次：pending 直接取消，running 仅标记取消请求。
     *
     * @param  GeoMonitorRun  $run  批次运行
     * @param  Admin|null  $admin  操作管理员
     */
    public function cancelRun(GeoMonitorRun $run, ?Admin $admin = null): void
    {
        if (in_array($run->status, ['succeeded', 'failed', 'partial', 'cancelled'], true)
            && ! $this->hasActiveObservations($run)) {
            throw new InvalidArgumentException(__('admin.geo_monitoring.error.run_already_finished'));
        }

        GeoMonitorObservation::query()
            ->where('run_id', $run->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        $meta = is_array($run->meta) ? $run->meta : [];
        $meta['cancel_requested_at'] = now()->toIso8601String();
        $meta['cancel_requested_by'] = $admin?->display_name;

        $run->update([
            'status' => 'cancelling',
            'meta' => $meta,
        ]);

        $this->appendRunLog(
            $run,
            'warning',
            __('admin.geo_monitoring.log.run_cancel_requested', [
                'admin' => $admin?->display_name ?? 'system',
            ]),
        );

        $this->finalizeCancelledRunIfReady($run->fresh() ?? $run);
    }

    /**
     * 若批次已无 pending/running 观测，则落盘为 cancelled。
     *
     * @param  GeoMonitorRun  $run  批次运行
     */
    public function finalizeCancelledRunIfReady(GeoMonitorRun $run): void
    {
        $meta = is_array($run->meta) ? $run->meta : [];

        if (! isset($meta['cancel_requested_at'])) {
            return;
        }

        if ($this->hasActiveObservations($run)) {
            return;
        }

        $run->update([
            'status' => 'cancelled',
            'finished_at' => $run->finished_at ?? now(),
        ]);

        $this->appendRunLog($run, 'warning', __('admin.geo_monitoring.log.run_cancelled'));
    }

    /**
     * 批次是否仍有待执行或执行中的观测。
     *
     * @param  GeoMonitorRun  $run  批次运行
     */
    public function hasActiveObservations(GeoMonitorRun $run): bool
    {
        return GeoMonitorObservation::query()
            ->where('run_id', $run->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /**
     * 批次是否允许展示取消按钮。
     *
     * @param  GeoMonitorRun  $run  批次运行
     */
    public function canCancel(GeoMonitorRun $run): bool
    {
        if (in_array($run->status, ['cancelled', 'cancelling'], true)) {
            return false;
        }

        return $this->hasActiveObservations($run)
            || in_array($run->status, ['running', 'pending'], true);
    }

    /**
     * 观测是否允许单条重跑。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     */
    public function canRetryObservation(GeoMonitorObservation $observation): bool
    {
        return in_array($observation->status, self::RETRYABLE_STATUSES, true)
            && ! $observation->retries()->whereIn('status', ['pending', 'running'])->exists();
    }
}
