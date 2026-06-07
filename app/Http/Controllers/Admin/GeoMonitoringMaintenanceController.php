<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoMonitorAccount;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorMaintenanceService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorSidecarAccountsExporter;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GEO 监测账号 noVNC 维护指引与事件操作。
 */
class GeoMonitoringMaintenanceController extends Controller
{
    /**
     * 维护指引页。
     */
    public function show(int $accountId, GeoMonitorMaintenanceService $maintenanceService): View|RedirectResponse
    {
        $account = $this->findAccount($accountId);

        if ($account === null) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.index')
                ->withErrors(__('admin.geo_monitoring.message.account_not_found'));
        }

        return view('admin.geo-monitoring.accounts.maintenance', [
            'pageTitle' => __('admin.geo_monitoring.maintenance_title', ['account' => $account->external_id]),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'account' => $account,
            'guide' => $maintenanceService->buildGuideContext($account),
            'events' => $maintenanceService->recentEvents($account),
            'interactiveSession' => session('geo_monitor_maintenance_session'),
        ]);
    }

    /**
     * 开始维护并记录事件。
     */
    public function start(Request $request, int $accountId, GeoMonitorMaintenanceService $maintenanceService): RedirectResponse
    {
        $account = $this->findAccount($accountId);

        if ($account === null) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.index')
                ->withErrors(__('admin.geo_monitoring.message.account_not_found'));
        }

        $admin = $request->user('admin');

        if ($admin === null) {
            abort(403);
        }

        $maintenanceService->beginMaintenance(
            $account,
            $admin,
            (string) $request->input('trigger_reason', ''),
        );

        return redirect()
            ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
            ->with('message', __('admin.geo_monitoring.maintenance_started'));
    }

    /**
     * 一键拉起可见浏览器（sidecar 可用；无头 Linux 通过 noVNC 远程桌面展示）。
     */
    public function launchBrowser(
        Request $request,
        int $accountId,
        GeoMonitorMaintenanceService $maintenanceService,
        GeoMonitorSidecarAccountsExporter $exporter,
    ): RedirectResponse {
        $account = $this->findAccount($accountId);

        if ($account === null) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.index')
                ->withErrors(__('admin.geo_monitoring.message.account_not_found'));
        }

        $admin = $request->user('admin');

        if ($admin === null) {
            abort(403);
        }

        try {
            $exporter->exportToPocRoot();
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
                ->withErrors($exception->getMessage());
        }

        $maintenanceService->beginMaintenance(
            $account->fresh() ?? $account,
            $admin,
            (string) $request->input('trigger_reason', 'initial_login'),
        );

        $mode = $account->status === 'needs_maintenance' ? 'captcha' : 'login';

        try {
            $session = $maintenanceService->launchInteractiveBrowser($account->fresh() ?? $account, $mode);
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
            ->with('message', __('admin.geo_monitoring.maintenance_browser_launched'))
            ->with('geo_monitor_maintenance_session', $session);
    }

    /**
     * 保存 profile 并关闭维护浏览器。
     */
    public function saveBrowser(
        Request $request,
        int $accountId,
        GeoMonitorMaintenanceService $maintenanceService,
    ): RedirectResponse {
        $account = $this->findAccount($accountId);

        if ($account === null) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.index')
                ->withErrors(__('admin.geo_monitoring.message.account_not_found'));
        }

        $sessionId = trim((string) $request->input('session_id', ''));

        if ($sessionId === '') {
            $flash = session('geo_monitor_maintenance_session');
            $sessionId = is_array($flash) ? (string) ($flash['session_id'] ?? '') : '';
        }

        try {
            $maintenanceService->saveInteractiveBrowser($sessionId);
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
                ->withErrors($exception->getMessage());
        }

        session()->forget('geo_monitor_maintenance_session');

        return redirect()
            ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
            ->with('message', __('admin.geo_monitoring.maintenance_profile_saved'));
    }

    /**
     * 执行 sidecar 登录态健康检查（AJAX 或表单回跳）。
     */
    public function healthCheck(int $accountId, GeoMonitorMaintenanceService $maintenanceService): RedirectResponse
    {
        $account = $this->findAccount($accountId);

        if ($account === null) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.index')
                ->withErrors(__('admin.geo_monitoring.message.account_not_found'));
        }

        $result = $maintenanceService->runHealthCheck($account);

        if ($result['ok']) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
                ->with('message', $result['message']);
        }

        return redirect()
            ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
            ->withErrors($result['message']);
    }

    /**
     * 完成维护：可选附带健康检查结果恢复调度。
     */
    public function complete(Request $request, int $accountId, GeoMonitorMaintenanceService $maintenanceService): RedirectResponse
    {
        $account = $this->findAccount($accountId);

        if ($account === null) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.index')
                ->withErrors(__('admin.geo_monitoring.message.account_not_found'));
        }

        $admin = $request->user('admin');

        if ($admin === null) {
            abort(403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $maintenanceService->completeMaintenance(
                $account,
                $admin,
                isset($validated['notes']) ? trim((string) $validated['notes']) : null,
            );
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
                ->withErrors($exception->getMessage());
        }

        if ($result['health']['ok']) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
                ->with('message', __('admin.geo_monitoring.maintenance_completed_active'));
        }

        return redirect()
            ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id])
            ->withErrors($result['health']['message']);
    }

    /**
     * 查找账号并预加载关联。
     */
    private function findAccount(int $accountId): ?GeoMonitorAccount
    {
        return GeoMonitorAccount::query()
            ->with(['platform', 'browserProfile', 'proxyEndpoint'])
            ->find($accountId);
    }
}
