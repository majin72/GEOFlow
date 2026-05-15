<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps;

use App\Ai\Tools\AdminOpsAdminActionTool;
use App\Models\Category;
use App\Services\Admin\AdminOps\AdminAction\AdminOpsMirrorArticlesHandler;
use App\Services\Admin\AdminOps\AdminAction\AdminOpsMirrorAuthorsHandler;
use App\Services\Admin\AdminOps\AdminAction\AdminOpsMirrorDashboardHandler;
use App\Services\Admin\AdminOps\AdminAction\AdminOpsMirrorLibrariesHandler;
use App\Services\Admin\AdminOps\AdminAction\AdminOpsMirrorSensitiveWordsHandler;
use App\Services\Admin\AdminOps\AdminAction\AdminOpsMirrorTasksHandler;
use App\Services\Admin\AdminOps\AdminAction\AdminOpsMirrorUrlAiHandler;
use InvalidArgumentException;

/**
 * 后台 Blade 路由能力的统一入口（供 {@see AdminOpsAdminActionTool} 调用）。
 *
 * 明确排除：管理员账号、API Token、活动日志、当前账号改密（不实现、不暴露 op）。
 */
final class AdminOpsAdminActionService
{
    public function __construct(
        private readonly AdminOpsMirrorDashboardHandler $dashboard,
        private readonly AdminOpsMirrorSensitiveWordsHandler $sensitiveWords,
        private readonly AdminOpsMirrorTasksHandler $tasks,
        private readonly AdminOpsMirrorArticlesHandler $articles,
        private readonly AdminOpsMirrorAuthorsHandler $authors,
        private readonly AdminOpsCategoryReadService $categoryRead,
        private readonly AdminOpsCategoryWriteService $categoryWrite,
        private readonly AdminOpsMirrorLibrariesHandler $libraries,
        private readonly AdminOpsMirrorUrlAiHandler $urlAi,
    ) {}

