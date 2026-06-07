<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\GeoFlow\GeoMonitorSidecarException;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorRun;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorProbePersister;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceBundle;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceHealthService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceScheduler;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRunOpsService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRunService;
use App\Services\GeoFlow\GeoMonitoring\ScraplingBridgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 队列任务：对单条 geo_monitor_observations 调用 sidecar 并持久化结果。
 */
class ProcessGeoMonitorProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    /**
     * 锁竞争时延迟重试秒数。
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 30, 45, 60, 90];
    }

    /**
     * @param  int  $observationId  观测主键
     */
    public function __construct(
        public readonly int $observationId,
    ) {}

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'geo-monitor',
            'observation:'.$this->observationId,
        ];
    }

    /**
     * @param  ScraplingBridgeClient  $bridgeClient  sidecar 客户端
     * @param  GeoMonitorProbePersister  $persister  结果持久化
     * @param  GeoMonitorRunService  $runService  批次状态刷新
     * @param  GeoMonitorRunOpsService  $runOpsService  批次运维与日志
     * @param  GeoMonitorResourceScheduler  $resourceScheduler  资源池调度
     * @param  GeoMonitorResourceHealthService  $resourceHealth  资源健康回写
     */
    public function handle(
        ScraplingBridgeClient $bridgeClient,
        GeoMonitorProbePersister $persister,
        GeoMonitorRunService $runService,
        GeoMonitorRunOpsService $runOpsService,
        GeoMonitorResourceScheduler $resourceScheduler,
        GeoMonitorResourceHealthService $resourceHealth,
    ): void {
        $observation = GeoMonitorObservation::query()
            ->with(['project', 'prompt', 'platform', 'account.browserProfile', 'account.proxyEndpoint', 'run'])
            ->find($this->observationId);

        if ($observation === null) {
            return;
        }

        if ($observation->status !== 'pending') {
            return;
        }

        $run = $observation->run;

        if ($this->isRunCancellationRequested($run)) {
            $observation->update(['status' => 'cancelled']);
            $runService->refreshRunStatus($run);

            return;
        }

        $bundle = $this->resolveResourceBundle($observation, $resourceScheduler);

        if ($bundle === null) {
            $persister->persistFailure(
                $observation,
                'failed',
                '未找到可调度账号（检查状态、冷却、Profile 健康与代理）',
            );
            $runService->refreshRunStatus($observation->run);

            return;
        }

        if (! $resourceScheduler->acquireAccountLock($bundle->account)) {
            $this->release(30);

            return;
        }

        $observation->update([
            'status' => 'running',
            'account_id' => $bundle->account->id,
        ]);

        $finalStatus = 'failed';

        try {
            $payload = [
                'platform' => $observation->platform->code,
                'account_id' => $bundle->account->external_id,
                'prompt_id' => $observation->prompt->code,
                'prompt_text' => $observation->prompt_text_snapshot,
                'headless' => true,
                'production' => true,
                'skip_login_check' => false,
                'evidence_subdir' => $this->evidenceSubdir($observation),
                'resource' => $bundle->toSidecarResource(),
            ];

            try {
                $result = $bridgeClient->probe($payload);
                $observation = $persister->persist($observation, $result, $bundle->account, $bundle);
                $finalStatus = (string) $observation->status;
            } catch (GeoMonitorSidecarException $exception) {
                $finalStatus = $this->mapExceptionStatus($exception);
                $persister->persistFailure($observation, $finalStatus, $exception->getMessage(), $bundle);
                $runOpsService->appendRunLog(
                    $observation->run()->firstOrFail(),
                    'error',
                    __('admin.geo_monitoring.log.probe_failed', [
                        'observation_id' => $observation->id,
                        'code' => $exception->errorCode,
                        'message' => $exception->getMessage(),
                    ]),
                );
            }
        } finally {
            $resourceHealth->handleProbeOutcome(
                $bundle->account->fresh() ?? $bundle->account,
                $finalStatus,
            );
            $resourceScheduler->releaseAccountLock($bundle->account);
        }

        $runService->refreshRunStatus($observation->run()->firstOrFail());
    }

    /**
     * 队列重试耗尽时，将仍 pending 的观测标记为失败。
     *
     * @param  \Throwable|null  $exception  失败异常
     */
    public function failed(?\Throwable $exception): void
    {
        $observation = GeoMonitorObservation::query()->find($this->observationId);

        if ($observation === null || $observation->status !== 'pending') {
            return;
        }

        $message = $exception !== null
            ? $exception->getMessage()
            : '队列重试耗尽（账号锁竞争或系统异常）';

        app(GeoMonitorProbePersister::class)->persistFailure(
            $observation,
            'failed',
            $message,
        );

        $run = $observation->run;

        if ($run !== null) {
            app(GeoMonitorRunService::class)->refreshRunStatus($run);
        }
    }

    /**
     * 解析观测应使用的资源包：优先沿用预分配账号，否则从池中选取。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     * @param  GeoMonitorResourceScheduler  $scheduler  调度器
     */
    private function resolveResourceBundle(
        GeoMonitorObservation $observation,
        GeoMonitorResourceScheduler $scheduler,
    ): ?GeoMonitorResourceBundle {
        if ($observation->account !== null) {
            $pinned = $scheduler->bundleForAccount($observation->account, 'pinned_account');

            if ($pinned !== null) {
                return $pinned;
            }
        }

        return $scheduler->selectForPlatform($observation->platform);
    }

    /**
     * 批次是否已请求取消（pending 观测应直接跳过）。
     *
     * @param  GeoMonitorRun  $run  批次运行
     */
    private function isRunCancellationRequested(GeoMonitorRun $run): bool
    {
        $meta = is_array($run->meta) ? $run->meta : [];

        return isset($meta['cancel_requested_at']);
    }

    /**
     * 为重跑观测使用独立证据子目录，避免覆盖历史文件。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     */
    private function evidenceSubdir(GeoMonitorObservation $observation): string
    {
        if ($observation->retried_from_observation_id !== null) {
            return 'laravel-run-'.$observation->run_id.'-retry-'.$observation->id;
        }

        return 'laravel-run-'.$observation->run_id;
    }

    /**
     * @param  GeoMonitorSidecarException  $exception  sidecar 异常
     */
    private function mapExceptionStatus(GeoMonitorSidecarException $exception): string
    {
        return match ($exception->errorCode) {
            'CAPTCHA_REQUIRED' => 'captcha_required',
            'NEEDS_LOGIN', 'LOGIN_REQUIRED' => 'needs_login',
            'SELECTOR_MISS', 'SELECTOR_NOT_FOUND' => 'selector_miss',
            'PROBE_TIMEOUT', 'BROWSER_UNAVAILABLE', 'UNAUTHORIZED' => 'failed',
            default => 'failed',
        };
    }
}
