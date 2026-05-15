<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps\AdminAction;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TitleAiGenerationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * 素材库（关键词/标题/图片/知识库）读写：对齐各 Material 控制器主要路由；图片与知识库文件上传请走后台表单（multipart），本工具返回说明。
 */
final class AdminOpsMirrorLibrariesHandler
{
    public function __construct(
        private readonly TitleAiGenerationService $titleAiGenerationService,
    ) {}

    // --- Keyword libraries ---

    /**
     * @return list<array<string, mixed>>
     */
    public function keywordLibrariesList(): array
    {
        return KeywordLibrary::query()
            ->select(['id', 'name', 'description', 'created_at', 'updated_at'])
            ->withCount('keywords as actual_count')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (KeywordLibrary $l): array => [
                'id' => (int) $l->id,
                'name' => (string) $l->name,
                'description' => (string) ($l->description ?? ''),
                'actual_count' => (int) ($l->actual_count ?? 0),
                'created_at' => $l->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $l->updated_at?->format('Y-m-d H:i:s'),
            ])
            ->all();
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function keywordLibraryDetail(int $libraryId, string $search, int $page): array
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->first();
        if ($library === null) {
            return ['ok' => false, 'error' => '关键词库不存在'];
        }
        $page = max(1, $page);
        $q = Keyword::query()->where('library_id', $libraryId)->orderByDesc('created_at');
        if ($search !== '') {
            $q->where('keyword', 'like', '%'.$search.'%');
        }
        $paginator = $q->paginate(50, ['*'], 'page', $page);
        $usageTotal = $this->keywordUsageTotal($libraryId);

