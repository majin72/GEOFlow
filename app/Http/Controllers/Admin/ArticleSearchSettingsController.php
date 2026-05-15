<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\GeoFlow\ArticleSearch\ArticleSearchConfig;
use App\Support\AdminWeb;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 文章联网搜索后台配置控制器。
 *
 * 仅负责 article_search_* 系列 site_settings 键的读写，真正搜索由 Worker 侧 Tool 执行。
 */
class ArticleSearchSettingsController extends Controller
{
    /**
     * 展示文章联网搜索配置页。
     */
    public function index(): View
    {
        return view('admin.article-search.index', [
            'pageTitle' => __('admin.article_search.page_title'),
            'activeMenu' => 'site_settings',
            'adminSiteName' => AdminWeb::siteName(),
            'settings' => $this->loadSettings(),
            'defaults' => [
                'endpoint' => ArticleSearchConfig::DEFAULT_ENDPOINT,
                'timeout' => ArticleSearchConfig::DEFAULT_TIMEOUT,
                'max_results' => ArticleSearchConfig::DEFAULT_MAX_RESULTS,
                'search_depth' => ArticleSearchConfig::DEFAULT_SEARCH_DEPTH,
                'cache_ttl' => ArticleSearchConfig::DEFAULT_CACHE_TTL,
            ],
        ]);
    }

    /**
     * 保存文章联网搜索配置。
     */
    public function update(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'enabled' => ['nullable'],
            'endpoint' => ['nullable', 'string', 'max:512', 'url'],
            'api_key' => ['nullable', 'string', 'max:512'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:20'],
            'search_depth' => ['nullable', 'string', 'in:basic,advanced'],
            'include_domains' => ['nullable', 'string', 'max:2000'],
            'cache_ttl' => ['nullable', 'integer', 'min:0', 'max:604800'],
        ]);

        $values = [
            ArticleSearchConfig::KEY_ENABLED => ! empty($payload['enabled']) ? '1' : '0',
            ArticleSearchConfig::KEY_PROVIDER => ArticleSearchConfig::DEFAULT_PROVIDER,
            ArticleSearchConfig::KEY_ENDPOINT => trim((string) ($payload['endpoint'] ?? ArticleSearchConfig::DEFAULT_ENDPOINT)),
            ArticleSearchConfig::KEY_API_KEY => trim((string) ($payload['api_key'] ?? '')),
            ArticleSearchConfig::KEY_TIMEOUT => (string) ((int) ($payload['timeout'] ?? ArticleSearchConfig::DEFAULT_TIMEOUT) ?: ArticleSearchConfig::DEFAULT_TIMEOUT),
            ArticleSearchConfig::KEY_MAX_RESULTS => (string) ((int) ($payload['max_results'] ?? ArticleSearchConfig::DEFAULT_MAX_RESULTS) ?: ArticleSearchConfig::DEFAULT_MAX_RESULTS),
            ArticleSearchConfig::KEY_SEARCH_DEPTH => trim((string) ($payload['search_depth'] ?? ArticleSearchConfig::DEFAULT_SEARCH_DEPTH)) ?: ArticleSearchConfig::DEFAULT_SEARCH_DEPTH,
            ArticleSearchConfig::KEY_INCLUDE_DOMAINS => $this->normalizeCsv((string) ($payload['include_domains'] ?? '')),
            ArticleSearchConfig::KEY_CACHE_TTL => (string) ((int) ($payload['cache_ttl'] ?? ArticleSearchConfig::DEFAULT_CACHE_TTL)),
        ];

        foreach ($values as $settingKey => $settingValue) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $settingKey],
                ['setting_value' => $settingValue]
            );
        }

        SiteSettingsBag::forget();

        return redirect()
            ->route('admin.site-settings.article-search')
            ->with('message', __('admin.article_search.message.saved'));
    }

    /**
     * @return array{
     *     enabled: bool,
     *     endpoint: string,
     *     api_key: string,
     *     timeout: string,
     *     max_results: string,
     *     search_depth: string,
     *     include_domains: string,
     *     cache_ttl: string,
     * }
     */
    private function loadSettings(): array
    {
        $keys = [
            ArticleSearchConfig::KEY_ENABLED,
            ArticleSearchConfig::KEY_ENDPOINT,
            ArticleSearchConfig::KEY_API_KEY,
            ArticleSearchConfig::KEY_TIMEOUT,
            ArticleSearchConfig::KEY_MAX_RESULTS,
            ArticleSearchConfig::KEY_SEARCH_DEPTH,
            ArticleSearchConfig::KEY_INCLUDE_DOMAINS,
            ArticleSearchConfig::KEY_CACHE_TTL,
        ];

        $stored = SiteSetting::query()
            ->whereIn('setting_key', $keys)
            ->pluck('setting_value', 'setting_key')
            ->all();

        $rawEnabled = strtolower(trim((string) ($stored[ArticleSearchConfig::KEY_ENABLED] ?? '0')));

        return [
            'enabled' => in_array($rawEnabled, ['1', 'true', 'yes', 'on'], true),
            'endpoint' => (string) ($stored[ArticleSearchConfig::KEY_ENDPOINT] ?? ArticleSearchConfig::DEFAULT_ENDPOINT),
            'api_key' => (string) ($stored[ArticleSearchConfig::KEY_API_KEY] ?? ''),
            'timeout' => (string) ($stored[ArticleSearchConfig::KEY_TIMEOUT] ?? (string) ArticleSearchConfig::DEFAULT_TIMEOUT),
            'max_results' => (string) ($stored[ArticleSearchConfig::KEY_MAX_RESULTS] ?? (string) ArticleSearchConfig::DEFAULT_MAX_RESULTS),
            'search_depth' => (string) ($stored[ArticleSearchConfig::KEY_SEARCH_DEPTH] ?? ArticleSearchConfig::DEFAULT_SEARCH_DEPTH),
            'include_domains' => (string) ($stored[ArticleSearchConfig::KEY_INCLUDE_DOMAINS] ?? ''),
            'cache_ttl' => (string) ($stored[ArticleSearchConfig::KEY_CACHE_TTL] ?? (string) ArticleSearchConfig::DEFAULT_CACHE_TTL),
        ];
    }

    /**
     * 规范化逗号分隔值。
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
