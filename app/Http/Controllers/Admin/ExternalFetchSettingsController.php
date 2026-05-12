<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchConfig;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchService;
use App\Support\AdminWeb;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 外部浏览器抓取（External Fetch）后台配置控制器。
 *
 * 仅负责 6 个 site_settings 键的读写（开关 / 端点 / token / 超时 / 域名白名单 / 回退状态码），
 * 不引入额外路由结构，所有键定义在 {@see ExternalFetchConfig} 中集中维护，保持与队列侧配置一致。
 *
 * 写入流程：
 *   1. 表单 → validate
 *   2. 字符串规范化（trim / CSV 去空 / 布尔 → '1'|'0'）
 *   3. updateOrCreate 到 site_settings 表
 *   4. SiteSettingsBag::forget() 清除本进程缓存，使下一次 ExternalFetchConfig::fromSettings() 读到最新值
 */
class ExternalFetchSettingsController extends Controller
{
    /**
     * 展示外部抓取配置页面。
     */
    public function index(): View
    {
        return view('admin.external-fetch.index', [
            'pageTitle' => __('admin.external_fetch.page_title'),
            'activeMenu' => 'site_settings',
            'adminSiteName' => AdminWeb::siteName(),
            'settings' => $this->loadSettings(),
            'defaults' => [
                'domains' => ExternalFetchConfig::DEFAULT_DOMAINS,
                'retry_on_status' => ExternalFetchConfig::DEFAULT_RETRY_ON_STATUS,
                'timeout' => ExternalFetchConfig::DEFAULT_TIMEOUT,
            ],
        ]);
    }

    /**
     * 保存外部抓取配置。
     *
     * 注意：endpoint / token 允许留空（关闭场景下可不填）；保存后端的实际生效与否由
     * {@see ExternalFetchService::isEnabled()} 决定。
     */
    public function update(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'enabled' => ['nullable'],
            'endpoint' => ['nullable', 'string', 'max:512', 'url'],
            'token' => ['nullable', 'string', 'max:512'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:600'],
            'domains' => ['nullable', 'string', 'max:2000'],
            'retry_on_status' => ['nullable', 'string', 'max:200'],
        ]);

        $values = [
            ExternalFetchConfig::KEY_ENABLED => ! empty($payload['enabled']) ? '1' : '0',
            ExternalFetchConfig::KEY_ENDPOINT => trim((string) ($payload['endpoint'] ?? '')),
            ExternalFetchConfig::KEY_TOKEN => trim((string) ($payload['token'] ?? '')),
            ExternalFetchConfig::KEY_TIMEOUT => (string) (
                isset($payload['timeout']) && (int) $payload['timeout'] > 0
                    ? (int) $payload['timeout']
                    : ExternalFetchConfig::DEFAULT_TIMEOUT
            ),
            ExternalFetchConfig::KEY_DOMAINS => $this->normalizeCsv((string) ($payload['domains'] ?? '')),
            ExternalFetchConfig::KEY_RETRY_ON_STATUS => $this->normalizeCsv((string) ($payload['retry_on_status'] ?? '')),
        ];

        foreach ($values as $settingKey => $settingValue) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $settingKey],
                ['setting_value' => $settingValue]
            );
        }

        SiteSettingsBag::forget();

        return redirect()
            ->route('admin.site-settings.external-fetch')
            ->with('message', __('admin.external_fetch.message.saved'));
    }

    /**
     * 从 site_settings 读取当前配置；缺失键回落到 ExternalFetchConfig 的内置默认值。
     *
     * 返回纯字符串/布尔，方便 Blade 模板直接绑定到 input。
     *
     * @return array{
     *     enabled: bool,
     *     endpoint: string,
     *     token: string,
     *     timeout: string,
     *     domains: string,
     *     retry_on_status: string,
     * }
     */
    private function loadSettings(): array
    {
        $keys = [
            ExternalFetchConfig::KEY_ENABLED,
            ExternalFetchConfig::KEY_ENDPOINT,
            ExternalFetchConfig::KEY_TOKEN,
            ExternalFetchConfig::KEY_TIMEOUT,
            ExternalFetchConfig::KEY_DOMAINS,
            ExternalFetchConfig::KEY_RETRY_ON_STATUS,
        ];

        $stored = SiteSetting::query()
            ->whereIn('setting_key', $keys)
            ->pluck('setting_value', 'setting_key')
            ->all();

        $rawEnabled = strtolower(trim((string) ($stored[ExternalFetchConfig::KEY_ENABLED] ?? '0')));

        return [
            'enabled' => in_array($rawEnabled, ['1', 'true', 'yes', 'on'], true),
            'endpoint' => (string) ($stored[ExternalFetchConfig::KEY_ENDPOINT] ?? ''),
            'token' => (string) ($stored[ExternalFetchConfig::KEY_TOKEN] ?? ''),
            'timeout' => (string) ($stored[ExternalFetchConfig::KEY_TIMEOUT] ?? (string) ExternalFetchConfig::DEFAULT_TIMEOUT),
            'domains' => (string) ($stored[ExternalFetchConfig::KEY_DOMAINS] ?? ExternalFetchConfig::DEFAULT_DOMAINS),
            'retry_on_status' => (string) ($stored[ExternalFetchConfig::KEY_RETRY_ON_STATUS] ?? ExternalFetchConfig::DEFAULT_RETRY_ON_STATUS),
        ];
    }

    /**
     * 规范化逗号分隔字符串：trim 每一项、剔除空白项、保持出现顺序、用逗号无空格拼接。
     *
     * 不在此处做语义校验（如端口范围、域名格式），把容错留给 {@see ExternalFetchConfig}
     * 解析层；admin 可以自由填写新增域名/状态码而不被 UI 阻塞。
     */
    private function normalizeCsv(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));

        return implode(',', $parts);
    }
}
