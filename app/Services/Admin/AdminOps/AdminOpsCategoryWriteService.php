<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps;

use App\Http\Controllers\Admin\CategoryController;
use App\Models\Category;
use Illuminate\Support\Facades\Validator;

/**
 * AI 运维与后台栏目页共用的栏目写入（对齐 {@see CategoryController} 校验与 slug 规则）。
 */
final class AdminOpsCategoryWriteService
{
    /**
     * 新建栏目。
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>, category_id?: int}
     */
    public function store(array $payload): array
    {
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过。', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $name = trim((string) $p['name']);
        if (Category::query()->where('name', $name)->exists()) {
            return ['ok' => false, 'error' => '栏目名称已存在。'];
        }
        $slug = $this->buildCategorySlug($name, (string) ($p['slug'] ?? ''), 0);
        $row = Category::query()->create([
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string) ($p['description'] ?? '')),
            'sort_order' => (int) ($p['sort_order'] ?? 0),
        ]);

        return ['ok' => true, 'category_id' => (int) $row->id];
    }

    /**
     * 更新栏目。
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function update(int $categoryId, array $payload): array
    {
        $category = Category::query()->whereKey($categoryId)->first();
        if ($category === null) {
            return ['ok' => false, 'error' => '栏目不存在。'];
        }
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过。', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $name = trim((string) $p['name']);
        if (Category::query()->where('name', $name)->where('id', '!=', $categoryId)->exists()) {
            return ['ok' => false, 'error' => '栏目名称已存在。'];
        }
        $slug = $this->buildCategorySlug($name, (string) ($p['slug'] ?? ''), $categoryId);
        $category->update([
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string) ($p['description'] ?? '')),
            'sort_order' => (int) ($p['sort_order'] ?? 0),
        ]);

        return ['ok' => true];
    }

    /**
     * 删除栏目（下有文章则拒绝）。
     *
     * @return array{ok: bool, error?: string}
     */
    public function destroy(int $categoryId): array
    {
        $category = Category::query()->withCount('articles')->whereKey($categoryId)->first();
        if ($category === null) {
            return ['ok' => false, 'error' => '栏目不存在。'];
        }
        if ((int) ($category->articles_count ?? 0) > 0) {
            return ['ok' => false, 'error' => '该栏目下仍有文章，无法删除。'];
        }
        Category::query()->whereKey($categoryId)->delete();

        return ['ok' => true];
    }

    /**
     * 生成唯一 slug（与后台栏目控制器一致）。
     */
    private function buildCategorySlug(string $name, string $rawSlug = '', int $excludeId = 0): string
    {
        $source = trim($rawSlug) !== '' ? trim($rawSlug) : trim($name);
        $slug = mb_strtolower($source, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?: '';
        $slug = trim((string) $slug, '-');

        if ($slug === '') {
            $slug = 'cat-'.substr(md5($name), 0, 8);
        }

        $baseSlug = $slug;
        $counter = 2;
        while (true) {
            $existsQuery = Category::query()->where('slug', $slug);
            if ($excludeId > 0) {
                $existsQuery->where('id', '!=', $excludeId);
            }
            if (! $existsQuery->exists()) {
                return $slug;
            }
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }
    }
}
