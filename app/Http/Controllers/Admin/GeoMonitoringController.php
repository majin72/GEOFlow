<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorRun;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorAttributionReportService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorEvidenceService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRunOpsService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRunService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRuntimeConfig;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * 后台 GEO 引用度监测页面。
 */
class GeoMonitoringController extends Controller
{
    /**
     * @param  GeoMonitorRunService  $runService  批次编排
     * @param  GeoMonitorRunOpsService  $runOpsService  批次运维
     * @param  GeoMonitorEvidenceService  $evidenceService  证据访问
     * @param  GeoMonitorAttributionReportService  $reportService  引用度报表
     */
    public function __construct(
        private readonly GeoMonitorRunService $runService,
        private readonly GeoMonitorRunOpsService $runOpsService,
        private readonly GeoMonitorEvidenceService $evidenceService,
        private readonly GeoMonitorAttributionReportService $reportService,
    ) {}

    /**
     * 监测项目列表。
     */
    public function index(Request $request): View
    {
        $projects = GeoMonitorProject::query()
            ->withCount(['prompts', 'runs'])
            ->orderByDesc('updated_at')
            ->paginate((int) config('geoflow.admin_items_per_page', 20));

        return view('admin.geo-monitoring.index', [
            'pageTitle' => __('admin.geo_monitoring.page_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'projects' => $projects,
            'isOperational' => $this->runService->isOperational(),
            'sidecarHealth' => $this->runService->sidecarHealth(),
            'runtimeConfig' => GeoMonitorRuntimeConfig::fromConfig(),
        ]);
    }

    /**
     * 项目详情：问题集、历史 run、触发新 run。
     */
    public function showProject(int $projectId): View|RedirectResponse
    {
        $project = GeoMonitorProject::query()
            ->with(['prompts' => fn ($q) => $q->where('is_enabled', true)->orderBy('code'), 'schedule'])
            ->find($projectId);

        if ($project === null) {
            return redirect()
                ->route('admin.geo-monitoring.index')
                ->withErrors(__('admin.geo_monitoring.message.project_not_found'));
        }

        $platforms = GeoMonitorPlatform::query()
            ->where('is_enabled', true)
            ->orderBy('code')
            ->get();

        $runs = GeoMonitorRun::query()
            ->where('project_id', $project->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('admin.geo-monitoring.project', [
            'pageTitle' => __('admin.geo_monitoring.project_title', ['name' => $project->name]),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'project' => $project,
            'platforms' => $platforms,
            'runs' => $runs,
            'schedule' => $project->schedule,
            'projectReport' => $this->reportService->buildProjectSummary($project),
            'isOperational' => $this->runService->isOperational(),
        ]);
    }

    /**
     * 手动触发一次批次 run。
     */
    public function storeRun(Request $request, int $projectId): RedirectResponse
    {
        $project = GeoMonitorProject::query()->find($projectId);

        if ($project === null) {
            return redirect()
                ->route('admin.geo-monitoring.index')
                ->withErrors(__('admin.geo_monitoring.message.project_not_found'));
        }

        if (! $this->runService->isOperational()) {
            return redirect()
                ->route('admin.geo-monitoring.project', ['projectId' => $projectId])
                ->withErrors(__('admin.geo_monitoring.message.sidecar_disabled'));
        }

        /** @var list<string> $platformCodes */
        $platformCodes = array_values(array_filter(
            (array) $request->input('platforms', []),
            fn ($code): bool => is_string($code) && $code !== '',
        ));

        try {
            $run = $this->runService->startRun(
                $project,
                $platformCodes,
                $request->user('admin'),
            );
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.project', ['projectId' => $projectId])
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo-monitoring.run', ['runId' => $run->id])
            ->with('message', __('admin.geo_monitoring.message.run_started', ['id' => $run->id]));
    }

    /**
     * 批次运行详情与观测列表。
     */
    public function showRun(int $runId): View|RedirectResponse
    {
        $run = GeoMonitorRun::query()
            ->with([
                'project',
                'observations.platform',
                'observations.prompt',
                'observations.citations',
                'observations.mentions',
                'observations.retriedFrom',
                'observations.retries',
                'observations.resourceAssignment.account',
                'observations.resourceAssignment.browserProfile',
                'observations.resourceAssignment.proxyEndpoint',
            ])
            ->find($runId);

        if ($run === null) {
            return redirect()
                ->route('admin.geo-monitoring.index')
                ->withErrors(__('admin.geo_monitoring.message.run_not_found'));
        }

        $evidenceTypesByObservation = [];

        foreach ($run->observations as $observation) {
            $evidenceTypesByObservation[$observation->id] = $this->evidenceService->availableTypes($observation);
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $runLogs = is_array($meta['logs'] ?? null) ? array_reverse($meta['logs']) : [];

        return view('admin.geo-monitoring.run', [
            'pageTitle' => __('admin.geo_monitoring.run_title', ['id' => $run->id]),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'run' => $run,
            'runReport' => $this->reportService->buildRunReport($run),
            'runLogs' => $runLogs,
            'canCancelRun' => $this->runOpsService->canCancel($run),
            'canRetryFailed' => $run->observations->contains(
                fn ($observation): bool => $this->runOpsService->canRetryObservation($observation),
            ),
            'evidenceTypesByObservation' => $evidenceTypesByObservation,
        ]);
    }
}
