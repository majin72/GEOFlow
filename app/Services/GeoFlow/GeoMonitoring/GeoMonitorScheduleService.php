<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorRun;
use App\Models\GeoMonitorSchedule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * 管理 GEO 监测定时计划：保存配置、计算下次运行时间、按计划创建批次。
 */
class GeoMonitorScheduleService
{
    /**
     * @param  GeoMonitorRunService  $runService  批次编排
     * @param  GeoMonitorConfig  $config  功能配置
     */
    public function __construct(
        private readonly GeoMonitorRunService $runService,
        private readonly GeoMonitorConfig $config,
    ) {}

    /**
     * 创建或更新项目监测计划。
     *
     * @param  GeoMonitorProject  $project  监测项目
     * @param  array<string, mixed>  $payload  表单数据
     * @return GeoMonitorSchedule 保存后的计划
     */
    public function upsertForProject(GeoMonitorProject $project, array $payload): GeoMonitorSchedule
    {
        $frequency = (string) ($payload['frequency'] ?? 'manual');
        if (! in_array($frequency, ['manual', 'daily', 'weekly'], true)) {
            throw new InvalidArgumentException(__('admin.geo_monitoring.schedule.invalid_frequency'));
        }

        /** @var list<string> $platformScope */
        $platformScope = array_values(array_filter(
            (array) ($payload['platform_scope'] ?? []),
            fn ($code): bool => is_string($code) && $code !== '',
        ));

        $timezone = trim((string) ($payload['timezone'] ?? 'Asia/Shanghai'));
        $runTime = $this->normalizeRunTime((string) ($payload['run_time'] ?? '09:00'));
        $weekday = $frequency === 'weekly' ? max(1, min(7, (int) ($payload['weekday'] ?? 1))) : null;
        $isEnabled = filter_var($payload['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $schedule = GeoMonitorSchedule::query()->firstOrNew(['project_id' => $project->id]);
        $schedule->fill([
            'frequency' => $frequency,
            'platform_scope' => $platformScope !== [] ? $platformScope : null,
            'timezone' => $timezone !== '' ? $timezone : 'Asia/Shanghai',
            'run_time' => $runTime,
            'weekday' => $weekday,
            'is_enabled' => $isEnabled && $frequency !== 'manual',
        ]);
        $schedule->next_run_at = $this->computeNextRunAt($schedule);
        $schedule->save();

        return $schedule->fresh(['project']) ?? $schedule;
    }

    /**
     * 扫描到期计划并创建批次（由 Artisan 调度命令调用）。
     *
     * @return array{dispatched: int, skipped: int}
     */
    public function dispatchDueSchedules(): array
    {
        if (! $this->config->isOperational()) {
            return ['dispatched' => 0, 'skipped' => 0];
        }

        if (! GeoMonitorAlertSettings::fromSiteSettings()->scheduleEnabled) {
            return ['dispatched' => 0, 'skipped' => 0];
        }

        $dispatched = 0;
        $skipped = 0;
        $now = now();

        /** @var EloquentCollection<int, GeoMonitorSchedule> $schedules */
        $schedules = GeoMonitorSchedule::query()
            ->with('project')
            ->where('is_enabled', true)
            ->whereIn('frequency', ['daily', 'weekly'])
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->get();

        foreach ($schedules as $schedule) {
            if ($this->dispatchSchedule($schedule) !== null) {
                $dispatched++;
            } else {
                $skipped++;
            }
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped];
    }

    /**
     * 为单条计划创建批次（带项目级锁与窗口去重）。
     *
     * @param  GeoMonitorSchedule  $schedule  监测计划
     * @return GeoMonitorRun|null 成功创建的批次，跳过时为 null
     */
    public function dispatchSchedule(GeoMonitorSchedule $schedule): ?GeoMonitorRun
    {
        $lock = Cache::lock('geo_monitor:schedule:project:'.$schedule->project_id, 120);

        if (! $lock->get()) {
            return null;
        }

        try {
            $schedule->refresh();
            $schedule->loadMissing('project');

            if (! $this->shouldDispatch($schedule)) {
                return null;
            }

            /** @var list<string> $platformCodes */
            $platformCodes = is_array($schedule->platform_scope) ? $schedule->platform_scope : [];

            try {
                $run = $this->runService->startRun(
                    $schedule->project,
                    $platformCodes,
                    null,
                    [
                        'trigger' => 'schedule',
                        'schedule_id' => $schedule->id,
                    ],
                );
            } catch (InvalidArgumentException $exception) {
                Log::warning('GEO monitor schedule dispatch skipped', [
                    'schedule_id' => $schedule->id,
                    'project_id' => $schedule->project_id,
                    'reason' => $exception->getMessage(),
                ]);

                $schedule->update([
                    'next_run_at' => $this->computeNextRunAt($schedule, now()->addMinute()),
                ]);

                return null;
            }

            $schedule->update([
                'last_run_at' => now(),
                'last_run_id' => $run->id,
                'last_dedupe_key' => $this->windowKey($schedule),
                'next_run_at' => $this->computeNextRunAt($schedule, now()->addMinute()),
            ]);

            return $run;
        } finally {
            $lock->release();
        }
    }

    /**
     * 计算计划的下一次运行时间（UTC 存储）。
     *
     * @param  GeoMonitorSchedule  $schedule  监测计划
     * @param  Carbon|null  $from  基准时间（默认当前）
     * @return Carbon|null 下次运行 UTC 时间；手动或未启用时为 null
     */
    public function computeNextRunAt(GeoMonitorSchedule $schedule, ?Carbon $from = null): ?Carbon
    {
        if ($schedule->frequency === 'manual' || ! $schedule->is_enabled) {
            return null;
        }

        $timezone = $schedule->timezone !== '' ? $schedule->timezone : 'Asia/Shanghai';
        $fromLocal = ($from ?? now())->copy()->timezone($timezone);
        [$hour, $minute] = array_pad(explode(':', $schedule->run_time), 2, '0');
        $candidate = $fromLocal->copy()->setTime((int) $hour, (int) $minute, 0);

        if ($schedule->frequency === 'daily') {
            if ($candidate->lessThanOrEqualTo($fromLocal)) {
                $candidate->addDay();
            }

            return $candidate->utc();
        }

        if ($schedule->frequency === 'weekly') {
            $targetWeekday = $schedule->weekday ?? 1;

            for ($attempt = 0; $attempt < 14; $attempt++) {
                if ($candidate->dayOfWeekIso === $targetWeekday && $candidate->greaterThan($fromLocal)) {
                    return $candidate->utc();
                }

                $candidate->addDay()->setTime((int) $hour, (int) $minute, 0);
            }
        }

        return null;
    }

    /**
     * 判断计划是否应在当前窗口触发。
     *
     * @param  GeoMonitorSchedule  $schedule  监测计划
     */
    private function shouldDispatch(GeoMonitorSchedule $schedule): bool
    {
        if (! $schedule->is_enabled || ! in_array($schedule->frequency, ['daily', 'weekly'], true)) {
            return false;
        }

        if ($schedule->next_run_at === null || $schedule->next_run_at->isFuture()) {
            return false;
        }

        if ($schedule->project->status !== 'active') {
            return false;
        }

        $windowKey = $this->windowKey($schedule);
        if ($schedule->last_dedupe_key === $windowKey) {
            return false;
        }

        $hasActiveRun = GeoMonitorRun::query()
            ->where('project_id', $schedule->project_id)
            ->whereIn('status', ['pending', 'running', 'cancelling'])
            ->exists();

        return ! $hasActiveRun;
    }

    /**
     * 构造计划窗口去重键（按项目时区）。
     *
     * @param  GeoMonitorSchedule  $schedule  监测计划
     */
    private function windowKey(GeoMonitorSchedule $schedule): string
    {
        $timezone = $schedule->timezone !== '' ? $schedule->timezone : 'Asia/Shanghai';
        $localNow = now()->timezone($timezone);

        if ($schedule->frequency === 'weekly') {
            return 'weekly:'.$localNow->format('o-\\WW');
        }

        return 'daily:'.$localNow->toDateString();
    }

    /**
     * 规范化 HH:MM 运行时间。
     *
     * @param  string  $runTime  原始时间字符串
     */
    private function normalizeRunTime(string $runTime): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($runTime), $matches) !== 1) {
            return '09:00';
        }

        $hour = max(0, min(23, (int) $matches[1]));
        $minute = max(0, min(59, (int) $matches[2]));

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
