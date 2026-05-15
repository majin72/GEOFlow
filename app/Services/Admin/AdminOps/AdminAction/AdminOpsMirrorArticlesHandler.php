<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps\AdminAction;

use App\Http\Controllers\Admin\ArticleController;
use App\Models\Article;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * 文章列表与批量操作（对齐 {@see ArticleController} 核心查询与状态机）。
 */
final class AdminOpsMirrorArticlesHandler
{
    /**
     * 文章列表分页（filters 键与后台 query 一致）。
     *
     * @param  array<string, mixed>  $filters
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function list(array $filters): array
    {
        $built = $this->buildFilters($filters);
        $query = ($built['trashed'] ?? false)
            ? Article::onlyTrashed()
            : Article::query();
        $query->with([
            'task:id,name,need_review',
            'author:id,name',
            'category:id,name',
        ]);
        if ($built['trashed'] ?? false) {
            $query->orderByDesc('deleted_at');
        } else {
            $query->orderByDesc('created_at');
        }
        if ($built['task_id'] > 0) {
            $query->where('task_id', $built['task_id']);
        }
        if (($built['trashed'] ?? false) === false && $built['status'] !== '') {
            $query->where('status', $built['status']);
        }
        if (($built['trashed'] ?? false) === false && $built['review_status'] !== '') {
            $query->where('review_status', $built['review_status']);
        }
        if ($built['author_id'] > 0) {
            $query->where('author_id', $built['author_id']);
        }
        if ($built['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $built['date_from']);
        }
        if ($built['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $built['date_to']);
        }
        if ($built['search'] !== '') {
            $s = $built['search'];
            $query->where(function ($sub) use ($s): void {
                $sub->where('title', 'like', '%'.$s.'%')
                    ->orWhere('content', 'like', '%'.$s.'%');
            });
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = $query->paginate($built['per_page'], ['*'], 'page', $page);
        $items = collect($paginator->items())->map(function (Article $a): array {
            return [
                'id' => (int) $a->id,
                'title' => (string) $a->title,
                'status' => (string) $a->status,
                'review_status' => (string) $a->review_status,
                'category' => $a->category ? ['id' => (int) $a->category->id, 'name' => (string) $a->category->name] : null,
                'author' => $a->author ? ['id' => (int) $a->author->id, 'name' => (string) $a->author->name] : null,
                'task' => $a->task ? ['id' => (int) $a->task->id, 'name' => (string) $a->task->name] : null,
                'created_at' => $a->created_at?->format('Y-m-d H:i:s'),
                'deleted_at' => $a->deleted_at?->format('Y-m-d H:i:s'),
            ];
        })->all();

        return [
            'ok' => true,
            'data' => [
                'items' => $items,
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'stats' => ($built['trashed'] ?? false) ? $this->trashStats() : $this->listStats(),
            ],
        ];
    }

    /**
     * 单篇详情（含软删可查：传 trashed=true 时在仅软删集合中查）。
     *
     * @return array{ok: bool, article?: array<string, mixed>, error?: string}
     */
    public function show(int $articleId, bool $onlyTrashed = false): array
    {
        $q = $onlyTrashed ? Article::onlyTrashed() : Article::query();
        $a = $q->with(['task:id,name', 'author:id,name', 'category:id,name'])->whereKey($articleId)->first();
        if ($a === null) {
            return ['ok' => false, 'error' => '文章不存在'];
        }

        return [
            'ok' => true,
            'article' => [
                'id' => (int) $a->id,
                'title' => (string) $a->title,
                'slug' => (string) $a->slug,
                'excerpt' => (string) ($a->excerpt ?? ''),
                'content' => (string) $a->content,
                'keywords' => (string) ($a->keywords ?? ''),
                'meta_description' => (string) ($a->meta_description ?? ''),
                'status' => (string) $a->status,
                'review_status' => (string) $a->review_status,
                'category_id' => (int) $a->category_id,
                'author_id' => (int) $a->author_id,
                'task_id' => $a->task_id ? (int) $a->task_id : null,
                'is_hot' => (bool) $a->is_hot,
                'is_featured' => (bool) $a->is_featured,
                'published_at' => $a->published_at?->format('Y-m-d H:i:s'),
                'created_at' => $a->created_at?->format('Y-m-d H:i:s'),
                'deleted_at' => $a->deleted_at?->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * @param  list<int>  $articleIds
     */
    public function batchUpdateStatus(array $articleIds, string $newStatus): array
    {
        if ($articleIds === []) {
            return ['ok' => false, 'error' => 'article_ids 不能为空'];
        }
        if (! in_array($newStatus, ['draft', 'published', 'private'], true)) {
            return ['ok' => false, 'error' => '非法 new_status'];
        }
        $articles = Article::query()
            ->select(['id', 'review_status', 'published_at'])
            ->whereIn('id', $articleIds)
            ->get();
        foreach ($articles as $article) {
            $workflowState = ArticleWorkflow::normalizeState(
                $newStatus,
                (string) ($article->review_status ?? 'pending'),
                $article->published_at?->format('Y-m-d H:i:s')
            );
            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
            ]);
        }

        return ['ok' => true, 'updated' => count($articleIds)];
    }

    /**
     * @param  list<int>  $articleIds
     */
    public function batchUpdateReview(array $articleIds, string $reviewStatus): array
    {
        if ($articleIds === []) {
            return ['ok' => false, 'error' => 'article_ids 不能为空'];
        }
        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            return ['ok' => false, 'error' => '非法 review_status'];
        }
        $articles = Article::query()
            ->with(['task:id,need_review'])
            ->select(['id', 'status', 'review_status', 'published_at', 'task_id'])
            ->whereIn('id', $articleIds)
            ->get();
        foreach ($articles as $article) {
            $desiredStatus = (string) ($article->status ?? 'draft');
            $needsReview = (int) ($article->task->need_review ?? 0);
            if (in_array($reviewStatus, ['approved', 'auto_approved'], true) && ($reviewStatus === 'auto_approved' || $needsReview === 0)) {
                $desiredStatus = 'published';
            }
            $workflowState = ArticleWorkflow::normalizeState(
                $desiredStatus,
                $reviewStatus,
                $article->published_at?->format('Y-m-d H:i:s')
            );
            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
            ]);
        }

        return ['ok' => true, 'updated' => count($articleIds)];
    }

    /**
     * @param  list<int>  $articleIds
     */
    public function batchSoftDelete(array $articleIds): array
    {
        if ($articleIds === []) {
            return ['ok' => false, 'error' => 'article_ids 不能为空'];
        }
        foreach ($articleIds as $id) {
            Article::query()->whereKey($id)->delete();
        }

        return ['ok' => true, 'deleted' => count($articleIds)];
    }

    /**
     * @param  list<int>  $articleIds
     */
    public function batchRestore(array $articleIds): array
    {
        if ($articleIds === []) {
            return ['ok' => false, 'error' => 'article_ids 不能为空'];
        }
        $count = Article::onlyTrashed()->whereIn('id', $articleIds)->restore();

        return ['ok' => true, 'restored' => (int) $count];
    }

    /**
     * @param  list<int>  $articleIds
     */
    public function batchForceDelete(array $articleIds): array
    {
        if ($articleIds === []) {
            return ['ok' => false, 'error' => 'article_ids 不能为空'];
        }
        $models = Article::onlyTrashed()->whereIn('id', $articleIds)->get();
        $models->each(fn (Article $a) => $a->forceDelete());

        return ['ok' => true, 'deleted' => $models->count()];
    }

    /**
     * 清空回收站。
     */
    public function emptyTrash(): array
    {
        $models = Article::onlyTrashed()->get();
        $total = $models->count();
        $models->each(fn (Article $a) => $a->forceDelete());

        return ['ok' => true, 'deleted' => $total];
    }

    public function restoreOne(int $articleId): array
    {
        $a = Article::onlyTrashed()->whereKey($articleId)->first();
        if ($a === null) {
            return ['ok' => false, 'error' => '未找到软删文章'];
        }
        $a->restore();

        return ['ok' => true];
    }

    public function forceDeleteOne(int $articleId): array
    {
        $a = Article::onlyTrashed()->whereKey($articleId)->first();
        if ($a === null) {
            return ['ok' => false, 'error' => '未找到软删文章'];
        }
        $a->forceDelete();

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, article_id?: int, error?: string, validation_errors?: array<string, mixed>}
     */
    public function store(array $payload): array
    {
        $v = $this->validateArticle($payload, false);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $workflowState = ArticleWorkflow::normalizeState(
            (string) $p['status'],
            (string) $p['review_status']
        );
        $excerpt = $p['excerpt'] !== '' && $p['excerpt'] !== null
            ? (string) $p['excerpt']
            : mb_substr(strip_tags((string) $p['content']), 0, 200, 'UTF-8');
        $row = Article::query()->create([
            'title' => (string) $p['title'],
            'slug' => ArticleWorkflow::generateUniqueSlug((string) $p['title']),
            'excerpt' => $excerpt,
            'content' => (string) $p['content'],
            'keywords' => (string) ($p['keywords'] ?? ''),
            'meta_description' => (string) ($p['meta_description'] ?? ''),
            'category_id' => (int) $p['category_id'],
            'author_id' => (int) $p['author_id'],
            'task_id' => null,
            'status' => $workflowState['status'],
            'review_status' => $workflowState['review_status'],
            'published_at' => $workflowState['published_at'],
            'is_hot' => (bool) ($p['is_hot'] ?? false),
            'is_featured' => (bool) ($p['is_featured'] ?? false),
            'is_ai_generated' => 0,
        ]);

        return ['ok' => true, 'article_id' => (int) $row->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function update(int $articleId, array $payload): array
    {
        $article = Article::query()->whereKey($articleId)->first();
        if ($article === null) {
            return ['ok' => false, 'error' => '文章不存在'];
        }
        $v = $this->validateArticle($payload, true);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $workflowState = ArticleWorkflow::normalizeState(
            (string) $p['status'],
            (string) $p['review_status'],
            $article->published_at?->format('Y-m-d H:i:s')
        );
        $excerpt = $p['excerpt'] !== '' && $p['excerpt'] !== null
            ? (string) $p['excerpt']
            : mb_substr(strip_tags((string) $p['content']), 0, 200, 'UTF-8');
        $article->fill([
            'title' => (string) $p['title'],
            'excerpt' => $excerpt,
            'content' => (string) $p['content'],
            'keywords' => (string) ($p['keywords'] ?? ''),
            'meta_description' => (string) ($p['meta_description'] ?? ''),
            'category_id' => (int) $p['category_id'],
            'author_id' => (int) $p['author_id'],
            'status' => $workflowState['status'],
            'review_status' => $workflowState['review_status'],
            'published_at' => $workflowState['published_at'],
            'is_hot' => (bool) ($p['is_hot'] ?? false),
            'is_featured' => (bool) ($p['is_featured'] ?? false),
        ])->save();

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateArticle(array $data, bool $isEdit): \Illuminate\Validation\Validator
    {
        return Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'category_id' => ['required', 'integer', 'min:1'],
            'author_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:draft,published,private'],
            'review_status' => ['required', 'string', 'in:pending,approved,rejected,auto_approved'],
            'is_hot' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{task_id: int, status: string, review_status: string, author_id: int, date_from: string, date_to: string, search: string, per_page: int, trashed: bool}
     */
    private function buildFilters(array $filters): array
    {
        $status = (string) ($filters['status'] ?? '');
        if (! in_array($status, ['draft', 'published', 'private'], true)) {
            $status = '';
        }
        $reviewStatus = (string) ($filters['review_status'] ?? '');
        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            $reviewStatus = '';
        }
        $trashed = filter_var($filters['trashed'] ?? false, FILTER_VALIDATE_BOOLEAN) || ($filters['trashed'] ?? '') === '1';

        return [
            'task_id' => max(0, (int) ($filters['task_id'] ?? 0)),
            'status' => $status,
            'review_status' => $reviewStatus,
            'author_id' => max(0, (int) ($filters['author_id'] ?? 0)),
            'date_from' => trim((string) ($filters['date_from'] ?? '')),
            'date_to' => trim((string) ($filters['date_to'] ?? '')),
            'search' => trim((string) ($filters['search'] ?? '')),
            'per_page' => min(100, max(10, (int) ($filters['per_page'] ?? 20) ?: 20)),
            'trashed' => $trashed,
        ];
    }

    /**
     * @return array{total: int, published: int, draft: int, pending_review: int, today: int}
     */
    private function listStats(): array
    {
        $baseQuery = Article::query();

        return [
            'total' => (clone $baseQuery)->count(),
            'published' => (clone $baseQuery)->where('status', 'published')->count(),
            'draft' => (clone $baseQuery)->where('status', 'draft')->count(),
            'pending_review' => (clone $baseQuery)->where('review_status', 'pending')->count(),
            'today' => (clone $baseQuery)->whereDate('created_at', Carbon::today())->count(),
        ];
    }

    /**
     * @return array{trashed_total: int}
     */
    private function trashStats(): array
    {
        return ['trashed_total' => Article::onlyTrashed()->count()];
    }
}
