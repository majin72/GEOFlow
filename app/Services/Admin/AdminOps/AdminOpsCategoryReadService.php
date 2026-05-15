<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps;

use App\Models\Category;

/**
 * AI 运维与后台栏目页共用的只读查询：从 categories 表列出栏目及文章数。
 */
final class AdminOpsCategoryReadService
{
    /**
     * 列出全部栏目（与后台栏目列表排序、字段一致），用于 AI 运维只读工具与栏目管理页。
     *
     * @return array<int, array{id:int,name:string,slug:string,description:string,sort_order:int,article_count:int,created_at:?string}>
     */
    public function listCategoriesWithArticleCounts(): array
    {
        return Category::query()
            ->select(['id', 'name', 'slug', 'description', 'sort_order', 'created_at'])
            ->withCount('articles')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(static function (Category $category): array {
                return [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                    'slug' => (string) ($category->slug ?? ''),
                    'description' => (string) ($category->description ?? ''),
                    'sort_order' => (int) ($category->sort_order ?? 0),
                    'article_count' => (int) ($category->articles_count ?? 0),
                    'created_at' => $category->created_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->all();
    }
}