        return [
            'ok' => true,
            'data' => [
                'library' => ['id' => (int) $library->id, 'name' => (string) $library->name, 'description' => (string) ($library->description ?? '')],
                'usage_total' => $usageTotal,
                'keywords' => collect($paginator->items())->map(static fn (Keyword $k): array => [
                    'id' => (int) $k->id,
                    'keyword' => (string) $k->keyword,
                    'used_count' => (int) ($k->used_count ?? 0),
                    'usage_count' => (int) ($k->usage_count ?? 0),
                    'created_at' => $k->created_at?->format('Y-m-d H:i:s'),
                ])->all(),
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, library_id?: int, error?: string, validation_errors?: array<string, mixed>}
     */
    public function keywordLibraryStore(array $payload): array
    {
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $row = KeywordLibrary::query()->create([
            'name' => trim((string) $p['name']),
            'description' => trim((string) ($p['description'] ?? '')),
            'keyword_count' => 0,
        ]);

        return ['ok' => true, 'library_id' => (int) $row->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function keywordLibraryUpdate(int $libraryId, array $payload): array
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->first();
        if ($library === null) {
            return ['ok' => false, 'error' => '关键词库不存在'];
        }
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $library->update([
            'name' => trim((string) $p['name']),
            'description' => trim((string) ($p['description'] ?? '')),
        ]);

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function keywordLibraryDestroy(int $libraryId): array
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->first();
        if ($library === null) {
            return ['ok' => false, 'error' => '关键词库不存在'];
        }
        Keyword::query()->where('library_id', $libraryId)->delete();
        $library->delete();

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function keywordAdd(int $libraryId, string $keyword): array
    {
        KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['ok' => false, 'error' => 'keyword 不能为空'];
        }
        if (Keyword::query()->where('library_id', $libraryId)->where('keyword', $keyword)->exists()) {
            return ['ok' => false, 'error' => '关键词已存在'];
        }
        Keyword::query()->create([
            'library_id' => $libraryId,
            'keyword' => $keyword,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->refreshKeywordLibraryCount($libraryId);

        return ['ok' => true];
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array{ok: bool, error?: string, deleted?: int}
     */
    public function keywordDeleteBatch(int $libraryId, array $keywordIds): array
    {
        KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();
        $ids = array_values(array_filter(array_map(static fn ($v): int => (int) $v, $keywordIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ['ok' => false, 'error' => 'keyword_ids 不能为空'];
        }
        $deleted = Keyword::query()->where('library_id', $libraryId)->whereIn('id', $ids)->delete();
        $this->refreshKeywordLibraryCount($libraryId);

        return ['ok' => true, 'deleted' => (int) $deleted];
    }

    /**
     * @return array{ok: bool, error?: string, imported?: int, duplicates?: int}
     */
    public function keywordImport(int $libraryId, string $keywordsText): array
    {
        KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();
        $keywords = $this->parseKeywordImportText($keywordsText);
        if ($keywords->isEmpty()) {
            return ['ok' => false, 'error' => '无有效关键词文本'];
        }
        $imported = 0;
        $duplicate = 0;
        DB::transaction(function () use ($keywords, $libraryId, &$imported, &$duplicate): void {
            foreach ($keywords as $kw) {
                if (Keyword::query()->where('library_id', $libraryId)->where('keyword', $kw)->exists()) {
                    $duplicate++;

                    continue;
                }
                Keyword::query()->create([
                    'library_id' => $libraryId,
                    'keyword' => $kw,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);
                $imported++;
            }
        });
        $this->refreshKeywordLibraryCount($libraryId);

        return ['ok' => true, 'imported' => $imported, 'duplicates' => $duplicate];
    }

    // --- Title libraries ---

    /**
     * @return list<array<string, mixed>>
     */
    public function titleLibrariesList(): array
    {
        return TitleLibrary::query()
            ->select(['id', 'name', 'description', 'generation_type', 'created_at', 'updated_at'])
            ->withCount('titles as title_count')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (TitleLibrary $l): array => [
                'id' => (int) $l->id,
                'name' => (string) $l->name,
                'description' => (string) ($l->description ?? ''),
                'generation_type' => (string) ($l->generation_type ?? 'manual'),
                'title_count' => (int) ($l->title_count ?? 0),
            ])
            ->all();
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function titleLibraryDetail(int $libraryId, int $page): array
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->first();
        if ($library === null) {
            return ['ok' => false, 'error' => '标题库不存在'];
        }
        $page = max(1, $page);
        $paginator = Title::query()->where('library_id', $libraryId)->orderByDesc('created_at')->paginate(20, ['*'], 'page', $page);
        $usageTotal = (int) (Title::query()->where('library_id', $libraryId)->sum('used_count') ?? 0);

        return [
            'ok' => true,
            'data' => [
                'library' => ['id' => (int) $library->id, 'name' => (string) $library->name],
                'usage_total' => $usageTotal,
                'titles' => collect($paginator->items())->map(static fn (Title $t): array => [
                    'id' => (int) $t->id,
                    'title' => (string) $t->title,
                    'used_count' => (int) ($t->used_count ?? 0),
                    'created_at' => $t->created_at?->format('Y-m-d H:i:s'),
                ])->all(),
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, library_id?: int, error?: string, validation_errors?: array<string, mixed>}
     */
    public function titleLibraryStore(array $payload): array
    {
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $row = TitleLibrary::query()->create([
            'name' => trim((string) $p['name']),
            'description' => trim((string) ($p['description'] ?? '')),
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        return ['ok' => true, 'library_id' => (int) $row->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function titleLibraryUpdate(int $libraryId, array $payload): array
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->first();
        if ($library === null) {
            return ['ok' => false, 'error' => '标题库不存在'];
        }
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $library->update([
            'name' => trim((string) $p['name']),
            'description' => trim((string) ($p['description'] ?? '')),
        ]);
        $this->refreshTitleLibraryCount($libraryId);

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function titleLibraryDestroy(int $libraryId): array
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->first();
        if ($library === null) {
            return ['ok' => false, 'error' => '标题库不存在'];
        }
        if (Task::query()->where('title_library_id', $libraryId)->exists()) {
            return ['ok' => false, 'error' => '仍有任务引用该标题库，无法删除'];
        }
        Title::query()->where('library_id', $libraryId)->delete();
        $library->delete();

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function titleAdd(int $libraryId, string $titleText): array
    {
        TitleLibrary::query()->whereKey($libraryId)->firstOrFail();
        $titleText = trim($titleText);
        if ($titleText === '' || mb_strlen($titleText, 'UTF-8') > 500) {
            return ['ok' => false, 'error' => '标题无效或过长'];
        }
        if (Title::query()->where('library_id', $libraryId)->where('title', $titleText)->exists()) {
            return ['ok' => false, 'error' => '标题已存在'];
        }
        Title::query()->create([
            'library_id' => $libraryId,
            'title' => $titleText,
            'keyword' => '',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->refreshTitleLibraryCount($libraryId);

        return ['ok' => true];
    }

    /**
     * @param  list<int>  $titleIds
     * @return array{ok: bool, error?: string, deleted?: int}
     */
    public function titleDeleteBatch(int $libraryId, array $titleIds): array
    {
        TitleLibrary::query()->whereKey($libraryId)->firstOrFail();
        $ids = array_values(array_filter(array_map(static fn ($v): int => (int) $v, $titleIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ['ok' => false, 'error' => 'title_ids 不能为空'];
        }
        $deleted = Title::query()->where('library_id', $libraryId)->whereIn('id', $ids)->delete();
        $this->refreshTitleLibraryCount($libraryId);

        return ['ok' => true, 'deleted' => (int) $deleted];
    }

    /**
     * AI 生成标题（对齐 TitleLibraryController::generateWithAi 核心逻辑）。
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>, saved?: int, duplicates?: int}
     */
    public function titleLibraryAiGenerate(int $libraryId, array $payload): array
    {
        TitleLibrary::query()->whereKey($libraryId)->firstOrFail();
        $v = Validator::make($payload, [
            'keyword_library_id' => ['required', 'integer'],
            'ai_model_id' => [
                'required',
                'integer',
                Rule::exists('ai_models', 'id')->where(static function ($query): void {
                    $query->where('status', 'active')
                        ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'");
                }),
            ],
            'title_count' => ['required', 'integer', 'min:1', 'max:50'],
            'title_style' => ['required', 'in:professional,attractive,seo,creative,question'],
            'custom_prompt' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        KeywordLibrary::query()->whereKey((int) $p['keyword_library_id'])->firstOrFail();
        AiModel::query()
            ->whereKey((int) $p['ai_model_id'])
            ->where('status', 'active')
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'")
            ->firstOrFail();

        $keywords = Keyword::query()
            ->where('library_id', (int) $p['keyword_library_id'])
            ->inRandomOrder()
            ->limit((int) config('geoflow.title_ai_keyword_sample_limit', 10))
            ->pluck('keyword')
            ->map(static fn ($x): string => trim((string) $x))
            ->filter(static fn (string $k): bool => $k !== '')
            ->values();
        if ($keywords->isEmpty()) {
            return ['ok' => false, 'error' => '关键词库为空，无法生成'];
        }
        $aiModel = AiModel::query()->whereKey((int) $p['ai_model_id'])->firstOrFail();
        $generationResult = $this->titleAiGenerationService->generateTitles(
            $aiModel,
            $keywords->all(),
            (int) $p['title_count'],
            (string) $p['title_style'],
            trim((string) ($p['custom_prompt'] ?? ''))
        );
        $generatedTitles = $generationResult['titles'];
        $saved = 0;
        $dup = 0;
        DB::transaction(function () use ($generatedTitles, $keywords, $libraryId, &$saved, &$dup): void {
            foreach ($generatedTitles as $titleText) {
                $title = trim((string) $titleText);
                if ($title === '' || mb_strlen($title, 'UTF-8') > 500) {
                    continue;
                }
                if (Title::query()->where('library_id', $libraryId)->where('title', $title)->exists()) {
                    $dup++;

                    continue;
                }
                Title::query()->create([
                    'library_id' => $libraryId,
                    'title' => $title,
                    'keyword' => (string) $keywords->random(),
                    'is_ai_generated' => true,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);
                $saved++;
            }
        });
        $this->refreshTitleLibraryCount($libraryId);

        return ['ok' => true, 'saved' => $saved, 'duplicates' => $dup];
    }

    // --- Image libraries ---

    /**
     * @return list<array<string, mixed>>
     */
    public function imageLibrariesList(): array
    {
        return ImageLibrary::query()
            ->select(['id', 'name', 'description', 'created_at', 'updated_at'])
            ->withCount('images as image_count')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (ImageLibrary $l): array => [
                'id' => (int) $l->id,
                'name' => (string) $l->name,
                'description' => (string) ($l->description ?? ''),
                'image_count' => (int) ($l->image_count ?? 0),
            ])
            ->all();
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function imageLibraryDetail(int $libraryId, int $page): array
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->first();
        if ($library === null) {
            return ['ok' => false, 'error' => '图片库不存在'];
        }
        $page = max(1, $page);
        $paginator = Image::query()->where('library_id', $libraryId)->orderByDesc('created_at')->paginate(24, ['*'], 'page', $page);

        return [
            'ok' => true,
            'data' => [
                'library' => ['id' => (int) $library->id, 'name' => (string) $library->name],
                'images' => collect($paginator->items())->map(static fn (Image $img): array => [
                    'id' => (int) $img->id,
                    'file_path' => (string) ($img->file_path ?? ''),
                    'original_name' => (string) ($img->original_name ?? ''),
                    'mime_type' => (string) ($img->mime_type ?? ''),
                    'created_at' => $img->created_at?->format('Y-m-d H:i:s'),
                ])->all(),
                'pagination' => [
                    'total' => $paginator->total(),
                    'current_page' => $paginator->currentPage(),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, library_id?: int, error?: string, validation_errors?: array<string, mixed>}
     */
    public function imageLibraryStore(array $payload): array
    {
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $row = ImageLibrary::query()->create([
            'name' => trim((string) $p['name']),
            'description' => trim((string) ($p['description'] ?? '')),
            'image_count' => 0,
            'used_task_count' => 0,
        ]);

        return ['ok' => true, 'library_id' => (int) $row->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function imageLibraryUpdate(int $libraryId, array $payload): array
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->first();
        if ($library === null) {
            return ['ok' => false, 'error' => '图片库不存在'];
        }
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $library->update([
            'name' => trim((string) $p['name']),
            'description' => trim((string) ($p['description'] ?? '')),
        ]);

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function imageLibraryDestroy(int $libraryId): array
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->first();
        if ($library === null) {
            return ['ok' => false, 'error' => '图片库不存在'];
        }
        if (Task::query()->where('image_library_id', $libraryId)->exists()) {
            return ['ok' => false, 'error' => '仍有任务引用该图片库，无法删除'];
        }
        Image::query()->where('library_id', $libraryId)->delete();
        $library->delete();

        return ['ok' => true];
    }

    /**
     * @param  list<int>  $imageIds
     * @return array{ok: bool, error?: string, deleted?: int}
     */
    public function imageDeleteBatch(int $libraryId, array $imageIds): array
    {
        ImageLibrary::query()->whereKey($libraryId)->firstOrFail();
        $ids = array_values(array_filter(array_map(static fn ($v): int => (int) $v, $imageIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ['ok' => false, 'error' => 'image_ids 不能为空'];
        }
        $deleted = Image::query()->where('library_id', $libraryId)->whereIn('id', $ids)->delete();

        return ['ok' => true, 'deleted' => (int) $deleted];
    }

    // --- Knowledge bases ---

    /**
     * @return list<array<string, mixed>>
     */
    public function knowledgeBasesList(): array
    {
        return KnowledgeBase::query()
            ->select(['id', 'name', 'description', 'created_at', 'updated_at'])
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (KnowledgeBase $k): array => [
                'id' => (int) $k->id,
                'name' => (string) $k->name,
                'description' => (string) ($k->description ?? ''),
            ])
            ->all();
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function knowledgeBaseDetail(int $knowledgeBaseId): array
    {
        $kb = KnowledgeBase::query()->whereKey($knowledgeBaseId)->first();
        if ($kb === null) {
            return ['ok' => false, 'error' => '知识库不存在'];
        }

        return [
            'ok' => true,
            'data' => [
                'id' => (int) $kb->id,
                'name' => (string) $kb->name,
                'description' => (string) ($kb->description ?? ''),
                'note' => '文件上传为 multipart，请使用后台知识库详情页；AI 运维仅支持元数据读写。',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, id?: int, error?: string, validation_errors?: array<string, mixed>}
     */
    public function knowledgeBaseStore(array $payload): array
    {
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $row = KnowledgeBase::query()->create([
            'name' => trim((string) $p['name']),
            'description' => trim((string) ($p['description'] ?? '')),
        ]);

        return ['ok' => true, 'id' => (int) $row->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function knowledgeBaseUpdate(int $knowledgeBaseId, array $payload): array
    {
        $kb = KnowledgeBase::query()->whereKey($knowledgeBaseId)->first();
        if ($kb === null) {
            return ['ok' => false, 'error' => '知识库不存在'];
        }
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $kb->update([
            'name' => trim((string) $p['name']),
            'description' => trim((string) ($p['description'] ?? '')),
        ]);

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function knowledgeBaseDestroy(int $knowledgeBaseId): array
    {
        $kb = KnowledgeBase::query()->whereKey($knowledgeBaseId)->first();
        if ($kb === null) {
            return ['ok' => false, 'error' => '知识库不存在'];
        }
        if (Task::query()->where('knowledge_base_id', $knowledgeBaseId)->exists()) {
            return ['ok' => false, 'error' => '仍有任务引用该知识库'];
        }
        $kb->delete();

        return ['ok' => true];
    }

    private function keywordUsageTotal(int $libraryId): int
    {
        if (! Schema::hasColumn('articles', 'original_keyword')) {
            return 0;
        }

        return (int) Article::query()
            ->whereIn('original_keyword', function ($query) use ($libraryId): void {
                $query->select('keyword')
                    ->from('keywords')
                    ->where('library_id', $libraryId);
            })
            ->count();
    }

    /**
     * @return Collection<int, string>
     */
    private function parseKeywordImportText(string $keywordsText): Collection
    {
        return collect(preg_split('/\R/u', $keywordsText) ?: [])
            ->flatMap(static function (string $line): array {
                return array_map('trim', explode(',', $line));
            })
            ->map(static fn (string $keyword): string => trim($keyword))
            ->filter(static fn (string $keyword): bool => $keyword !== '')
            ->unique()
            ->values();
    }

    private function refreshKeywordLibraryCount(int $libraryId): void
    {
        $count = Keyword::query()->where('library_id', $libraryId)->count();
        KeywordLibrary::query()->whereKey($libraryId)->update(['keyword_count' => $count]);
    }

    private function refreshTitleLibraryCount(int $libraryId): void
    {
        $count = Title::query()->where('library_id', $libraryId)->count();
        TitleLibrary::query()->whereKey($libraryId)->update(['title_count' => $count]);
    }
}
