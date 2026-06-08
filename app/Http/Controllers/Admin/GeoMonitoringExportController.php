<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorRun;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorReportExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GEO 监测报告 CSV 导出。
 */
class GeoMonitoringExportController extends Controller
{
    /**
     * @param  GeoMonitorReportExportService  $exportService  导出服务
     */
    public function __construct(
        private readonly GeoMonitorReportExportService $exportService,
    ) {}

    /**
     * 导出单批次 CSV。
     */
    public function run(int $runId): StreamedResponse
    {
        $run = GeoMonitorRun::query()->with('project')->find($runId);

        if ($run === null) {
            abort(404, __('admin.geo_monitoring.message.run_not_found'));
        }

        return $this->exportService->streamRunCsv($run);
    }

    /**
     * 导出项目最近一次已完成批次 CSV。
     */
    public function project(int $projectId): StreamedResponse
    {
        $project = GeoMonitorProject::query()->find($projectId);

        if ($project === null) {
            abort(404, __('admin.geo_monitoring.message.project_not_found'));
        }

        return $this->exportService->streamProjectLatestCsv($project);
    }
}
