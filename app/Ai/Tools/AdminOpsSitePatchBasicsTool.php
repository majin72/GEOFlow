<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AdminOps\AdminOpsSiteWriteService;
use App\Services\Admin\AiOps\AdminAiOpsPendingWriteGuard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * AI 运维写库工具：按 JSON 合并更新站点基础设置（site_settings 中与后台「站点设置」表单一致的字段）。
 */
final class AdminOpsSitePatchBasicsTool implements Tool
{
    public function __construct(
        private readonly AdminOpsSiteWriteService $siteWrite,
        private readonly AdminAiOpsPendingWriteGuard $writeGuard,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '写入站点基础配置（site_settings）：站点名/副标题/描述关键词/版权与备案/Logo/Favicon/统计代码/SEO 模板/精选条数/分页/首页轮播 JSON/admin_base_path 等。参数 patch_json 为 JSON 对象，仅包含需要修改的键；未出现的键保持原值。修改 admin_base_path 会改写路由缓存文件，请仅在用户明确要求时操作。写入前建议先调用 AdminOpsSiteInfoTool 读取当前值。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $raw = trim((string) Arr::get($request->toArray(), 'patch_json', ''));
        if ($raw === '') {
            return json_encode(['ok' => false, 'error' => 'patch_json 不能为空。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return json_encode(['ok' => false, 'error' => 'patch_json 不是合法 JSON。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        if (! is_array($decoded)) {
            return json_encode(['ok' => false, 'error' => 'patch_json 必须解析为 JSON 对象。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return $this->writeGuard->runJson(
            'AdminOpsSitePatchBasicsTool',
            [
                'patch' => $decoded,
            ],
            fn (): array => $this->siteWrite->patchBasics($decoded),
        );
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'patch_json' => $schema->string()
                ->description('要合并写入的字段 JSON 对象（字符串）。可含：site_name（站点名称/标题，勿用 site_title）, site_subtitle, site_description, site_keywords, copyright_info, site_icp_beian, site_police_beian, site_police_beian_code, site_logo, site_favicon, analytics_code, seo_title_template, seo_description_template, featured_limit, per_page, home_carousel_slides（数组或 JSON 字符串）, admin_base_path。'),
        ];
    }
}
