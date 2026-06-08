<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoMonitorAlert;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorDashboardService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * GEO 监测产品化运行面板。
 */
class GeoMonitoringDashboardController extends Controller
{
    /**
     * @param  GeoMonitorDashboardService  $dashboardService  面板数据
     */
    public function __construct(
        private readonly GeoMonitorDashboardService $dashboardService,
    ) {}

    /**
     * 运行面板首页。
     */
    public function index(): View
    {
        return view('admin.geo-monitoring.dashboard', [
            'pageTitle' => __('admin.geo_monitoring.dashboard_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'dashboard' => $this->dashboardService->build(),
        ]);
    }

    /**
     * 确认告警（标记为已读）。
     */
    public function acknowledgeAlert(int $alertId): RedirectResponse
    {
        $alert = GeoMonitorAlert::query()->find($alertId);

        if ($alert === null) {
            return redirect()
                ->route('admin.geo-monitoring.dashboard')
                ->withErrors(__('admin.geo_monitoring.alert.not_found'));
        }

        if ($alert->acknowledged_at === null) {
            $alert->update(['acknowledged_at' => now()]);
        }

        return redirect()
            ->route('admin.geo-monitoring.dashboard')
            ->with('message', __('admin.geo_monitoring.alert.acknowledged'));
    }
}
