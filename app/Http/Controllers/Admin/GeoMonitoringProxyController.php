<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoMonitorProxyEndpoint;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GEO 监测代理出口后台 CRUD。
 */
class GeoMonitoringProxyController extends Controller
{
    /**
     * 代理列表。
     */
    public function index(): View
    {
        $proxies = GeoMonitorProxyEndpoint::query()
            ->withCount('accounts')
            ->orderBy('label')
            ->get();

        return view('admin.geo-monitoring.proxies.index', [
            'pageTitle' => __('admin.geo_monitoring.proxies_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'proxies' => $proxies,
        ]);
    }

    /**
     * 新建代理表单。
     */
    public function create(): View
    {
        return view('admin.geo-monitoring.proxies.form', [
            'pageTitle' => __('admin.geo_monitoring.proxy_create_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'proxyId' => 0,
            'form' => $this->emptyProxyForm(),
        ]);
    }

    /**
     * 保存代理。
     */
    public function store(Request $request): RedirectResponse
    {
        GeoMonitorProxyEndpoint::query()->create($this->validatedProxyPayload($request));

        return redirect()
            ->route('admin.geo-monitoring.proxies.index')
            ->with('message', __('admin.geo_monitoring.message.proxy_created'));
    }

    /**
     * 编辑代理表单。
     */
    public function edit(int $proxyId): View|RedirectResponse
    {
        $proxy = GeoMonitorProxyEndpoint::query()->find($proxyId);

        if ($proxy === null) {
            return redirect()
                ->route('admin.geo-monitoring.proxies.index')
                ->withErrors(__('admin.geo_monitoring.message.proxy_not_found'));
        }

        return view('admin.geo-monitoring.proxies.form', [
            'pageTitle' => __('admin.geo_monitoring.proxy_edit_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'proxyId' => $proxy->id,
            'form' => $this->proxyFormFromModel($proxy),
        ]);
    }

    /**
     * 更新代理。
     */
    public function update(Request $request, int $proxyId): RedirectResponse
    {
        $proxy = GeoMonitorProxyEndpoint::query()->find($proxyId);

        if ($proxy === null) {
            return redirect()
                ->route('admin.geo-monitoring.proxies.index')
                ->withErrors(__('admin.geo_monitoring.message.proxy_not_found'));
        }

        $proxy->update($this->validatedProxyPayload($request));

        return redirect()
            ->route('admin.geo-monitoring.proxies.index')
            ->with('message', __('admin.geo_monitoring.message.proxy_updated'));
    }

    /**
     * 切换代理启停。
     */
    public function toggle(int $proxyId): RedirectResponse
    {
        $proxy = GeoMonitorProxyEndpoint::query()->find($proxyId);

        if ($proxy === null) {
            return redirect()
                ->route('admin.geo-monitoring.proxies.index')
                ->withErrors(__('admin.geo_monitoring.message.proxy_not_found'));
        }

        $proxy->update([
            'status' => $proxy->status === 'active' ? 'disabled' : 'active',
        ]);

        return redirect()
            ->route('admin.geo-monitoring.proxies.index')
            ->with('message', __('admin.geo_monitoring.message.proxy_toggled'));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProxyForm(): array
    {
        return [
            'label' => '',
            'proxy_type' => 'http',
            'host' => '',
            'port' => '',
            'region' => '',
            'egress_ip_summary' => '',
            'status' => 'active',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proxyFormFromModel(GeoMonitorProxyEndpoint $proxy): array
    {
        return [
            'label' => $proxy->label,
            'proxy_type' => $proxy->proxy_type,
            'host' => $proxy->host,
            'port' => $proxy->port,
            'region' => (string) ($proxy->region ?? ''),
            'egress_ip_summary' => (string) ($proxy->egress_ip_summary ?? ''),
            'status' => $proxy->status,
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     proxy_type: string,
     *     host: string,
     *     port: int,
     *     region: string|null,
     *     egress_ip_summary: string|null,
     *     status: string
     * }
     */
    private function validatedProxyPayload(Request $request): array
    {
        $payload = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'proxy_type' => ['required', 'string', 'max:32'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'region' => ['nullable', 'string', 'max:64'],
            'egress_ip_summary' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'in:active,disabled,cooldown'],
        ], [
            'label.required' => __('admin.geo_monitoring.error.proxy_label_required'),
            'host.required' => __('admin.geo_monitoring.error.proxy_host_required'),
        ]);

        return [
            'label' => trim((string) $payload['label']),
            'proxy_type' => trim((string) $payload['proxy_type']),
            'host' => trim((string) $payload['host']),
            'port' => (int) $payload['port'],
            'region' => ($region = trim((string) ($payload['region'] ?? ''))) !== '' ? $region : null,
            'egress_ip_summary' => ($summary = trim((string) ($payload['egress_ip_summary'] ?? ''))) !== '' ? $summary : null,
            'status' => (string) $payload['status'],
        ];
    }
}
