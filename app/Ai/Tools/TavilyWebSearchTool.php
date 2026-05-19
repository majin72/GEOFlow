<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AiOps\AdminAiOpsStreamContext;
use App\Services\GeoFlow\ArticleSearch\TavilyArticleSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * 文章生成用联网搜索 Tool。
 *
 * Tool 只承担 AI SDK 入口职责，缓存和外部 API 调用由 TavilyArticleSearchService 负责。
 */
class TavilyWebSearchTool implements Tool
{
    public function __construct(
        private readonly TavilyArticleSearchService $searchService,
    ) {}

    /**
     * 获取工具用途说明。
     */
    public function description(): Stringable|string
    {
        return 'Search the web for recent facts, market information, sources, and background material that can improve an article.';
    }

    /**
     * 执行搜索。
     */
    public function handle(Request $request): Stringable|string
    {
        try {
            $forAiOps = app()->bound(AdminAiOpsStreamContext::class);

            return $this->searchService->search((string) $request->string('query'), $forAiOps);
        } catch (Throwable $exception) {
            return '联网搜索暂不可用：'.$exception->getMessage();
        }
    }

    /**
     * 获取工具参数结构。
     *
     * @return array<string,mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('A concise web search query for article research.')
                ->required(),
        ];
    }
}
