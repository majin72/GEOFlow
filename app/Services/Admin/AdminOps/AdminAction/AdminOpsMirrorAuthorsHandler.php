<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps\AdminAction;

use App\Http\Controllers\Admin\AuthorController;
use App\Models\Article;
use App\Models\Author;
use Illuminate\Support\Facades\Validator;

/**
 * 作者只读与 CRUD（对齐 {@see AuthorController}）。
 */
final class AdminOpsMirrorAuthorsHandler
{
    private const PER_PAGE = 20;

    /**
     * 作者列表分页。
     *
     * @return array{ok: bool, data?: array<string, mixed>}
     */
    public function list(string $search, int $page): array
    {
        $page = max(1, $page);
        $query = Author::query()
            ->select(['id', 'name', 'email', 'bio', 'website', 'social_links', 'created_at'])
            ->orderByDesc('created_at');
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('bio', 'like', '%'.$search.'%');
            });
        }
        $query->withCount([
            'articles as article_count' => fn ($b) => $b->whereNull('deleted_at'),
            'articles as published_count' => fn ($b) => $b->where('status', 'published')->whereNull('deleted_at'),
            'articles as trashed_count' => fn ($b) => $b->whereNotNull('deleted_at'),
        ]);
        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);
        $items = collect($paginator->items())->map(static function (Author $author): array {
            return [
                'id' => (int) $author->id,
                'name' => (string) $author->name,
                'email' => (string) ($author->email ?? ''),
                'bio' => (string) ($author->bio ?? ''),
                'website' => (string) ($author->website ?? ''),
                'social_links' => (string) ($author->social_links ?? ''),
                'created_at' => $author->created_at?->format('Y-m-d H:i:s'),
                'article_count' => (int) ($author->article_count ?? 0),
                'published_count' => (int) ($author->published_count ?? 0),
                'trashed_count' => (int) ($author->trashed_count ?? 0),
            ];
        })->all();

        return [
            'ok' => true,
            'data' => [
                'items' => $items,
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'stats' => $this->stats(),
            ],
        ];
    }

    /**
     * @return array{total_authors: int, active_authors: int, avg_articles: float}
     */
    public function stats(): array
    {
        $totalAuthors = Author::query()->count();
        $activeAuthors = Article::query()
            ->whereNotNull('author_id')
            ->whereNull('deleted_at')
            ->distinct('author_id')
            ->count('author_id');
        $totalArticles = Article::query()->whereNotNull('author_id')->whereNull('deleted_at')->count();

        return [
            'total_authors' => $totalAuthors,
            'active_authors' => $activeAuthors,
            'avg_articles' => $totalAuthors > 0 ? round($totalArticles / $totalAuthors, 1) : 0.0,
        ];
    }

    /**
     * @return array{ok: bool, author?: array<string, mixed>, recent_articles?: list<array<string, mixed>>, error?: string}
     */
    public function detail(int $authorId): array
    {
        $author = Author::query()->whereKey($authorId)->first();
        if ($author === null) {
            return ['ok' => false, 'error' => '作者不存在'];
        }
        $articles = Article::query()
            ->select(['id', 'title', 'status', 'review_status', 'created_at', 'deleted_at'])
            ->where('author_id', $authorId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(static fn (Article $a): array => [
                'id' => (int) $a->id,
                'title' => (string) $a->title,
                'status' => (string) $a->status,
                'review_status' => (string) $a->review_status,
                'created_at' => $a->created_at?->format('Y-m-d H:i:s'),
                'deleted_at' => $a->deleted_at?->format('Y-m-d H:i:s'),
            ])
            ->all();

        return [
            'ok' => true,
            'author' => [
                'id' => (int) $author->id,
                'name' => (string) $author->name,
                'email' => (string) ($author->email ?? ''),
                'bio' => (string) ($author->bio ?? ''),
                'website' => (string) ($author->website ?? ''),
                'social_links' => (string) ($author->social_links ?? ''),
                'created_at' => $author->created_at?->format('Y-m-d H:i:s'),
            ],
            'recent_articles' => $articles,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, author_id?: int, error?: string, validation_errors?: array<string, mixed>}
     */
    public function create(array $payload): array
    {
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:200'],
            'social_links' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $row = Author::query()->create([
            'name' => trim((string) $p['name']),
            'email' => trim((string) ($p['email'] ?? '')),
            'bio' => trim((string) ($p['bio'] ?? '')),
            'website' => trim((string) ($p['website'] ?? '')),
            'social_links' => trim((string) ($p['social_links'] ?? '')),
        ]);

        return ['ok' => true, 'author_id' => (int) $row->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function update(int $authorId, array $payload): array
    {
        $author = Author::query()->whereKey($authorId)->first();
        if ($author === null) {
            return ['ok' => false, 'error' => '作者不存在'];
        }
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:200'],
            'social_links' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $author->update([
            'name' => trim((string) $p['name']),
            'email' => trim((string) ($p['email'] ?? '')),
            'bio' => trim((string) ($p['bio'] ?? '')),
            'website' => trim((string) ($p['website'] ?? '')),
            'social_links' => trim((string) ($p['social_links'] ?? '')),
        ]);

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function delete(int $authorId): array
    {
        $author = Author::query()->whereKey($authorId)->first();
        if ($author === null) {
            return ['ok' => false, 'error' => '作者不存在'];
        }
        $visibleCount = Article::query()->where('author_id', $authorId)->whereNull('deleted_at')->count();
        if ($visibleCount > 0) {
            return ['ok' => false, 'error' => '仍有未删除文章引用该作者（'.$visibleCount.'）。'];
        }
        $trashedCount = Article::query()->where('author_id', $authorId)->whereNotNull('deleted_at')->count();
        if ($trashedCount > 0) {
            return ['ok' => false, 'error' => '回收站仍有文章引用该作者（'.$trashedCount.'）。'];
        }
        $author->delete();

        return ['ok' => true];
    }
}
