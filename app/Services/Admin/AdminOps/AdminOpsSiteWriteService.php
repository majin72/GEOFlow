<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps;

use App\Http\Controllers\Admin\SiteSettingsController;
use App\Models\AiModel;
use App\Models\SiteSetting;
use App\Services\GeoFlow\ArticleSearch\ArticleSearchConfig;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchConfig;
use App\Support\AdminBasePathManager;
use App\Support\AdminWeb;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * AI 运维场景下对站点相关 site_settings 的写入封装，与后台「站点设置」等控制器校验规则对齐。
 */
final class AdminOpsSiteWriteService
{
    /**
     * 列出已安装主题 id（与 {@see SiteSettingsController} 发现逻辑一致）。
     *
     * @return array<int, array{id: string, name: string, version: string, description: string}>
     */
    public function listInstalledThemes(): array
    {
        $themesRoot = resource_path('views/theme');
        if (! is_dir($themesRoot)) {
            return [];
        }

        $themes = [];
        $entries = scandir($themesRoot);
        if (! is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! preg_match('/^[a-zA-Z0-9_-]+$/', $entry)) {
                continue;
            }

            $themeDir = $themesRoot.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($themeDir)) {
                continue;
            }

            $manifestPath = $themeDir.DIRECTORY_SEPARATOR.'manifest.json';
            if (is_file($manifestPath)) {
                $manifestRaw = file_get_contents($manifestPath);
                if (! is_string($manifestRaw) || $manifestRaw === '') {
                    continue;
                }

                $manifest = json_decode($manifestRaw, true);
                if (! is_array($manifest)) {
                    continue;
                }

                $themes[] = [
                    'id' => (string) $entry,
                    'name' => (string) ($manifest['name'] ?? $entry),
                    'version' => (string) ($manifest['version'] ?? ''),
                    'description' => (string) ($manifest['description'] ?? ''),
                ];

                continue;
            }

            if (! is_file($themeDir.DIRECTORY_SEPARATOR.'home.blade.php')) {
                continue;
            }

