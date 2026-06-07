<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorRun;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorEvidenceService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRunOpsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GEO 监测批次运维：证据查看/下载、重跑、取消。
 */
class GeoMonitoringRunOpsController extends Controller
{
    /**
     * @param  GeoMonitorEvidenceService  $evidenceService  证据访问
     * @param  GeoMonitorRunOpsService  $runOpsService  批次运维
     */
    public function __construct(
        private readonly GeoMonitorEvidenceService $evidenceService,
        private readonly GeoMonitorRunOpsService $runOpsService,
    ) {}

    /**
     * 内联预览证据文件。
     */
    public function showEvidence(int $runId, int $observationId, string $type): BinaryFileResponse|RedirectResponse
    {
        $observation = $this->resolveObservation($runId, $observationId);

        if ($observation === null) {
            return $this->redirectRunNotFound($runId);
        }

        try {
            return $this->evidenceService->buildFileResponse($observation, $type, download: false);
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.run', ['runId' => $runId])
                ->withErrors($exception->getMessage());
        }
    }

    /**
     * 下载证据文件。
     */
    public function downloadEvidence(int $runId, int $observationId, string $type): BinaryFileResponse|RedirectResponse
    {
        $observation = $this->resolveObservation($runId, $observationId);

        if ($observation === null) {
            return $this->redirectRunNotFound($runId);
        }

        try {
            return $this->evidenceService->buildFileResponse($observation, $type, download: true);
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.run', ['runId' => $runId])
                ->withErrors($exception->getMessage());
        }
    }

    /**
     * 单条观测重跑。
     */
    public function retryObservation(Request $request, int $runId, int $observationId): RedirectResponse
    {
        $observation = $this->resolveObservation($runId, $observationId);

        if ($observation === null) {
            return $this->redirectRunNotFound($runId);
        }

        try {
            $retry = $this->runOpsService->retryObservation($observation, $request->user('admin'));
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.run', ['runId' => $runId])
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo-monitoring.run', ['runId' => $runId])
            ->with('message', __('admin.geo_monitoring.message.observation_retry_queued', ['id' => $retry->id]));
    }

    /**
     * 批量重跑批次内可重试的失败观测。
     */
    public function retryFailed(Request $request, int $runId): RedirectResponse
    {
        $run = GeoMonitorRun::query()->find($runId);

        if ($run === null) {
            return redirect()
                ->route('admin.geo-monitoring.index')
                ->withErrors(__('admin.geo_monitoring.message.run_not_found'));
        }

        try {
            $count = $this->runOpsService->retryFailedObservations($run, $request->user('admin'));
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.run', ['runId' => $runId])
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo-monitoring.run', ['runId' => $runId])
            ->with('message', __('admin.geo_monitoring.message.batch_retry_queued', ['count' => $count]));
    }

    /**
     * 取消尚未完成的批次。
     */
    public function cancelRun(Request $request, int $runId): RedirectResponse
    {
        $run = GeoMonitorRun::query()->find($runId);

        if ($run === null) {
            return redirect()
                ->route('admin.geo-monitoring.index')
                ->withErrors(__('admin.geo_monitoring.message.run_not_found'));
        }

        try {
            $this->runOpsService->cancelRun($run, $request->user('admin'));
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.run', ['runId' => $runId])
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo-monitoring.run', ['runId' => $runId])
            ->with('message', __('admin.geo_monitoring.message.run_cancel_requested'));
    }

    /**
     * 解析属于指定批次的观测记录。
     *
     * @param  int  $runId  批次 ID
     * @param  int  $observationId  观测 ID
     */
    private function resolveObservation(int $runId, int $observationId): ?GeoMonitorObservation
    {
        return GeoMonitorObservation::query()
            ->where('run_id', $runId)
            ->where('id', $observationId)
            ->first();
    }

    /**
     * 观测不存在时跳回批次页。
     *
     * @param  int  $runId  批次 ID
     */
    private function redirectRunNotFound(int $runId): RedirectResponse
    {
        if (GeoMonitorRun::query()->where('id', $runId)->exists()) {
            return redirect()
                ->route('admin.geo-monitoring.run', ['runId' => $runId])
                ->withErrors(__('admin.geo_monitoring.message.observation_not_found'));
        }

        return redirect()
            ->route('admin.geo-monitoring.index')
            ->withErrors(__('admin.geo_monitoring.message.run_not_found'));
    }
}
