<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\SiteSetting;
use App\Services\Admin\AdminOps\AdminOpsSiteWriteService;
use App\Support\AdminWeb;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * AI 运维只读工具：从 site_settings 汇总站点基础信息（名称、标题、SEO、分页、主题等），并附带 integrations 汇总（联网搜索、外部抓取、默认 embedding，敏感字段脱敏），不写入数据库。
 */
final class AdminOpsSiteInfoTool implements Tool
{
    public function __construct(
        private readonly AdminOpsSiteWriteService $siteWrite,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '读取当前 GEOFlow 站点键值设置（site_settings）：站点名称、站点标题(site_title)、副标题、描述与关键词、版权与备案、Logo/Favicon、SEO 模板、首页精选条数、列表分页、后台路径前缀、启用主题、轮播与文章内广告 JSON；并附带 integrations（联网搜索、外部抓取、默认 embedding 模型，密钥脱敏）。仅查询不写库；若用户问「站点叫什么」「每页几条」「用什么主题」等，应先调用本工具再回答。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        try {
            $scope = strtolower(trim((string) Arr::get($request->toArray(), 'scope', 'summary')));
            $includeHeavy = in_array($scope, ['full', 'all', 'verbose'], true);

            return json_encode($this->buildPayload($includeHeavy), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        } catch (Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => '读取站点设置失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()
                ->description('可选：summary（默认，长字段截断）或 full（轮播/广告 JSON 与统计代码给出更长截断预览）。可不传。'),
        ];
    }

    /**
     * 与后台「站点设置」页对齐的默认值，并与数据库中的键合并。
     *
     * @return array<string, string>
     */
    private function defaults(): array
    {
        return [
            'site_name' => 'GEOFlow',
            'site_title' => '',
            'site_subtitle' => '',
            'site_description' => '基于AI的智能内容生成与发布平台',
            'site_keywords' => 'AI内容生成,GEO优化,智能发布,内容管理',
            'copyright_info' => '© 2026 GEOFlow. All rights reserved.',
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
            'active_theme' => (string) config('geoflow.default_theme', ''),
            'home_carousel_slides' => '[]',
            'article_detail_ads' => '[]',
        ];
    }

    /**
     * 构造返回给模型的 JSON 负载（已按 scope 做截断与元信息）。
     *
     * @return array<string, mixed>
     */
    private function buildPayload(bool $includeHeavy): array
    {
        if (! Schema::hasTable('site_settings')) {
            return [
                'ok' => false,
                'error' => '数据库中不存在 site_settings 表。',
            ];
        }

        $defaults = $this->defaults();
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
        if (trim((string) ($stored['active_theme'] ?? '')) === '') {
            $stored['active_theme'] = (string) config('geoflow.default_theme', '');
        }

        if (trim((string) ($stored['site_title'] ?? '')) === '') {
            $stored['site_title'] = (string) ($stored['site_name'] ?? $defaults['site_name']);
        }

        $meta = [
            'scope' => $includeHeavy ? 'full' : 'summary',
            'truncated_fields' => [],
        ];

        $analytics = (string) ($stored['analytics_code'] ?? '');
        if ($includeHeavy) {
            $stored['analytics_code'] = $this->truncateField('analytics_code', $analytics, 800, $meta);
        } else {
            $stored['analytics_code'] = $analytics === '' ? '' : '[已配置，长度 '.mb_strlen($analytics).' 字符；将 scope 设为 full 可查看截断预览]';
        }

        $carousel = (string) ($stored['home_carousel_slides'] ?? '');
        $ads = (string) ($stored['article_detail_ads'] ?? '');
        $maxJson = $includeHeavy ? 4000 : 400;
        $stored['home_carousel_slides'] = $this->truncateField('home_carousel_slides', $carousel, $maxJson, $meta);
        $stored['article_detail_ads'] = $this->truncateField('article_detail_ads', $ads, $maxJson, $meta);

        return [
            'ok' => true,
            'source' => 'site_settings',
            'meta' => $meta,
            'settings' => $stored,
            'integrations' => $this->siteWrite->buildIntegrationsSnapshot(),
        ];
    }

    /**
     * 将过长字段截断并记录元数据，避免撑爆模型上下文。
     *
     * @param  array<string, mixed>  $meta
     */
    private function truncateField(string $field, string $value, int $maxChars, array &$meta): string
    {
        if ($value === '') {
            return '';
        }
        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }
        $suffix = '…';
        $take = max(0, $maxChars - mb_strlen($suffix));
        $meta['truncated_fields'][] = [
            'field' => $field,
            'original_length' => mb_strlen($value),
            'returned_length' => $maxChars,
        ];

        return mb_substr($value, 0, $take).$suffix;
    }
}
