<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoMonitorProject;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * GEO 监测项目定时计划配置。
 */
class GeoMonitoringScheduleController extends Controller
{
    /**
     * @param  GeoMonitorScheduleService  $scheduleService  计划服务
     */
    public function __construct(
        private readonly GeoMonitorScheduleService $scheduleService,
    ) {}

    /**
     * 保存或更新项目监测计划。
     */
    public function store(Request $request, int $projectId): RedirectResponse
    {
        $project = GeoMonitorProject::query()->find($projectId);

        if ($project === null) {
            return redirect()
                ->route('admin.geo-monitoring.index')
                ->withErrors(__('admin.geo_monitoring.message.project_not_found'));
        }

        try {
            $schedule = $this->scheduleService->upsertForProject($project, $request->all());
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.project', ['projectId' => $projectId])
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo-monitoring.project', ['projectId' => $projectId])
            ->with('message', __('admin.geo_monitoring.schedule.saved', [
                'next' => $schedule->next_run_at?->timezone($schedule->timezone)->format('Y-m-d H:i') ?? '-',
            ]));
    }
}
