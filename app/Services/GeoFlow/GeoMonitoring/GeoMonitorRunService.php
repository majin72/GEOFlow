<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Exceptions\GeoFlow\GeoMonitorSidecarException;
use App\Jobs\ProcessGeoMonitorProbeJob;
use App\Models\Admin;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 编排 GEO 监测批次运行：创建 run、观测占位、派发队列。
 */
class GeoMonitorRunService
{
    /**
     * @param  ScraplingBridgeClient  $bridgeClient  sidecar 客户端
     * @param  GeoMonitorConfig  $config  功能配置
     */
    public function __construct(
        private readonly ScraplingBridgeClient $bridgeClient,
        private readonly GeoMonitorConfig $config,
        private readonly GeoMonitorRunOpsService $runOpsService,
        private readonly ?GeoMonitorAttributionScorer $scorer = null,
        private readonly ?GeoMonitorResourceScheduler $resourceScheduler = null,
    ) {}

    /**
     * 功能是否可在后台触发。
     */
    public function isOperational(): bool
    {
        return $this->config->isOperational();
    }

    /**
     * 创建批次并派发每条 prompt×platform 的探测 Job。
     *
     * @param  GeoMonitorProject  $project  监测项目
     * @param  list<string>  $platformCodes  平台 code 列表（空则使用全部已启用平台）
     * @param  Admin|null  $admin  触发管理员
     * @return GeoMonitorRun 新创建的 run
     */
    public function startRun(
        GeoMonitorProject $project,
        array $platformCodes = [],
        ?Admin $admin = null,
        ?array $triggerMeta = null,
    ): GeoMonitorRun {
        if (! $this->isOperational()) {
            throw new InvalidArgumentException('GEO 监测未启用或未配置 sidecar URL');
        }

        app(GeoMonitorSidecarAccountsExporter::class)->exportToPocRoot();

        $platforms = $this->resolvePlatforms($platformCodes);
        $prompts = GeoMonitorPrompt::query()
            ->where('project_id', $project->id)
            ->where('is_enabled', true)
            ->orderBy('code')
            ->get();

        if ($prompts->isEmpty()) {
            throw new InvalidArgumentException('项目下没有监测问题，请在项目设置里每行填写一个问题');
        }

        if ($platforms->isEmpty()) {
            throw new InvalidArgumentException('没有可用的监测平台');
        }

        $run = DB::transaction(function () use ($project, $admin, $platforms, $prompts, $triggerMeta): GeoMonitorRun {
            $run = GeoMonitorRun::query()->create([
                'project_id' => $project->id,
                'triggered_by_admin_id' => $admin?->id,
                'status' => 'running',
                'platform_scope' => $platforms->pluck('code')->values()->all(),
                'prompt_count' => $prompts->count(),
                'observation_count' => $prompts->count() * $platforms->count(),
                'success_count' => 0,
                'started_at' => now(),
                'meta' => $triggerMeta !== null && $triggerMeta !== [] ? $triggerMeta : null,
            ]);

            foreach ($platforms as $platform) {
                $bundle = $this->scheduler()->selectForPlatform($platform);
                $account = $bundle?->account;

                foreach ($prompts as $prompt) {
                    $observation = GeoMonitorObservation::query()->create([
                        'run_id' => $run->id,
                        'project_id' => $project->id,
                        'prompt_id' => $prompt->id,
                        'platform_id' => $platform->id,
                        'account_id' => $account?->id,
                        'prompt_text_snapshot' => $prompt->prompt_text,
                        'status' => 'pending',
                        'login_status' => 'unknown',
                    ]);

                    ProcessGeoMonitorProbeJob::dispatch($observation->id);
                }
            }

            return $run;
        });

        return $run->fresh(['observations']);
    }

    /**
     * 根据观测终态刷新 run 汇总状态。
     *
     * @param  GeoMonitorRun  $run  批次运行
     */
    public function refreshRunStatus(GeoMonitorRun $run): void
    {
        $run->load('observations');

        $activeCount = $run->observations
            ->filter(fn (GeoMonitorObservation $item): bool => in_array($item->status, ['pending', 'running'], true))
            ->count();

        if ($activeCount > 0) {
            if ($run->status !== 'cancelling') {
                $run->update(['status' => 'running']);
            }

            return;
        }

        $meta = is_array($run->meta) ? $run->meta : [];

        if (isset($meta['cancel_requested_at'])) {
            $this->runOpsService->finalizeCancelledRunIfReady($run->fresh() ?? $run);

            return;
        }

        $successStatuses = ['success', 'partial'];
        $terminalObservations = $run->observations
            ->reject(fn (GeoMonitorObservation $item): bool => $item->status === 'cancelled');

        $successCount = $terminalObservations
            ->filter(fn (GeoMonitorObservation $item): bool => in_array($item->status, $successStatuses, true))
            ->count();

        $failed = $terminalObservations->reject(
            fn (GeoMonitorObservation $item): bool => in_array($item->status, $successStatuses, true)
        );

        $failedSummary = $failed
            ->groupBy('status')
            ->map(fn (Collection $group, string $status): string => $status.':'.$group->count())
            ->implode(', ');

        $allFailed = $successCount === 0 && $terminalObservations->isNotEmpty();
        $allSucceeded = $failed->isEmpty();

        $run->update([
            'success_count' => $successCount,
            'failed_summary' => $failedSummary !== '' ? $failedSummary : null,
            'status' => $allSucceeded ? 'succeeded' : ($allFailed ? 'failed' : 'partial'),
            'finished_at' => now(),
        ]);

        $this->scoreRunIfPossible($run->fresh() ?? $run);
        $this->evaluateAlerts($run->fresh() ?? $run);
    }

    /**
     * 批次结束后触发异常告警评估。
     *
     * @param  GeoMonitorRun  $run  批次运行
     */
    private function evaluateAlerts(GeoMonitorRun $run): void
    {
        if (! in_array($run->status, ['succeeded', 'partial', 'failed'], true)) {
            return;
        }

        app(GeoMonitorAlertService::class)->evaluateCompletedRun($run);
    }

    /**
     * 批次结束后写入 run 级评分快照。
     *
     * @param  GeoMonitorRun  $run  批次运行
     */
    private function scoreRunIfPossible(GeoMonitorRun $run): void
    {
        $scorer = $this->scorer ?? GeoMonitorAttributionScorer::fromConfig();
        $scorer->scoreRun($run);
    }

    /**
     * sidecar 健康摘要（供后台展示）。
     *
     * @return array<string, mixed>|null
     */
    public function sidecarHealth(): ?array
    {
        if (! $this->bridgeClient->isOperational()) {
            return null;
        }

        try {
            return $this->bridgeClient->health();
        } catch (GeoMonitorSidecarException) {
            return ['service' => 'geo-monitor-sidecar', 'reachable' => false];
        }
    }

    /**
     * @param  list<string>  $platformCodes  指定平台 code
     * @return Collection<int, GeoMonitorPlatform>
     */
    private function resolvePlatforms(array $platformCodes): Collection
    {
        $query = GeoMonitorPlatform::query()->where('is_enabled', true);

        if ($platformCodes !== []) {
            $query->whereIn('code', $platformCodes);
        }

        return $query->orderBy('code')->get();
    }

    /**
     * 获取资源池调度器实例。
     */
    private function scheduler(): GeoMonitorResourceScheduler
    {
        return $this->resourceScheduler ?? GeoMonitorResourceScheduler::fromConfig();
    }
}
