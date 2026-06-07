<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorBrowserProfile;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProxyEndpoint;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorMaintenanceService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceScheduler;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorSidecarAccountsExporter;
use App\Support\AdminWeb;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * GEO 监测平台账号后台 CRUD。
 */
class GeoMonitoringAccountController extends Controller
{
    /**
     * 账号列表。
     */
    public function index(): View
    {
        $accounts = GeoMonitorAccount::query()
            ->with(['platform', 'proxyEndpoint', 'browserProfile'])
            ->orderBy('platform_id')
            ->orderBy('external_id')
            ->get();

        $scheduler = GeoMonitorResourceScheduler::fromConfig();
        $runtimeStats = [];

        foreach ($accounts as $account) {
            $runtimeStats[$account->id] = $scheduler->accountRuntimeStats($account);
        }

        return view('admin.geo-monitoring.accounts.index', [
            'pageTitle' => __('admin.geo_monitoring.accounts_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'accounts' => $accounts,
            'runtimeStats' => $runtimeStats,
        ]);
    }

    /**
     * 新建账号表单。
     */
    public function create(): View
    {
        return view('admin.geo-monitoring.accounts.form', [
            'pageTitle' => __('admin.geo_monitoring.account_create_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'accountId' => 0,
            'platforms' => $this->platformOptions(),
            'proxies' => $this->proxyOptions(),
            'form' => $this->emptyAccountForm(),
        ]);
    }

    /**
     * 保存账号：自动分配 ID/Profile，并尽可能拉起登录浏览器。
     */
    public function store(
        Request $request,
        GeoMonitorMaintenanceService $maintenanceService,
        GeoMonitorSidecarAccountsExporter $exporter,
    ): RedirectResponse {
        $payload = $this->validatedCreatePayload($request);

        $account = GeoMonitorAccount::query()->create($payload);
        $this->ensureBrowserProfile($account, '');

        try {
            $exporter->exportToPocRoot();
        } catch (\RuntimeException) {
            // 新建后仍可进入维护页手动同步
        }

        $redirect = redirect()
            ->route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]);

        $admin = $request->user('admin');

        if ($admin === null || ! $maintenanceService->supportsInteractiveBrowser()) {
            return $redirect->with('message', __('admin.geo_monitoring.message.account_created_onboarding'));
        }

        try {
            $maintenanceService->beginMaintenance($account->fresh() ?? $account, $admin, 'initial_login');
            $session = $maintenanceService->launchInteractiveBrowser($account->fresh() ?? $account, 'login');

            return $redirect
                ->with('message', __('admin.geo_monitoring.message.account_created_launching'))
                ->with('geo_monitor_maintenance_session', $session);
        } catch (\InvalidArgumentException $exception) {
            return $redirect
                ->with('message', __('admin.geo_monitoring.message.account_created_onboarding'))
                ->withErrors($exception->getMessage());
        }
    }

    /**
     * 编辑账号表单。
     */
    public function edit(int $accountId): View|RedirectResponse
    {
        $account = GeoMonitorAccount::query()->with('browserProfile')->find($accountId);

        if ($account === null) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.index')
                ->withErrors(__('admin.geo_monitoring.message.account_not_found'));
        }

        return view('admin.geo-monitoring.accounts.form', [
            'pageTitle' => __('admin.geo_monitoring.account_edit_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'accountId' => $account->id,
            'platforms' => $this->platformOptions(),
            'proxies' => $this->proxyOptions(),
            'form' => $this->accountFormFromModel($account),
        ]);
    }

    /**
     * 更新账号。
     */
    public function update(Request $request, int $accountId): RedirectResponse
    {
        $account = GeoMonitorAccount::query()->find($accountId);

        if ($account === null) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.index')
                ->withErrors(__('admin.geo_monitoring.message.account_not_found'));
        }

        $payload = $this->validatedUpdatePayload($request, $account);

        $account->update($payload);

        if ($request->boolean('create_profile')) {
            $this->ensureBrowserProfile($account, (string) $request->input('profile_storage_path', ''));
        }

