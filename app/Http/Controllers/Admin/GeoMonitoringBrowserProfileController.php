<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorBrowserProfile;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorProfileMaintenanceEvent;
use App\Support\AdminWeb;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GEO 监测浏览器 Profile 后台 CRUD。
 */
class GeoMonitoringBrowserProfileController extends Controller
{
    /**
     * Profile 列表。
     */
    public function index(): View
    {
        $profiles = GeoMonitorBrowserProfile::query()
            ->with(['account.platform'])
            ->orderByDesc('updated_at')
            ->get();

        return view('admin.geo-monitoring.profiles.index', [
            'pageTitle' => __('admin.geo_monitoring.profiles_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'profiles' => $profiles,
        ]);
    }

    /**
     * 新建 Profile 表单。
     */
    public function create(): View
    {
        return view('admin.geo-monitoring.profiles.form', [
            'pageTitle' => __('admin.geo_monitoring.profile_create_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'profileId' => 0,
            'accounts' => $this->unboundAccounts(),
            'form' => $this->emptyProfileForm(),
        ]);
    }

    /**
     * 保存 Profile。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedProfilePayload($request);

        if (GeoMonitorBrowserProfile::query()->where('account_id', $payload['account_id'])->exists()) {
            return back()->withInput()->withErrors(__('admin.geo_monitoring.error.profile_account_bound'));
        }

        GeoMonitorBrowserProfile::query()->create($payload);

        return redirect()
            ->route('admin.geo-monitoring.profiles.index')
            ->with('message', __('admin.geo_monitoring.message.profile_created'));
    }

    /**
     * 编辑 Profile 表单。
     */
    public function edit(int $profileId): View|RedirectResponse
    {
        $profile = GeoMonitorBrowserProfile::query()->with('account.platform')->find($profileId);

        if ($profile === null) {
            return redirect()
                ->route('admin.geo-monitoring.profiles.index')
                ->withErrors(__('admin.geo_monitoring.message.profile_not_found'));
        }

        return view('admin.geo-monitoring.profiles.form', [
            'pageTitle' => __('admin.geo_monitoring.profile_edit_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'profileId' => $profile->id,
            'accounts' => GeoMonitorAccount::query()->with('platform')->orderBy('external_id')->get(),
            'form' => $this->profileFormFromModel($profile),
        ]);
    }

    /**
     * 更新 Profile。
     */
    public function update(Request $request, int $profileId): RedirectResponse
    {
        $profile = GeoMonitorBrowserProfile::query()->find($profileId);

        if ($profile === null) {
            return redirect()
                ->route('admin.geo-monitoring.profiles.index')
                ->withErrors(__('admin.geo_monitoring.message.profile_not_found'));
        }

        $payload = $this->validatedProfilePayload($request, $profile->id);

        if (GeoMonitorBrowserProfile::query()
            ->where('account_id', $payload['account_id'])
            ->where('id', '!=', $profile->id)
            ->exists()) {
            return back()->withInput()->withErrors(__('admin.geo_monitoring.error.profile_account_bound'));
        }

        $profile->update($payload);

        return redirect()
            ->route('admin.geo-monitoring.profiles.index')
            ->with('message', __('admin.geo_monitoring.message.profile_updated'));
    }

    /**
     * 删除浏览器 Profile（账号保留，可稍后重新创建 Profile）。
     */
    public function destroy(int $profileId): RedirectResponse
    {
        $profile = GeoMonitorBrowserProfile::query()->find($profileId);

        if ($profile === null) {
            return redirect()
                ->route('admin.geo-monitoring.profiles.index')
                ->withErrors(__('admin.geo_monitoring.message.profile_not_found'));
        }

        if ($this->profileAccountHasActiveObservations($profile->account_id)) {
            return redirect()
                ->route('admin.geo-monitoring.profiles.index')
                ->withErrors(__('admin.geo_monitoring.error.profile_delete_active_observations'));
        }

        if ($this->profileHasOpenMaintenance($profile->id)) {
            return redirect()
                ->route('admin.geo-monitoring.profiles.index')
                ->withErrors(__('admin.geo_monitoring.error.profile_delete_maintenance_in_progress'));
        }

        $profile->delete();

        return redirect()
            ->route('admin.geo-monitoring.profiles.index')
            ->with('message', __('admin.geo_monitoring.message.profile_deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProfileForm(): array
    {
        return [
            'account_id' => '',
            'profile_key' => '',
            'storage_path' => '',
            'host_node' => '',
            'user_agent_summary' => '',
            'locale' => 'zh-CN',
            'timezone_id' => 'Asia/Shanghai',
            'health_status' => 'unknown',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileFormFromModel(GeoMonitorBrowserProfile $profile): array
    {
        return [
            'account_id' => (string) $profile->account_id,
            'profile_key' => $profile->profile_key,
            'storage_path' => $profile->storage_path,
            'host_node' => (string) ($profile->host_node ?? ''),
            'user_agent_summary' => (string) ($profile->user_agent_summary ?? ''),
            'locale' => $profile->locale,
            'timezone_id' => $profile->timezone_id,
            'health_status' => $profile->health_status,
        ];
    }

    /**
     * @return array{
     *     account_id: int,
     *     profile_key: string,
     *     storage_path: string,
     *     host_node: string|null,
     *     user_agent_summary: string|null,
     *     locale: string,
     *     timezone_id: string,
     *     health_status: string
     * }
     */
    private function validatedProfilePayload(Request $request, int $ignoreProfileId = 0): array
    {
        $payload = $request->validate([
            'account_id' => ['required', 'integer', 'exists:geo_monitor_accounts,id'],
            'profile_key' => ['required', 'string', 'max:120'],
            'storage_path' => ['required', 'string', 'max:500'],
            'host_node' => ['nullable', 'string', 'max:120'],
            'user_agent_summary' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:16'],
            'timezone_id' => ['nullable', 'string', 'max:64'],
            'health_status' => ['required', 'in:unknown,healthy,degraded,maintenance,corrupted'],
        ], [
            'account_id.required' => __('admin.geo_monitoring.error.profile_account_required'),
            'storage_path.required' => __('admin.geo_monitoring.error.profile_storage_required'),
        ]);

        return [
            'account_id' => (int) $payload['account_id'],
            'profile_key' => trim((string) $payload['profile_key']),
            'storage_path' => trim((string) $payload['storage_path']),
            'host_node' => ($node = trim((string) ($payload['host_node'] ?? ''))) !== '' ? $node : null,
            'user_agent_summary' => ($ua = trim((string) ($payload['user_agent_summary'] ?? ''))) !== '' ? $ua : null,
            'locale' => trim((string) ($payload['locale'] ?? 'zh-CN')) ?: 'zh-CN',
            'timezone_id' => trim((string) ($payload['timezone_id'] ?? 'Asia/Shanghai')) ?: 'Asia/Shanghai',
            'health_status' => (string) $payload['health_status'],
        ];
    }

    /**
     * 尚未绑定 profile 的账号（新建时用）。
     *
     * @return Collection<int, GeoMonitorAccount>
     */
    private function unboundAccounts()
    {
        $boundIds = GeoMonitorBrowserProfile::query()->pluck('account_id');

        return GeoMonitorAccount::query()
            ->with('platform')
            ->when($boundIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $boundIds))
            ->orderBy('external_id')
            ->get();
    }

    /**
     * 绑定账号是否仍有排队或执行中的观测任务。
     *
     * @param  int  $accountId  账号主键
     */
    private function profileAccountHasActiveObservations(int $accountId): bool
    {
        return GeoMonitorObservation::query()
            ->where('account_id', $accountId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /**
     * Profile 是否仍有进行中的维护会话。
     *
     * @param  int  $profileId  Profile 主键
     */
    private function profileHasOpenMaintenance(int $profileId): bool
    {
        return GeoMonitorProfileMaintenanceEvent::query()
            ->where('browser_profile_id', $profileId)
            ->where('status', 'in_progress')
            ->exists();
    }
}
