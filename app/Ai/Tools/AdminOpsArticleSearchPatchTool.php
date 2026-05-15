<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AdminOps\AdminOpsSiteWriteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * AI 运维写库工具：合并更新文章联网搜索（article_search_* site_settings）。
 */
final class AdminOpsArticleSearchPatchTool implements Tool
{
    public function __construct(
        private readonly AdminOpsSiteWriteService $siteWrite,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '合并写入文章联网搜索配置（Tavily 等）：enabled, endpoint, api_key, timeout, max_results, search_depth(basic|advanced), include_domains(逗号分隔域名), cache_ttl。patch_json 仅含需修改的键。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $raw = trim((string) Arr::get($request->toArray(), 'patch_json', '{}'));

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return json_encode(['ok' => false, 'error' => 'patch_json 不是合法 JSON。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        if (! is_array($decoded)) {
            return json_encode(['ok' => false, 'error' => 'patch_json 必须为 JSON 对象。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return json_encode($this->siteWrite->patchArticleSearch($decoded), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
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
                ->description('要合并的 JSON 对象字符串，键名：enabled, endpoint, api_key, timeout, max_results, search_depth, include_domains, cache_ttl。'),
        ];
    }
}