        return redirect()
            ->route('admin.geo-monitoring.accounts.index')
            ->with('message', __('admin.geo_monitoring.message.account_updated'));
    }

    /**
     * 清除 Redis 中残留的账号串行锁（无 running 观测时安全）。
     */
    public function clearStaleLocks(GeoMonitorResourceScheduler $scheduler): RedirectResponse
    {
        $released = $scheduler->releaseAllAccountLocks();

        return redirect()
            ->route('admin.geo-monitoring.accounts.index')
            ->with('message', __('admin.geo_monitoring.message.account_locks_cleared', ['count' => $released]));
    }

    /**
     * 将后台账号/代理配置导出到 sidecar accounts.json。
     */
    public function syncSidecarAccounts(GeoMonitorSidecarAccountsExporter $exporter): RedirectResponse
    {
        try {
            $path = $exporter->exportToPocRoot();
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.index')
                ->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo-monitoring.accounts.index')
            ->with('message', __('admin.geo_monitoring.message.accounts_synced', ['path' => $path]));
    }

    /**
     * 切换账号启停（active / disabled）。
     */
    public function toggle(int $accountId): RedirectResponse
    {
        $account = GeoMonitorAccount::query()->find($accountId);

        if ($account === null) {
            return redirect()
                ->route('admin.geo-monitoring.accounts.index')
                ->withErrors(__('admin.geo_monitoring.message.account_not_found'));
        }

        $account->update([
            'status' => $account->status === 'active' ? 'disabled' : 'active',
        ]);

        return redirect()
            ->route('admin.geo-monitoring.accounts.index')
            ->with('message', __('admin.geo_monitoring.message.account_toggled'));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAccountForm(): array
    {
        return [
            'platform_id' => '',
            'external_id' => '',
            'label' => '',
            'status' => 'needs_login',
            'profile_storage_path' => '',
            'proxy_endpoint_id' => '',
            'daily_quota' => '',
            'hourly_quota' => '',
            'create_profile' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountFormFromModel(GeoMonitorAccount $account): array
    {
        return [
            'platform_id' => (string) $account->platform_id,
            'external_id' => $account->external_id,
            'label' => $account->label,
            'status' => $account->status,
            'profile_storage_path' => $account->profile_storage_path,
            'proxy_endpoint_id' => (string) ($account->proxy_endpoint_id ?? ''),
            'daily_quota' => $account->daily_quota ?? '',
            'hourly_quota' => $account->hourly_quota ?? '',
            'create_profile' => $account->browserProfile === null,
        ];
    }

    /**
     * 校验并组装新建账号载荷（仅平台 + 名称，其余自动推导）。
     *
     * @return array{
     *     platform_id: int,
     *     external_id: string,
     *     label: string,
     *     status: string,
     *     profile_storage_path: string,
     *     proxy_endpoint_id: int|null,
     *     daily_quota: int|null,
     *     hourly_quota: int|null
     * }
     */
    private function validatedCreatePayload(Request $request): array
    {
        $payload = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:geo_monitor_platforms,id'],
            'label' => ['required', 'string', 'max:160'],
        ], [
            'label.required' => __('admin.geo_monitoring.error.account_name_required'),
        ]);

        $platformId = (int) $payload['platform_id'];
        $label = trim((string) $payload['label']);
        $externalId = $this->generateExternalId($platformId, $label);

        return [
            'platform_id' => $platformId,
            'external_id' => $externalId,
            'label' => $label,
            'status' => 'needs_login',
            'profile_storage_path' => 'profiles/'.$externalId,
            'proxy_endpoint_id' => null,
            'daily_quota' => null,
            'hourly_quota' => null,
        ];
    }

    /**
     * 校验并组装编辑账号载荷。
     *
     * @return array{
     *     platform_id: int,
     *     external_id: string,
     *     label: string,
     *     status: string,
     *     profile_storage_path: string,
     *     proxy_endpoint_id: int|null,
     *     daily_quota: int|null,
     *     hourly_quota: int|null
     * }
     */
    private function validatedUpdatePayload(Request $request, GeoMonitorAccount $account): array
    {
        $payload = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:geo_monitor_platforms,id'],
            'label' => ['required', 'string', 'max:160'],
            'status' => ['required', 'in:active,disabled,needs_login,cooldown,needs_maintenance'],
            'profile_storage_path' => ['nullable', 'string', 'max:500'],
            'proxy_endpoint_id' => ['nullable', 'integer', 'exists:geo_monitor_proxy_endpoints,id'],
            'daily_quota' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'hourly_quota' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ], [
            'label.required' => __('admin.geo_monitoring.error.account_name_required'),
        ]);

        $profilePath = trim((string) ($payload['profile_storage_path'] ?? ''));

        if ($profilePath === '') {
            $profilePath = $account->profile_storage_path;
        }

        return [
            'platform_id' => (int) $payload['platform_id'],
            'external_id' => $account->external_id,
            'label' => trim((string) $payload['label']),
            'status' => (string) $payload['status'],
            'profile_storage_path' => $profilePath,
            'proxy_endpoint_id' => filled($payload['proxy_endpoint_id'] ?? null)
                ? (int) $payload['proxy_endpoint_id']
                : null,
            'daily_quota' => filled($payload['daily_quota'] ?? null) ? (int) $payload['daily_quota'] : null,
            'hourly_quota' => filled($payload['hourly_quota'] ?? null) ? (int) $payload['hourly_quota'] : null,
        ];
    }

    /**
     * 根据平台与显示名称生成唯一 external_id（sidecar 用）。
     *
     * @param  int  $platformId  平台主键
     * @param  string  $label  用户填写的名称
     */
    private function generateExternalId(int $platformId, string $label): string
    {
        $platform = GeoMonitorPlatform::query()->findOrFail($platformId);
        $slug = (string) preg_replace(
            '/[^a-z0-9_]/',
            '',
            Str::lower(Str::slug($label, '_')),
        );

        if ($slug === '') {
            $sequence = GeoMonitorAccount::query()->where('platform_id', $platformId)->count() + 1;
            $slug = 'account_'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
        }

        $candidate = $platform->code.'_'.$slug;
        $suffix = 1;

        while (GeoMonitorAccount::query()
            ->where('platform_id', $platformId)
            ->where('external_id', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = $platform->code.'_'.$slug.'_'.$suffix;
        }

        return $candidate;
    }

    /**
     * 为账号创建或更新浏览器 profile 绑定。
     */
    private function ensureBrowserProfile(GeoMonitorAccount $account, string $storagePath): void
    {
        $path = trim($storagePath) !== '' ? trim($storagePath) : $account->profile_storage_path;

        GeoMonitorBrowserProfile::query()->updateOrCreate(
            ['account_id' => $account->id],
            [
                'profile_key' => $account->external_id,
                'storage_path' => $path,
                'health_status' => 'unknown',
            ],
        );
    }

    /**
     * @return Collection<int, GeoMonitorPlatform>
     */
    private function platformOptions()
    {
        return GeoMonitorPlatform::query()->orderBy('code')->get();
    }

    /**
     * @return Collection<int, GeoMonitorProxyEndpoint>
     */
    private function proxyOptions()
    {
        return GeoMonitorProxyEndpoint::query()->orderBy('label')->get();
    }
}
