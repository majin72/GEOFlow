<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps\AdminAction;

use App\Http\Controllers\Admin\TaskController;
use App\Models\AiModel;
use App\Models\Author;
use App\Models\Category;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * 任务监控与生命周期（对齐 {@see TaskController} 与队列 JSON 接口）。
 */
final class AdminOpsMirrorTasksHandler
{
    public function __construct(
        private readonly TaskLifecycleService $taskLifecycleService,
        private readonly TaskMonitoringQueryService $taskMonitoringQueryService,
    ) {}

    /**
     * 后台任务页同源概览（任务列表、队列、worker、近期 runs）。
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        try {
            return $this->taskMonitoringQueryService->buildAdminOverview();
        } catch (Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'tasks' => [],
                'worker_overview' => [],
                'queue_overview' => ['pending' => 0, 'running' => 0, 'failed' => 0, 'completed' => 0],
                'recent_runs' => [],
            ];
        }
    }

    /**
     * 单任务详情（服务层结构）。
     *
     * @return array<string, mixed>
     */
    public function taskDetail(int $taskId): array
    {
        try {
            return $this->taskLifecycleService->getTask($taskId);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 创建/编辑任务表单所需下拉数据（与 TaskController::loadTaskFormOptions 同源）。
     *
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        $titleLibraries = TitleLibrary::query()
            ->select(['id', 'name'])
            ->selectRaw('(SELECT COUNT(*) FROM titles WHERE titles.library_id = title_libraries.id) AS title_count')
            ->orderByDesc('id')
            ->get()
            ->map(static function (TitleLibrary $row): array {
                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'count' => (int) ($row->title_count ?? 0),
                ];
            })
            ->all();

        $prompts = Prompt::query()
            ->select(['id', 'name'])
            ->where('type', 'content')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Prompt $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $aiModels = AiModel::query()
            ->select(['id', 'name'])
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AiModel $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $imageLibraries = ImageLibrary::query()
            ->select(['id', 'name'])
            ->selectRaw('(SELECT COUNT(*) FROM images WHERE images.library_id = image_libraries.id) AS image_count')
            ->orderBy('name')
            ->get()
            ->map(static function (ImageLibrary $row): array {
                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'count' => (int) ($row->image_count ?? 0),
                ];
            })
            ->all();

