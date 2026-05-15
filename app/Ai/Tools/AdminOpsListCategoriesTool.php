<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AdminOps\AdminOpsCategoryReadService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * AI 运维只读工具：列出站点文章栏目（categories 表：名称、slug、排序、文章数等）。
 */
final class AdminOpsListCategoriesTool implements Tool
{
    public function __construct(
        private readonly AdminOpsCategoryReadService $categoryRead,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '列出当前站点全部文章栏目/分类（id、name、slug、description、sort_order、article_count、created_at）。用户问「有哪些栏目」「分类列表」时必须调用本工具；不提供增删改，仅只读。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        try {
            $rows = $this->categoryRead->listCategoriesWithArticleCounts();

            return json_encode([
                'ok' => true,
                'source' => 'categories',
                'categories' => $rows,
                'total' => count($rows),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        } catch (Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => '读取栏目列表失败：'.$e->getMessage(),
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
        return [];
    }
}