            $themes[] = [
                'id' => (string) $entry,
                'name' => ucfirst(str_replace(['-', '_'], ' ', $entry)),
                'version' => '',
                'description' => '',
            ];
        }

        return $themes;
    }

    /**
     * 设置启用主题（空字符串表示使用系统默认主题）。
     *
     * @return array{ok: bool, error?: string}
     */
    public function setActiveTheme(string $themeId): array
    {
        $selectedTheme = trim($themeId);
        $availableThemeIds = array_map(
            static fn (array $theme): string => (string) $theme['id'],
            $this->listInstalledThemes()
        );

        if ($selectedTheme !== '' && ! in_array($selectedTheme, $availableThemeIds, true)) {
            return ['ok' => false, 'error' => '无效的主题 id：'.$selectedTheme.'。请先调用 AdminOpsListThemesTool 查看可用主题。'];
        }

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => $selectedTheme]
        );
        SiteSettingsBag::forget();

        return ['ok' => true];
    }

    /**
     * 合并并保存「站点基础设置」中与后台表单一致的字段（部分字段可省略，省略则保持原值）。
     *
     * @param  array<string, mixed>  $patch
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>, updated_keys?: array<int, string>}
     */
    public function patchBasics(array $patch): array
    {
        $current = $this->loadBasicsForMerge();
        $merged = $current;

        $allowedScalar = [
            'site_name', 'site_subtitle', 'site_description', 'site_keywords', 'copyright_info',
            'site_icp_beian', 'site_police_beian', 'site_police_beian_code',
            'site_logo', 'site_favicon', 'analytics_code',
            'seo_title_template', 'seo_description_template', 'admin_base_path',
        ];

        foreach ($allowedScalar as $key) {
            if (array_key_exists($key, $patch)) {
                $merged[$key] = is_string($patch[$key]) ? trim($patch[$key]) : (string) $patch[$key];
            }
        }

        if (array_key_exists('featured_limit', $patch)) {
            $merged['featured_limit'] = (string) (int) $patch['featured_limit'];
        }
        if (array_key_exists('per_page', $patch)) {
            $merged['per_page'] = (string) (int) $patch['per_page'];
        }

        if (array_key_exists('home_carousel_slides', $patch)) {
            $slidesInput = $patch['home_carousel_slides'];
            if (is_string($slidesInput)) {
                $decoded = json_decode($slidesInput, true);
                $slidesInput = is_array($decoded) ? $decoded : [];
            }
            $normalizedSlides = $this->normalizeHomeCarouselSlides(is_array($slidesInput) ? $slidesInput : []);
            $merged['home_carousel_slides'] = (string) json_encode($normalizedSlides, JSON_UNESCAPED_UNICODE);
        }

        $validator = Validator::make(
            [
                'site_name' => $merged['site_name'],
                'site_subtitle' => $merged['site_subtitle'],
                'site_description' => $merged['site_description'],
                'site_keywords' => $merged['site_keywords'],
                'copyright_info' => $merged['copyright_info'],
                'site_icp_beian' => $merged['site_icp_beian'],
                'site_police_beian' => $merged['site_police_beian'],
                'site_police_beian_code' => $merged['site_police_beian_code'],
                'site_logo' => $merged['site_logo'],
                'site_favicon' => $merged['site_favicon'],
                'analytics_code' => $merged['analytics_code'],
                'seo_title_template' => $merged['seo_title_template'],
                'seo_description_template' => $merged['seo_description_template'],
                'featured_limit' => (int) $merged['featured_limit'],
                'per_page' => (int) $merged['per_page'],
                'home_carousel_slides' => json_decode((string) $merged['home_carousel_slides'], true) ?: [],
                'admin_base_path' => $merged['admin_base_path'],
            ],
            [
                'site_name' => ['required', 'string', 'max:120'],
                'site_subtitle' => ['nullable', 'string', 'max:255'],
                'site_description' => ['nullable', 'string'],
                'site_keywords' => ['nullable', 'string', 'max:500'],
                'copyright_info' => ['nullable', 'string', 'max:500'],
                'site_icp_beian' => ['nullable', 'string', 'max:120'],
                'site_police_beian' => ['nullable', 'string', 'max:120'],
                'site_police_beian_code' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]*$/'],
                'site_logo' => ['nullable', 'url', 'max:500'],
                'site_favicon' => ['nullable', 'url', 'max:500'],
                'analytics_code' => ['nullable', 'string'],
                'seo_title_template' => ['nullable', 'string', 'max:255'],
                'seo_description_template' => ['nullable', 'string', 'max:255'],
                'featured_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
                'home_carousel_slides' => ['nullable', 'array', 'max:3'],
                'home_carousel_slides.*.image_url' => ['nullable', 'string', 'max:500'],
                'home_carousel_slides.*.title' => ['nullable', 'string', 'max:120'],
                'home_carousel_slides.*.link_url' => ['nullable', 'string', 'max:500'],
                'home_carousel_slides.*.enabled' => ['nullable'],
                'admin_base_path' => [
                    'required',
                    'string',
                    'min:3',
                    'max:48',
                    'regex:/^[a-z0-9][a-z0-9_-]*[a-z0-9]$/',
                    Rule::notIn(AdminBasePathManager::reservedSegments()),
                ],
            ],
        );

        if ($validator->fails()) {
            return [
                'ok' => false,
                'error' => '校验未通过。',
                'validation_errors' => $validator->errors()->toArray(),
            ];
        }

        $validated = $validator->validated();

        try {
            $newAdminBasePath = AdminBasePathManager::normalize((string) $validated['admin_base_path']);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'admin_base_path 非法，无法规范化。'];
        }

        $currentAdminBasePath = AdminWeb::basePath();

        $settings = [
            'site_name' => trim((string) $validated['site_name']),
            'site_title' => trim((string) $validated['site_name']),
            'site_subtitle' => trim((string) ($validated['site_subtitle'] ?? '')),
            'site_description' => trim((string) ($validated['site_description'] ?? '')),
            'site_keywords' => trim((string) ($validated['site_keywords'] ?? '')),
            'copyright_info' => trim((string) ($validated['copyright_info'] ?? '')),
            'site_icp_beian' => trim((string) ($validated['site_icp_beian'] ?? '')),
            'site_police_beian' => trim((string) ($validated['site_police_beian'] ?? '')),
            'site_police_beian_code' => trim((string) ($validated['site_police_beian_code'] ?? '')),
            'site_logo' => trim((string) ($validated['site_logo'] ?? '')),
            'site_favicon' => trim((string) ($validated['site_favicon'] ?? '')),
            'analytics_code' => trim((string) ($validated['analytics_code'] ?? '')),
            'seo_title_template' => trim((string) ($validated['seo_title_template'] ?? '')),
            'seo_description_template' => trim((string) ($validated['seo_description_template'] ?? '')),
            'featured_limit' => (string) ((int) ($validated['featured_limit'] ?? 6)),
            'per_page' => (string) ((int) ($validated['per_page'] ?? 12)),
            'home_carousel_slides' => (string) json_encode($this->normalizeHomeCarouselSlides($validated['home_carousel_slides'] ?? []), JSON_UNESCAPED_UNICODE),
            'admin_base_path' => $newAdminBasePath,
        ];

        foreach ($settings as $settingKey => $settingValue) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $settingKey],
                ['setting_value' => $settingValue]
            );
        }

        SiteSettingsBag::forget();

        if ($newAdminBasePath !== $currentAdminBasePath) {
            try {
                AdminBasePathManager::persist($newAdminBasePath);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => '保存后台路径失败：'.$e->getMessage()];
            }
        }

        return ['ok' => true, 'updated_keys' => array_keys($settings)];
    }

    /**
     * 覆盖保存文章详情页广告位 JSON（与后台校验一致）。
     *
     * @param  array<int, array<string, mixed>>  $postedAds
     * @return array{ok: bool, error?: string}
     */
    public function setArticleDetailAds(array $postedAds): array
    {
        $ads = [];
        foreach ($postedAds as $index => $postedAd) {
            if (! is_array($postedAd)) {
                continue;
            }

            $name = trim((string) ($postedAd['name'] ?? ''));
            $badge = trim((string) ($postedAd['badge'] ?? ''));
            $title = trim((string) ($postedAd['title'] ?? ''));
            $copy = trim((string) ($postedAd['copy'] ?? ''));
            $buttonText = trim((string) ($postedAd['button_text'] ?? ''));
            $buttonUrl = $this->normalizeCtaTargetUrl((string) ($postedAd['button_url'] ?? ''));
            $enabled = ! empty($postedAd['enabled']);
            $id = trim((string) ($postedAd['id'] ?? ''));

            if ($name === '' && $badge === '' && $title === '' && $copy === '' && $buttonText === '' && $buttonUrl === '') {
                continue;
            }

            if ($copy === '' || $buttonText === '' || $buttonUrl === '') {
                return ['ok' => false, 'error' => '广告位第 '.((int) $index + 1).' 条：文案、按钮文字与链接为必填。'];
            }

            $ads[] = [
                'id' => $id !== '' ? $id : uniqid('article_ad_', true),
                'name' => $name !== '' ? $name : '广告 '.(count($ads) + 1),
                'badge' => $badge,
                'title' => $title,
                'copy' => $copy,
                'button_text' => $buttonText,
                'button_url' => $buttonUrl,
                'enabled' => $enabled,
            ];
        }

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'article_detail_ads'],
            ['setting_value' => (string) json_encode($ads, JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        return ['ok' => true];
    }

    /**
     * 合并并保存文章联网搜索相关 site_settings。
     *
     * @param  array<string, mixed>  $patch
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function patchArticleSearch(array $patch): array
    {
        $current = $this->loadArticleSearchFlat();

        if (array_key_exists('enabled', $patch)) {
            $v = $patch['enabled'];
            $current['enabled'] = filter_var($v, FILTER_VALIDATE_BOOLEAN)
                || $v === '1'
                || $v === 1
                || $v === true;
        }
        foreach (['timeout', 'max_results', 'cache_ttl'] as $intKey) {
            if (array_key_exists($intKey, $patch)) {
                $current[$intKey] = (int) $patch[$intKey];
            }
        }
        foreach (['endpoint', 'api_key', 'search_depth', 'include_domains'] as $strKey) {
            if (array_key_exists($strKey, $patch)) {
                $current[$strKey] = trim((string) $patch[$strKey]);
            }
        }

        $validator = Validator::make(
            [
                'enabled' => $current['enabled'],
                'endpoint' => $current['endpoint'],
                'api_key' => $current['api_key'],
                'timeout' => $current['timeout'],
                'max_results' => $current['max_results'],
                'search_depth' => $current['search_depth'],
                'include_domains' => $current['include_domains'],
                'cache_ttl' => $current['cache_ttl'],
            ],
            [
                'enabled' => ['nullable'],
                'endpoint' => ['nullable', 'string', 'max:512'],
                'api_key' => ['nullable', 'string', 'max:512'],
                'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
                'max_results' => ['nullable', 'integer', 'min:1', 'max:20'],
                'search_depth' => ['nullable', 'string', 'in:basic,advanced'],
                'include_domains' => ['nullable', 'string', 'max:2000'],
                'cache_ttl' => ['nullable', 'integer', 'min:0', 'max:604800'],
            ],
        );

        if ($validator->fails()) {
            return [
                'ok' => false,
                'error' => '文章联网搜索配置校验未通过。',
                'validation_errors' => $validator->errors()->toArray(),
            ];
        }

        $p = $validator->validated();

        $values = [
            ArticleSearchConfig::KEY_ENABLED => ! empty($p['enabled']) ? '1' : '0',
            ArticleSearchConfig::KEY_PROVIDER => ArticleSearchConfig::DEFAULT_PROVIDER,
            ArticleSearchConfig::KEY_ENDPOINT => trim((string) ($p['endpoint'] ?? ArticleSearchConfig::DEFAULT_ENDPOINT)) ?: ArticleSearchConfig::DEFAULT_ENDPOINT,
            ArticleSearchConfig::KEY_API_KEY => trim((string) ($p['api_key'] ?? '')),
            ArticleSearchConfig::KEY_TIMEOUT => (string) ((int) ($p['timeout'] ?? ArticleSearchConfig::DEFAULT_TIMEOUT) ?: ArticleSearchConfig::DEFAULT_TIMEOUT),
            ArticleSearchConfig::KEY_MAX_RESULTS => (string) ((int) ($p['max_results'] ?? ArticleSearchConfig::DEFAULT_MAX_RESULTS) ?: ArticleSearchConfig::DEFAULT_MAX_RESULTS),
            ArticleSearchConfig::KEY_SEARCH_DEPTH => trim((string) ($p['search_depth'] ?? ArticleSearchConfig::DEFAULT_SEARCH_DEPTH)) ?: ArticleSearchConfig::DEFAULT_SEARCH_DEPTH,
            ArticleSearchConfig::KEY_INCLUDE_DOMAINS => $this->normalizeCsv((string) ($p['include_domains'] ?? '')),
            ArticleSearchConfig::KEY_CACHE_TTL => (string) ((int) ($p['cache_ttl'] ?? ArticleSearchConfig::DEFAULT_CACHE_TTL)),
        ];

        foreach ($values as $settingKey => $settingValue) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $settingKey],
                ['setting_value' => $settingValue]
            );
        }

        SiteSettingsBag::forget();

        return ['ok' => true];
    }

    /**
     * 供 AI 运维只读汇总：联网搜索、外部抓取与默认 embedding（密钥脱敏）。
     *
     * @return array{
     *     article_search: array<string, mixed>,
     *     external_fetch: array<string, mixed>,
     *     embedding: array<string, mixed>
     * }
     */
    public function buildIntegrationsSnapshot(): array
    {
        $article = $this->loadArticleSearchFlat();
        $article['api_key'] = $this->maskSecretForDisplay((string) ($article['api_key'] ?? ''));
        $providerRaw = SiteSetting::query()
            ->where('setting_key', ArticleSearchConfig::KEY_PROVIDER)
            ->value('setting_value');
        $article['provider'] = trim((string) ($providerRaw)) !== ''
            ? trim((string) $providerRaw)
            : ArticleSearchConfig::DEFAULT_PROVIDER;

        $external = $this->loadExternalFetchFlat();
        $external['token'] = $this->maskSecretForDisplay((string) ($external['token'] ?? ''));

        $embeddingId = max(0, (int) (SiteSetting::query()
            ->where('setting_key', 'default_embedding_model_id')
            ->value('setting_value') ?? 0));

        $embedding = [
            'default_embedding_model_id' => $embeddingId,
            'resolved_name' => null,
            'resolved_status' => null,
            'resolved_model_type' => null,
        ];

        if ($embeddingId > 0) {
            $row = AiModel::query()
                ->whereKey($embeddingId)
                ->first(['name', 'status', 'model_type']);
            if ($row !== null) {
                $embedding['resolved_name'] = (string) ($row->name ?? '');
                $embedding['resolved_status'] = (string) ($row->status ?? '');
                $embedding['resolved_model_type'] = (string) ($row->model_type ?? '');
            }
        }

        return [
            'article_search' => $article,
            'external_fetch' => $external,
            'embedding' => $embedding,
        ];
    }

    /**
     * 设置站点默认 embedding 模型（与后台 AI 模型页逻辑一致：仅允许 active + embedding；0 表示清除）。
     *
     * @return array{ok: bool, error?: string, default_embedding_model_id?: int}
     */
    public function setDefaultEmbeddingModelId(int $modelId): array
    {
        $id = max(0, $modelId);

        if ($id > 0) {
            $ok = AiModel::query()
                ->whereKey($id)
                ->where('status', 'active')
                ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'")
                ->exists();

            if (! $ok) {
                return [
                    'ok' => false,
                    'error' => '无效的默认 embedding 模型：需存在、状态为 active 且 model_type 为 embedding。',
                ];
            }
        }

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'default_embedding_model_id'],
            ['setting_value' => (string) $id]
        );
        SiteSettingsBag::forget();

        return ['ok' => true, 'default_embedding_model_id' => $id];
    }

    /**
     * 合并并保存外部浏览器抓取相关 site_settings。
     *
     * @param  array<string, mixed>  $patch
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function patchExternalFetch(array $patch): array
    {
        $current = $this->loadExternalFetchFlat();

        if (array_key_exists('enabled', $patch)) {
            $v = $patch['enabled'];
            $current['enabled'] = filter_var($v, FILTER_VALIDATE_BOOLEAN)
                || $v === '1'
                || $v === 1
                || $v === true;
        }
        if (array_key_exists('timeout', $patch)) {
            $current['timeout'] = (int) $patch['timeout'];
        }
        foreach (['endpoint', 'token', 'domains', 'retry_on_status'] as $strKey) {
            if (array_key_exists($strKey, $patch)) {
                $current[$strKey] = trim((string) $patch[$strKey]);
            }
        }

        $validator = Validator::make(
            $current,
            [
                'enabled' => ['nullable'],
                'endpoint' => ['nullable', 'string', 'max:512'],
                'token' => ['nullable', 'string', 'max:512'],
                'timeout' => ['nullable', 'integer', 'min:1', 'max:600'],
                'domains' => ['nullable', 'string', 'max:2000'],
                'retry_on_status' => ['nullable', 'string', 'max:200'],
            ],
        );

        if ($validator->fails()) {
            return [
                'ok' => false,
                'error' => '外部抓取配置校验未通过。',
                'validation_errors' => $validator->errors()->toArray(),
            ];
        }

        $p = $validator->validated();

        $values = [
            ExternalFetchConfig::KEY_ENABLED => ! empty($p['enabled']) ? '1' : '0',
            ExternalFetchConfig::KEY_ENDPOINT => trim((string) ($p['endpoint'] ?? '')),
            ExternalFetchConfig::KEY_TOKEN => trim((string) ($p['token'] ?? '')),
            ExternalFetchConfig::KEY_TIMEOUT => (string) (
                isset($p['timeout']) && (int) $p['timeout'] > 0
                    ? (int) $p['timeout']
                    : ExternalFetchConfig::DEFAULT_TIMEOUT
            ),
            ExternalFetchConfig::KEY_DOMAINS => $this->normalizeCsv((string) ($p['domains'] ?? '')),
            ExternalFetchConfig::KEY_RETRY_ON_STATUS => $this->normalizeCsv((string) ($p['retry_on_status'] ?? '')),
        ];

        foreach ($values as $settingKey => $settingValue) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $settingKey],
                ['setting_value' => $settingValue]
            );
        }

        SiteSettingsBag::forget();

        return ['ok' => true];
    }

    /**
     * @return array<string, string>
     */
    private function loadBasicsForMerge(): array
    {
        $defaults = [
            'site_name' => 'GEOFlow',
            'site_subtitle' => '',
            'site_description' => '',
            'site_keywords' => '',
            'copyright_info' => '',
            'site_icp_beian' => '',
            'site_police_beian' => '',
            'site_police_beian_code' => '',
            'site_logo' => '',
            'site_favicon' => '',
            'analytics_code' => '',
            'seo_title_template' => '{title} - {site_name}',
            'seo_description_template' => '{description}',
            'featured_limit' => '6',
            'per_page' => '12',
            'admin_base_path' => AdminWeb::basePath(),
            'home_carousel_slides' => '[]',
        ];

        $stored = SiteSetting::query()
            ->select(['setting_key', 'setting_value'])
            ->whereIn('setting_key', array_keys($defaults))
            ->get()
            ->pluck('setting_value', 'setting_key')
            ->all();

        foreach ($defaults as $key => $defaultValue) {
            if (! array_key_exists($key, $stored)) {
                $stored[$key] = $defaultValue;
            }
        }

        $stored['admin_base_path'] = AdminWeb::basePath();

        return array_map(static fn ($v): string => (string) $v, $stored);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadArticleSearchFlat(): array
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
            'timeout' => (int) ($stored[ArticleSearchConfig::KEY_TIMEOUT] ?? ArticleSearchConfig::DEFAULT_TIMEOUT),
            'max_results' => (int) ($stored[ArticleSearchConfig::KEY_MAX_RESULTS] ?? ArticleSearchConfig::DEFAULT_MAX_RESULTS),
            'search_depth' => (string) ($stored[ArticleSearchConfig::KEY_SEARCH_DEPTH] ?? ArticleSearchConfig::DEFAULT_SEARCH_DEPTH),
            'include_domains' => (string) ($stored[ArticleSearchConfig::KEY_INCLUDE_DOMAINS] ?? ''),
            'cache_ttl' => (int) ($stored[ArticleSearchConfig::KEY_CACHE_TTL] ?? ArticleSearchConfig::DEFAULT_CACHE_TTL),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadExternalFetchFlat(): array
    {
        $stored = SiteSetting::query()
            ->whereIn('setting_key', [
                ExternalFetchConfig::KEY_ENABLED,
                ExternalFetchConfig::KEY_ENDPOINT,
                ExternalFetchConfig::KEY_TOKEN,
                ExternalFetchConfig::KEY_TIMEOUT,
                ExternalFetchConfig::KEY_DOMAINS,
                ExternalFetchConfig::KEY_RETRY_ON_STATUS,
            ])
            ->pluck('setting_value', 'setting_key')
            ->all();

        $rawEnabled = strtolower(trim((string) ($stored[ExternalFetchConfig::KEY_ENABLED] ?? '0')));

        return [
            'enabled' => in_array($rawEnabled, ['1', 'true', 'yes', 'on'], true),
            'endpoint' => trim((string) ($stored[ExternalFetchConfig::KEY_ENDPOINT] ?? '')),
            'token' => trim((string) ($stored[ExternalFetchConfig::KEY_TOKEN] ?? '')),
            'timeout' => (int) ($stored[ExternalFetchConfig::KEY_TIMEOUT] ?? ExternalFetchConfig::DEFAULT_TIMEOUT),
            'domains' => (string) ($stored[ExternalFetchConfig::KEY_DOMAINS] ?? ExternalFetchConfig::DEFAULT_DOMAINS),
            'retry_on_status' => (string) ($stored[ExternalFetchConfig::KEY_RETRY_ON_STATUS] ?? ExternalFetchConfig::DEFAULT_RETRY_ON_STATUS),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $postedSlides
     * @return array<int, array{image_url: string, title: string, link_url: string, enabled: bool}>
     */
    private function normalizeHomeCarouselSlides(array $postedSlides): array
    {
        $slides = [];
        foreach ($postedSlides as $postedSlide) {
            if (! is_array($postedSlide)) {
                continue;
            }

            $imageUrl = $this->normalizePublicImageUrl((string) ($postedSlide['image_url'] ?? ''));
            $title = trim((string) ($postedSlide['title'] ?? ''));
            $linkUrl = $this->normalizeCtaTargetUrl((string) ($postedSlide['link_url'] ?? ''));
            $enabled = ! empty($postedSlide['enabled']);

            if ($imageUrl === '' && $title === '' && $linkUrl === '') {
                continue;
            }

            if ($imageUrl === '') {
                continue;
            }

            $slides[] = [
                'image_url' => $imageUrl,
                'title' => $title,
                'link_url' => $linkUrl,
                'enabled' => $enabled,
            ];

            if (count($slides) >= 3) {
                break;
            }
        }

        return $slides;
    }

    private function normalizePublicImageUrl(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, '/') && ! str_starts_with($normalized, '//')) {
            return $normalized;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        return '';
    }

    private function normalizeCtaTargetUrl(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        return '/'.ltrim($normalized, '/');
    }

    private function normalizeCsv(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));

        return implode(',', $parts);
    }

    /**
     * 将敏感串脱敏为可给模型阅读的短描述（不回传原文）。
     */
    private function maskSecretForDisplay(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        $len = strlen($secret);
        if ($len <= 6) {
            return '[已配置，长度 '.$len.' 字符]';
        }

        return substr($secret, 0, 3).'…'.substr($secret, -3).'（长度 '.$len.'）';
    }
}