        $knowledgeBases = KnowledgeBase::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(static fn (KnowledgeBase $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $authors = Author::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(static fn (Author $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $categories = Category::query()
            ->select(['id', 'name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Category $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        return [
            'titleLibraries' => $titleLibraries,
            'prompts' => $prompts,
            'aiModels' => $aiModels,
            'imageLibraries' => $imageLibraries,
            'knowledgeBases' => $knowledgeBases,
            'authors' => $authors,
            'categories' => $categories,
        ];
    }

    /**
     * 启停任务：current_status=active 则停止，否则启动。
     *
     * @return array{ok: bool, error?: string, data?: mixed}
     */
    public function toggle(int $taskId, string $currentStatus): array
    {
        if ($taskId <= 0) {
            return ['ok' => false, 'error' => '无效 task_id'];
        }
        try {
            if ($currentStatus === 'active') {
                $this->taskLifecycleService->stopTask($taskId);

                return ['ok' => true, 'data' => ['action' => 'stopped']];
            }
            $this->taskLifecycleService->startTask($taskId, false);

            return ['ok' => true, 'data' => ['action' => 'started']];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 删除任务。
     *
     * @return array{ok: bool, error?: string}
     */
    public function delete(int $taskId): array
    {
        if ($taskId <= 0) {
            return ['ok' => false, 'error' => '无效 task_id'];
        }
        try {
            $this->taskLifecycleService->deleteTask($taskId);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 批量 JSON 接口同源：start|stop。
     *
     * @return array{ok: bool, error?: string, data?: mixed}
     */
    public function batchStartStop(int $taskId, string $action): array
    {
        $v = Validator::make(['task_id' => $taskId, 'action' => $action], [
            'task_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string', 'in:start,stop'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'data' => $v->errors()->toArray()];
        }
        try {
            $result = $action === 'start'
                ? $this->taskLifecycleService->startTask($taskId, true)
                : $this->taskLifecycleService->stopTask($taskId);

            return ['ok' => true, 'data' => $result];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 创建任务（字段与 TaskController::validateTaskForm / buildTaskPayload 一致）。
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function create(array $payload): array
    {
        if (! Category::query()->exists()) {
            return ['ok' => false, 'error' => '尚未配置任何栏目，请先创建栏目。'];
        }
        $validated = $this->validateTaskPayload($payload);
        if ($validated === null) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $this->lastTaskValidationErrors];
        }
        try {
            $this->taskLifecycleService->createTask($validated);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 更新任务。
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function update(int $taskId, array $payload): array
    {
        if (! Category::query()->exists()) {
            return ['ok' => false, 'error' => '尚未配置任何栏目，请先创建栏目。'];
        }
        $validated = $this->validateTaskPayload($payload);
        if ($validated === null) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $this->lastTaskValidationErrors];
        }
        try {
            $this->taskLifecycleService->updateTask($taskId, $validated);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @var array<string, mixed> */
    private array $lastTaskValidationErrors = [];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int|string|null>|null
     */
    private function validateTaskPayload(array $payload): ?array
    {
        $v = Validator::make($payload, [
            'task_name' => ['required', 'string', 'max:200'],
            'title_library_id' => ['required', 'integer', 'min:1'],
            'prompt_id' => ['required', 'integer', 'min:1'],
            'ai_model_id' => ['required', 'integer', 'min:1'],
            'author_id' => ['nullable', 'integer', 'min:0'],
            'image_library_id' => ['nullable', 'integer', 'min:1'],
            'image_count' => ['nullable', 'integer', 'min:0', 'max:5'],
            'knowledge_base_id' => ['nullable', 'integer', 'min:1'],
            'fixed_category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:active,paused'],
            'article_limit' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'draft_limit' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'publish_interval' => ['nullable', 'integer', 'min:1'],
            'category_mode' => ['nullable', 'string', 'in:smart,fixed,random'],
            'model_selection_mode' => ['nullable', 'string', 'in:fixed,smart_failover'],
            'need_review' => ['nullable'],
            'is_loop' => ['nullable'],
            'auto_keywords' => ['nullable'],
            'auto_description' => ['nullable'],
        ]);
        if ($v->fails()) {
            $this->lastTaskValidationErrors = $v->errors()->toArray();

            return null;
        }
        $p = $v->validated();
        $categoryMode = (string) ($p['category_mode'] ?? 'smart');
        if ($categoryMode === 'random') {
            $categoryMode = 'smart';
        }
        $needReview = $this->parseOptionalFlag($p, 'need_review', false);
        $isLoop = $this->parseOptionalFlag($p, 'is_loop', true);
        $autoKeywords = $this->parseOptionalFlag($p, 'auto_keywords', true);
        $autoDescription = $this->parseOptionalFlag($p, 'auto_description', true);

        return [
            'name' => (string) $p['task_name'],
            'title_library_id' => (int) $p['title_library_id'],
            'image_library_id' => isset($p['image_library_id']) ? (int) $p['image_library_id'] : null,
            'image_count' => (int) ($p['image_count'] ?? 0),
            'prompt_id' => (int) $p['prompt_id'],
            'ai_model_id' => (int) $p['ai_model_id'],
            'author_id' => isset($p['author_id']) && (int) $p['author_id'] > 0 ? (int) $p['author_id'] : null,
            'knowledge_base_id' => isset($p['knowledge_base_id']) ? (int) $p['knowledge_base_id'] : null,
            'fixed_category_id' => isset($p['fixed_category_id']) ? (int) $p['fixed_category_id'] : null,
            'status' => (string) $p['status'],
            'article_limit' => (int) ($p['article_limit'] ?? 10),
            'draft_limit' => (int) ($p['draft_limit'] ?? 10),
            'publish_interval' => max(1, (int) ($p['publish_interval'] ?? 60)) * 60,
            'need_review' => $needReview ? 1 : 0,
            'is_loop' => $isLoop ? 1 : 0,
            'category_mode' => $categoryMode,
            'model_selection_mode' => (string) ($p['model_selection_mode'] ?? 'fixed'),
            'auto_keywords' => $autoKeywords ? 1 : 0,
            'auto_description' => $autoDescription ? 1 : 0,
        ];
    }

    /**
     * 解析 payload 中可选布尔字段（未传时用 $defaultWhenMissing，与后台创建页默认勾选一致）。
     *
     * @param  array<string, mixed>  $validated
     */
    private function parseOptionalFlag(array $validated, string $key, bool $defaultWhenMissing): bool
    {
        if (! array_key_exists($key, $validated)) {
            return $defaultWhenMissing;
        }

        $value = $validated[$key];

        return filter_var($value, FILTER_VALIDATE_BOOLEAN)
            || $value === '1'
            || $value === 1;
    }
}
