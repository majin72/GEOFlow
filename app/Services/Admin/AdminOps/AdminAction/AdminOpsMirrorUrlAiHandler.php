<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps\AdminAction;

use App\Http\Controllers\Admin\AiModelController;
use App\Http\Controllers\Admin\UrlImportController;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * URL 智能采集与 AI 配置（模型、正文提示词、特殊提示词）：不含 API Token / 管理员账号 / 活动日志 / 改密。
 */
final class AdminOpsMirrorUrlAiHandler
{
    public function __construct(
        private readonly UrlImportProcessingService $urlImportProcessingService,
        private readonly ApiKeyCrypto $apiKeyCrypto,
    ) {}

    /**
     * URL 导入新建页同源统计。
     *
     * @return array<string, int>
     */
    public function urlImportIndexStats(): array
    {
        return [
            'knowledge_bases' => (int) KnowledgeBase::query()->count(),
            'keyword_libraries' => (int) KeywordLibrary::query()->count(),
            'title_libraries' => (int) TitleLibrary::query()->count(),
        ];
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function urlImportJobShow(int $jobId): array
    {
        $job = UrlImportJob::query()->whereKey($jobId)->first();
        if ($job === null) {
            return ['ok' => false, 'error' => '任务不存在'];
        }
        $job->load(['logs' => fn ($q) => $q->oldest()->limit(120)]);
        $result = [];
        $raw = (string) ($job->result_json ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $result = is_array($decoded) ? $decoded : [];
        }

        return [
            'ok' => true,
            'data' => [
                'job' => $job->toArray(),
                'result' => $result,
                'logs' => $job->logs->map(static fn ($l) => $l->toArray())->all(),
            ],
        ];
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>}
     */
    public function urlImportHistory(int $page): array
    {
        $page = max(1, $page);
        $paginator = UrlImportJob::query()->latest()->paginate(20, ['*'], 'page', $page);

        return [
            'ok' => true,
            'data' => [
                'items' => $paginator->items(),
                'stats' => [
                    'total' => UrlImportJob::query()->count(),
                    'completed' => UrlImportJob::query()->where('status', 'completed')->count(),
                    'running' => UrlImportJob::query()->whereIn('status', ['queued', 'running'])->count(),
                    'failed' => UrlImportJob::query()->where('status', 'failed')->count(),
                ],
                'pagination' => [
                    'total' => $paginator->total(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, job_id?: int, error?: string, validation_errors?: array<string, mixed>}
     */
    public function urlImportStore(array $payload): array
    {
        $v = Validator::make($payload, [
            'url' => ['required', 'string', 'max:2048'],
            'project_name' => ['nullable', 'string', 'max:120'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'content_language' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'outputs' => ['nullable', 'array'],
            'outputs.*' => ['string', 'in:knowledge,keywords,titles'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $validated = $v->validated();
        try {
            $normalized = $this->urlImportProcessingService->normalizeInputUrl((string) $validated['url']);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        try {
            $this->urlImportProcessingService->assertAnalysisModelReady();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '分析模型未就绪：'.$e->getMessage()];
        }
        $job = UrlImportJob::query()->create([
            'url' => $validated['url'],
            'normalized_url' => $normalized['url'],
            'source_domain' => $normalized['host'],
            'page_title' => $validated['project_name'] ?? '',
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
            'options_json' => json_encode([
                'project_name' => $validated['project_name'] ?? '',
                'source_label' => $validated['source_label'] ?? '',
                'content_language' => $validated['content_language'] ?? '',
                'notes' => $validated['notes'] ?? '',
                'outputs' => $validated['outputs'] ?? ['knowledge', 'keywords', 'titles'],
            ], JSON_UNESCAPED_UNICODE),
            'result_json' => '',
            'error_message' => '',
            'created_by' => Auth::guard('admin')->user()?->username ?? '',
        ]);
        UrlImportJobLog::query()->create([
            'job_id' => $job->id,
            'step' => 'queued',
            'level' => 'info',
            'message' => 'queued',
        ]);

        return ['ok' => true, 'job_id' => (int) $job->id];
    }

    /**
     * 委托 {@see UrlImportController::run} / status（JSON）。
     *
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    public function urlImportRun(int $jobId): array
    {
        try {
            $response = app(UrlImportController::class)->run($jobId);

            return ['ok' => true, 'data' => $response->getData(true)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    public function urlImportStatus(int $jobId): array
    {
        try {
            $response = app(UrlImportController::class)->status($jobId);

            return ['ok' => true, 'data' => $response->getData(true)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function urlImportCommit(int $jobId): array
    {
        $job = UrlImportJob::query()->whereKey($jobId)->first();
        if ($job === null) {
            return ['ok' => false, 'error' => '任务不存在'];
        }
        try {
            $summary = $this->urlImportProcessingService->commit($job);

            return ['ok' => true, 'data' => $summary];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, models?: list<array<string, mixed>>, error?: string}
     */
    public function aiModelsList(): array
    {
        try {
            $models = AiModel::query()
                ->select([
                    'id', 'name', 'version', 'api_key', 'model_id', 'model_type', 'api_url',
                    'failover_priority', 'daily_limit', 'used_today', 'total_used', 'status', 'created_at', 'updated_at',
                ])
                ->withCount('tasks as task_count')
                ->addSelect([
                    'article_count' => Article::query()
                        ->selectRaw('COUNT(articles.id)')
                        ->join('tasks', 'articles.task_id', '=', 'tasks.id')
                        ->whereColumn('tasks.ai_model_id', 'ai_models.id'),
                ])
                ->orderByDesc('created_at')
                ->get();
            $defaultEmbeddingModelId = $this->getDefaultEmbeddingModelId();
            $out = $models->map(function (AiModel $model) use ($defaultEmbeddingModelId): array {
                $modelType = $this->normalizeModelType((string) ($model->model_type ?? 'chat'));

                return [
                    'id' => (int) $model->id,
                    'name' => (string) $model->name,
                    'version' => (string) ($model->version ?? ''),
                    'model_id' => (string) $model->model_id,
                    'model_type' => $modelType,
                    'api_url' => (string) ($model->api_url ?? ''),
                    'failover_priority' => (int) ($model->failover_priority ?? 100),
                    'daily_limit' => (int) ($model->daily_limit ?? 0),
                    'used_today' => (int) ($model->used_today ?? 0),
                    'total_used' => (int) ($model->total_used ?? 0),
                    'status' => (string) ($model->status ?? 'active'),
                    'task_count' => (int) ($model->task_count ?? 0),
                    'article_count' => (int) ($model->article_count ?? 0),
                    'masked_api_key' => $this->apiKeyCrypto->mask((string) ($model->getRawOriginal('api_key') ?? '')),
                    'is_default_embedding' => $modelType === 'embedding' && $defaultEmbeddingModelId === (int) $model->id,
                ];
            })->all();

            return ['ok' => true, 'models' => $out];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function aiModelStore(array $payload): array
    {
        $v = $this->validateModelPayload($payload, false);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $apiKey = trim((string) ($p['api_key'] ?? ''));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => '创建时 api_key 必填'];
        }
        try {
            $encrypted = $this->apiKeyCrypto->encrypt($apiKey);
        } catch (\RuntimeException) {
            return ['ok' => false, 'error' => '密钥加密失败（APP_KEY 等配置）'];
        }
        $modelType = $this->normalizeModelType((string) ($p['model_type'] ?? 'chat'));
        $created = AiModel::query()->create([
            'name' => trim((string) $p['name']),
            'version' => trim((string) ($p['version'] ?? '')),
            'api_key' => $encrypted,
            'model_id' => trim((string) $p['model_id']),
            'model_type' => $modelType,
            'api_url' => trim((string) ($p['api_url'] ?? '')),
            'failover_priority' => max(1, (int) ($p['failover_priority'] ?? 100)),
            'daily_limit' => max(0, (int) ($p['daily_limit'] ?? 0)),
            'status' => 'active',
        ]);
        if ($modelType === 'embedding' && $this->getDefaultEmbeddingModelId() <= 0) {
            $this->setDefaultEmbeddingModelId((int) $created->id);
        }

        return ['ok' => true, 'model_id' => (int) $created->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function aiModelUpdate(int $modelId, array $payload): array
    {
        $model = AiModel::query()->whereKey($modelId)->first();
        if ($model === null) {
            return ['ok' => false, 'error' => '模型不存在'];
        }
        $v = $this->validateModelPayload($payload, true);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $modelType = $this->normalizeModelType((string) ($p['model_type'] ?? 'chat'));
        $status = (string) ($p['status'] ?? 'active');
        if (! in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        $update = [
            'name' => trim((string) $p['name']),
            'version' => trim((string) ($p['version'] ?? '')),
            'model_id' => trim((string) $p['model_id']),
            'model_type' => $modelType,
            'api_url' => trim((string) ($p['api_url'] ?? '')),
            'failover_priority' => max(1, (int) ($p['failover_priority'] ?? 100)),
            'daily_limit' => max(0, (int) ($p['daily_limit'] ?? 0)),
            'status' => $status,
        ];
        $apiKey = trim((string) ($p['api_key'] ?? ''));
        if ($apiKey !== '') {
            try {
                $update['api_key'] = $this->apiKeyCrypto->encrypt($apiKey);
            } catch (\RuntimeException) {
                return ['ok' => false, 'error' => '密钥加密失败'];
            }
        }
        $model->update($update);
        if ($this->getDefaultEmbeddingModelId() === (int) $model->id && ($modelType !== 'embedding' || $status !== 'active')) {
            $this->setDefaultEmbeddingModelId(0);
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function aiModelDestroy(int $modelId): array
    {
        $model = AiModel::query()->whereKey($modelId)->first();
        if ($model === null) {
            return ['ok' => false, 'error' => '模型不存在'];
        }
        $taskCount = $model->tasks()->count();
        if ($taskCount > 0) {
            return ['ok' => false, 'error' => '模型仍被 '.$taskCount.' 个任务引用，无法删除'];
        }
        $model->delete();
        if ($this->getDefaultEmbeddingModelId() === (int) $model->id) {
            $this->setDefaultEmbeddingModelId(0);
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    public function aiModelTest(int $modelId): array
    {
        try {
            $json = app(AiModelController::class)->testConnection($modelId)->getData(true);

            return ['ok' => true, 'data' => $json];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, prompts?: list<array<string, mixed>>, error?: string}
     */
    public function aiPromptsList(): array
    {
        $rows = Prompt::query()
            ->select(['id', 'name', 'type', 'content', 'created_at'])
            ->where('type', 'content')
            ->withCount('tasks')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (Prompt $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'content' => (string) $p->content,
                'task_count' => (int) ($p->tasks_count ?? 0),
                'created_at' => $p->created_at?->format('Y-m-d H:i:s'),
            ])
            ->all();

        return ['ok' => true, 'prompts' => $rows];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, prompt_id?: int, error?: string, validation_errors?: array<string, mixed>}
     */
    public function aiPromptStore(array $payload): array
    {
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $row = Prompt::query()->create([
            'name' => trim((string) $p['name']),
            'type' => 'content',
            'content' => trim((string) $p['content']),
            'variables' => '',
        ]);

        return ['ok' => true, 'prompt_id' => (int) $row->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function aiPromptUpdate(int $promptId, array $payload): array
    {
        $prompt = Prompt::query()->whereKey($promptId)->where('type', 'content')->first();
        if ($prompt === null) {
            return ['ok' => false, 'error' => '提示词不存在'];
        }
        $v = Validator::make($payload, [
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string'],
        ]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $p = $v->validated();
        $prompt->update([
            'name' => trim((string) $p['name']),
            'content' => trim((string) $p['content']),
        ]);

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function aiPromptDestroy(int $promptId): array
    {
        $prompt = Prompt::query()->whereKey($promptId)->where('type', 'content')->first();
        if ($prompt === null) {
            return ['ok' => false, 'error' => '提示词不存在'];
        }
        $usage = Task::query()->where('prompt_id', $promptId)->count();
        if ($usage > 0) {
            return ['ok' => false, 'error' => '提示词仍被 '.$usage.' 个任务引用'];
        }
        $prompt->delete();

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, data?: array{keyword:string,description:string}}
     */
    public function aiSpecialPromptsRead(): array
    {
        $kw = Prompt::query()->where('type', 'keyword')->orderByDesc('updated_at')->orderByDesc('id')->value('content');
        $desc = Prompt::query()->where('type', 'description')->orderByDesc('updated_at')->orderByDesc('id')->value('content');

        return [
            'ok' => true,
            'data' => [
                'keyword' => (string) ($kw ?? ''),
                'description' => (string) ($desc ?? ''),
            ],
        ];
    }

    /**
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function aiSpecialPromptUpdateKeyword(string $content): array
    {
        $v = Validator::make(['keyword_content' => $content], ['keyword_content' => ['required', 'string']]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $this->upsertPromptByType('keyword', trim($content), '关键词生成提示词');

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string, validation_errors?: array<string, mixed>}
     */
    public function aiSpecialPromptUpdateDescription(string $content): array
    {
        $v = Validator::make(['description_content' => $content], ['description_content' => ['required', 'string']]);
        if ($v->fails()) {
            return ['ok' => false, 'error' => '校验未通过', 'validation_errors' => $v->errors()->toArray()];
        }
        $this->upsertPromptByType('description', trim($content), '文章描述生成提示词');

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateModelPayload(array $data, bool $isUpdate): \Illuminate\Validation\Validator
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'version' => ['nullable', 'string', 'max:50'],
            'api_key' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:500'],
            'model_id' => ['required', 'string', 'max:100'],
            'model_type' => ['required', 'in:chat,embedding'],
            'api_url' => ['nullable', 'string', 'max:500'],
            'failover_priority' => ['nullable', 'integer', 'min:1'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
        ];
        if ($isUpdate) {
            $rules['status'] = ['nullable', 'in:active,inactive'];
        }

        return Validator::make($data, $rules);
    }

    private function normalizeModelType(string $modelType): string
    {
        $normalized = trim(strtolower($modelType));

        return in_array($normalized, ['chat', 'embedding'], true) ? $normalized : 'chat';
    }

    private function getDefaultEmbeddingModelId(): int
    {
        return (int) (SiteSetting::query()
            ->where('setting_key', 'default_embedding_model_id')
            ->value('setting_value') ?? 0);
    }

    private function setDefaultEmbeddingModelId(int $modelId): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'default_embedding_model_id'],
            ['setting_value' => (string) max(0, $modelId)]
        );
    }

    private function upsertPromptByType(string $type, string $content, string $fallbackName): void
    {
        $content = trim($content);
        $exists = Prompt::query()->where('type', $type)->exists();
        if ($exists) {
            Prompt::query()->where('type', $type)->update([
                'content' => $content,
                'updated_at' => now(),
            ]);

            return;
        }
        Prompt::query()->create([
            'name' => $fallbackName,
            'type' => $type,
            'content' => $content,
            'variables' => '',
        ]);
    }
}