    /**
     * 执行只读或写入操作。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(string $kind, string $op, array $payload): array
    {
        $kind = strtolower(trim($kind));
        $op = strtolower(trim($op));
        if (! in_array($kind, ['read', 'write'], true)) {
            return ['ok' => false, 'error' => 'kind 必须为 read 或 write'];
        }
        $this->assertOpAllowed($op);

        try {
            return $kind === 'read'
                ? $this->executeRead($op, $payload)
                : $this->executeWrite($op, $payload);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function executeRead(string $op, array $p): array
    {
        return match ($op) {
            'dashboard_summary' => [
                'ok' => true,
                'stats' => $this->dashboard->dashboardSummary(),
                'today_week' => $this->dashboard->dashboardTodayWeek(),
                'category_distribution_top10' => $this->dashboard->dashboardCategoryDistributionTop10(),
            ],
            'materials_stats' => ['ok' => true, 'stats' => $this->dashboard->materialsStats()],
            'legacy_ai_configurator' => ['ok' => true, 'info' => $this->dashboard->legacyAiConfiguratorInfo()],
            'sensitive_words_list' => ['ok' => true, 'words' => $this->sensitiveWords->listWords()],
            'tasks_overview' => ['ok' => true, 'overview' => $this->tasks->overview()],
            'tasks_form_options' => ['ok' => true, 'options' => $this->tasks->formOptions()],
            'task_detail' => ['ok' => true, 'task' => $this->tasks->taskDetail((int) ($p['task_id'] ?? 0))],
            'categories_list' => ['ok' => true, 'categories' => $this->categoryRead->listCategoriesWithArticleCounts()],
            'articles_list' => $this->articles->list((array) ($p['filters'] ?? $p)),
            'article_detail' => $this->articles->show((int) ($p['article_id'] ?? 0), (bool) ($p['only_trashed'] ?? false)),
            'authors_list' => $this->authors->list(trim((string) ($p['search'] ?? '')), (int) ($p['page'] ?? 1)),
            'author_detail' => $this->authors->detail((int) ($p['author_id'] ?? 0)),
            'keyword_libraries_list' => ['ok' => true, 'libraries' => $this->libraries->keywordLibrariesList()],
            'keyword_library_detail' => $this->libraries->keywordLibraryDetail(
                (int) ($p['library_id'] ?? 0),
                trim((string) ($p['search'] ?? '')),
                (int) ($p['page'] ?? 1)
            ),
            'title_libraries_list' => ['ok' => true, 'libraries' => $this->libraries->titleLibrariesList()],
            'title_library_detail' => $this->libraries->titleLibraryDetail((int) ($p['library_id'] ?? 0), (int) ($p['page'] ?? 1)),
            'image_libraries_list' => ['ok' => true, 'libraries' => $this->libraries->imageLibrariesList()],
            'image_library_detail' => $this->libraries->imageLibraryDetail((int) ($p['library_id'] ?? 0), (int) ($p['page'] ?? 1)),
            'knowledge_bases_list' => ['ok' => true, 'items' => $this->libraries->knowledgeBasesList()],
            'knowledge_base_detail' => $this->libraries->knowledgeBaseDetail((int) ($p['knowledge_base_id'] ?? 0)),
            'url_import_index_stats' => ['ok' => true, 'stats' => $this->urlAi->urlImportIndexStats()],
            'url_import_job_show' => $this->urlAi->urlImportJobShow((int) ($p['job_id'] ?? 0)),
            'url_import_history' => $this->urlAi->urlImportHistory((int) ($p['page'] ?? 1)),
            'url_import_status' => $this->urlAi->urlImportStatus((int) ($p['job_id'] ?? 0)),
            'ai_models_list' => $this->urlAi->aiModelsList(),
            'ai_prompts_list' => $this->urlAi->aiPromptsList(),
            'ai_special_prompts_read' => $this->urlAi->aiSpecialPromptsRead(),
            default => throw new InvalidArgumentException('未知 read 操作：'.$op.'。请查看 AdminOpsAdminActionTool 描述中的操作清单。'),
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function executeWrite(string $op, array $p): array
    {
        return match ($op) {
            'sensitive_words_add' => $this->mergeOk($this->sensitiveWords->addWords((string) ($p['words'] ?? ''))),
            'sensitive_words_delete' => $this->mergeOk($this->sensitiveWords->deleteWord((int) ($p['word_id'] ?? 0))),
            'category_create' => $this->mergeOk($this->categoryWrite->store((array) ($p['payload'] ?? $p))),
            'category_update' => $this->mergeOk($this->categoryWrite->update(
                $this->resolveCategoryIdForOps($p),
                $this->categoryWritePayloadStripped($p),
            )),
            'category_delete' => $this->mergeOk($this->categoryWrite->destroy($this->resolveCategoryIdForOps($p))),
            'author_create' => $this->mergeOk($this->authors->create((array) ($p['payload'] ?? $p))),
            'author_update' => $this->mergeOk($this->authors->update((int) ($p['author_id'] ?? 0), (array) ($p['payload'] ?? $p))),
            'author_delete' => $this->mergeOk($this->authors->delete((int) ($p['author_id'] ?? 0))),
            'task_toggle' => $this->mergeOk($this->tasks->toggle((int) ($p['task_id'] ?? 0), (string) ($p['current_status'] ?? 'paused'))),
            'task_delete' => $this->mergeOk($this->tasks->delete((int) ($p['task_id'] ?? 0))),
            'task_batch_start_stop' => $this->mergeOk($this->tasks->batchStartStop((int) ($p['task_id'] ?? 0), (string) ($p['action'] ?? ''))),
            'task_create' => $this->mergeOk($this->tasks->create((array) ($p['payload'] ?? $p))),
            'task_update' => $this->mergeOk($this->tasks->update((int) ($p['task_id'] ?? 0), (array) ($p['payload'] ?? $p))),
            'articles_batch_status' => $this->mergeOk($this->articles->batchUpdateStatus(
                $this->intList($p['article_ids'] ?? []),
                (string) ($p['new_status'] ?? '')
            )),
            'articles_batch_review' => $this->mergeOk($this->articles->batchUpdateReview(
                $this->intList($p['article_ids'] ?? []),
                (string) ($p['review_status'] ?? '')
            )),
            'articles_batch_soft_delete' => $this->mergeOk($this->articles->batchSoftDelete($this->intList($p['article_ids'] ?? []))),
            'articles_batch_restore' => $this->mergeOk($this->articles->batchRestore($this->intList($p['article_ids'] ?? []))),
            'articles_batch_force_delete' => $this->mergeOk($this->articles->batchForceDelete($this->intList($p['article_ids'] ?? []))),
            'articles_trash_empty' => $this->mergeOk($this->articles->emptyTrash()),
            'article_restore' => $this->mergeOk($this->articles->restoreOne((int) ($p['article_id'] ?? 0))),
            'article_force_delete' => $this->mergeOk($this->articles->forceDeleteOne((int) ($p['article_id'] ?? 0))),
            'article_create' => $this->mergeOk($this->articles->store((array) ($p['payload'] ?? $p))),
            'article_update' => $this->mergeOk($this->articles->update((int) ($p['article_id'] ?? 0), (array) ($p['payload'] ?? $p))),
            'keyword_library_create' => $this->mergeOk($this->libraries->keywordLibraryStore((array) ($p['payload'] ?? $p))),
            'keyword_library_update' => $this->mergeOk($this->libraries->keywordLibraryUpdate((int) ($p['library_id'] ?? 0), (array) ($p['payload'] ?? $p))),
            'keyword_library_delete' => $this->mergeOk($this->libraries->keywordLibraryDestroy((int) ($p['library_id'] ?? 0))),
            'keyword_add' => $this->mergeOk($this->libraries->keywordAdd((int) ($p['library_id'] ?? 0), (string) ($p['keyword'] ?? ''))),
            'keyword_delete_batch' => $this->mergeOk($this->libraries->keywordDeleteBatch((int) ($p['library_id'] ?? 0), $this->intList($p['keyword_ids'] ?? []))),
            'keyword_import' => $this->mergeOk($this->libraries->keywordImport((int) ($p['library_id'] ?? 0), (string) ($p['keywords_text'] ?? ''))),
            'title_library_create' => $this->mergeOk($this->libraries->titleLibraryStore((array) ($p['payload'] ?? $p))),
            'title_library_update' => $this->mergeOk($this->libraries->titleLibraryUpdate((int) ($p['library_id'] ?? 0), (array) ($p['payload'] ?? $p))),
            'title_library_delete' => $this->mergeOk($this->libraries->titleLibraryDestroy((int) ($p['library_id'] ?? 0))),
            'title_add' => $this->mergeOk($this->libraries->titleAdd((int) ($p['library_id'] ?? 0), (string) ($p['title'] ?? ''))),
            'title_delete_batch' => $this->mergeOk($this->libraries->titleDeleteBatch((int) ($p['library_id'] ?? 0), $this->intList($p['title_ids'] ?? []))),
            'title_library_ai_generate' => $this->mergeOk($this->libraries->titleLibraryAiGenerate((int) ($p['library_id'] ?? 0), (array) ($p['payload'] ?? $p))),
            'image_library_create' => $this->mergeOk($this->libraries->imageLibraryStore((array) ($p['payload'] ?? $p))),
            'image_library_update' => $this->mergeOk($this->libraries->imageLibraryUpdate((int) ($p['library_id'] ?? 0), (array) ($p['payload'] ?? $p))),
            'image_library_delete' => $this->mergeOk($this->libraries->imageLibraryDestroy((int) ($p['library_id'] ?? 0))),
            'image_delete_batch' => $this->mergeOk($this->libraries->imageDeleteBatch((int) ($p['library_id'] ?? 0), $this->intList($p['image_ids'] ?? []))),
            'knowledge_base_create' => $this->mergeOk($this->libraries->knowledgeBaseStore((array) ($p['payload'] ?? $p))),
            'knowledge_base_update' => $this->mergeOk($this->libraries->knowledgeBaseUpdate((int) ($p['knowledge_base_id'] ?? 0), (array) ($p['payload'] ?? $p))),
            'knowledge_base_delete' => $this->mergeOk($this->libraries->knowledgeBaseDestroy((int) ($p['knowledge_base_id'] ?? 0))),
            'url_import_create' => $this->mergeOk($this->urlAi->urlImportStore((array) ($p['payload'] ?? $p))),
            'url_import_run' => $this->mergeOk($this->urlAi->urlImportRun((int) ($p['job_id'] ?? 0))),
            'url_import_commit' => $this->mergeOk($this->urlAi->urlImportCommit((int) ($p['job_id'] ?? 0))),
            'ai_model_create' => $this->mergeOk($this->urlAi->aiModelStore((array) ($p['payload'] ?? $p))),
            'ai_model_update' => $this->mergeOk($this->urlAi->aiModelUpdate((int) ($p['model_id'] ?? 0), (array) ($p['payload'] ?? $p))),
            'ai_model_delete' => $this->mergeOk($this->urlAi->aiModelDestroy((int) ($p['model_id'] ?? 0))),
            'ai_model_test' => $this->mergeOk($this->urlAi->aiModelTest((int) ($p['model_id'] ?? 0))),
            'ai_prompt_create' => $this->mergeOk($this->urlAi->aiPromptStore((array) ($p['payload'] ?? $p))),
            'ai_prompt_update' => $this->mergeOk($this->urlAi->aiPromptUpdate((int) ($p['prompt_id'] ?? 0), (array) ($p['payload'] ?? $p))),
            'ai_prompt_delete' => $this->mergeOk($this->urlAi->aiPromptDestroy((int) ($p['prompt_id'] ?? 0))),
            'ai_special_prompt_keyword' => $this->mergeOk($this->urlAi->aiSpecialPromptUpdateKeyword((string) ($p['content'] ?? ''))),
            'ai_special_prompt_description' => $this->mergeOk($this->urlAi->aiSpecialPromptUpdateDescription((string) ($p['content'] ?? ''))),
            default => throw new InvalidArgumentException('未知 write 操作：'.$op),
        };
    }

    private function assertOpAllowed(string $op): void
    {
        $blocked = ['admin_user', 'api_token', 'activity_log', 'security_password', 'password_update'];
        foreach ($blocked as $b) {
            if (str_contains($op, $b)) {
                throw new InvalidArgumentException('该操作不在 AI 运维授权范围内。');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function mergeOk(array $r): array
    {
        if (! array_key_exists('ok', $r)) {
            $r['ok'] = true;
        }

        return $r;
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($v): int => (int) $v, $raw), static fn (int $id): bool => $id > 0));
    }

    /**
     * 解析栏目主键：兼容 LLM 误传 id、把 id 放进 payload、或仅用 slug/name 定位（与 category_id 等价）。
     *
     * @param  array<string, mixed>  $p
     */
    private function resolveCategoryIdForOps(array $p): int
    {
        $nested = is_array($p['payload'] ?? null) ? $p['payload'] : [];
        $candidates = [
            (int) ($p['category_id'] ?? 0),
            (int) ($p['id'] ?? 0),
            (int) ($nested['category_id'] ?? 0),
            (int) ($nested['id'] ?? 0),
        ];
        foreach ($candidates as $id) {
            if ($id > 0) {
                return $id;
            }
        }

        $slug = trim((string) ($p['slug'] ?? $p['category_slug'] ?? ''));
        if ($slug === '' && is_array($nested)) {
            $slug = trim((string) ($nested['slug'] ?? $nested['category_slug'] ?? ''));
        }
        if ($slug !== '') {
            $found = Category::query()->where('slug', $slug)->value('id');

            return $found ? (int) $found : 0;
        }

        $nameLookup = array_values(array_filter([
            trim((string) ($p['category_name'] ?? '')),
            is_array($nested) ? trim((string) ($nested['category_name'] ?? '')) : '',
            is_array($nested) ? trim((string) ($nested['name'] ?? '')) : '',
            trim((string) ($p['name'] ?? '')),
        ], static fn (string $s): bool => $s !== ''));
        foreach ($nameLookup as $nm) {
            $found = Category::query()->where('name', $nm)->orderBy('id')->value('id');
            if ($found) {
                return (int) $found;
            }
        }

        return 0;
    }

    /**
     * 栏目写入校验用的 body：去掉仅用于定位的字段，避免与 Category 校验规则冲突。
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function categoryWritePayloadStripped(array $p): array
    {
        $body = is_array($p['payload'] ?? null) ? $p['payload'] : $p;
        if (! is_array($body)) {
            return [];
        }
        $body = [...$body];
        foreach (['category_id', 'id', 'slug', 'category_slug', 'category_name', 'payload', 'kind', 'op'] as $k) {
            unset($body[$k]);
        }

        return $body;
    }
}
