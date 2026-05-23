<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AiOps\AdminOpsMirrorToolRunner;
use App\Support\AdminOpsMirrorRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * AI 运维：文章生成任务查询与 CRUD。
 */
final class AdminOpsTasksTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsTasksTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '管理文章生成任务：overview、form_options（创建前必调）、detail、create、update、toggle、delete、batch_start_stop。create 必填 task_name（勿用 name）、title_library_id、prompt_id、ai_model_id、status(active|paused)；选填 author_id、fixed_category_id（勿用 category_id）、article_limit 等。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $action = AdminOpsMirrorRequest::action($request);

        return match ($action) {
            'overview' => $this->runner->runRead('tasks_overview'),
            'form_options' => $this->runner->runRead('tasks_form_options'),
            'detail' => $this->runner->runRead('task_detail', [
                'task_id' => AdminOpsMirrorRequest::int($request, 'task_id'),
            ]),
            'create' => $this->runner->runWrite(self::TOOL_NAME, 'task_create', $this->taskBody($request)),
            'update' => $this->runner->runWrite(self::TOOL_NAME, 'task_update', [
                'task_id' => AdminOpsMirrorRequest::int($request, 'task_id'),
                'payload' => $this->taskBody($request),
            ]),
            'toggle' => $this->runner->runWrite(self::TOOL_NAME, 'task_toggle', [
                'task_id' => AdminOpsMirrorRequest::int($request, 'task_id'),
                'current_status' => AdminOpsMirrorRequest::string($request, 'current_status', 'paused'),
            ]),
            'delete' => $this->runner->runWrite(self::TOOL_NAME, 'task_delete', [
                'task_id' => AdminOpsMirrorRequest::int($request, 'task_id'),
            ]),
            'batch_start_stop' => $this->runner->runWrite(self::TOOL_NAME, 'task_batch_start_stop', [
                'task_id' => AdminOpsMirrorRequest::int($request, 'task_id'),
                'action' => AdminOpsMirrorRequest::string($request, 'batch_action'),
            ]),
            default => json_encode(['ok' => false, 'error' => 'action 无效。'], JSON_UNESCAPED_UNICODE) ?: '{}',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function taskBody(Request $request): array
    {
        $body = AdminOpsMirrorRequest::payloadJson($request);
        if ($body !== []) {
            return $body;
        }

        $keys = [
            'task_name', 'title_library_id', 'prompt_id', 'ai_model_id', 'status',
            'author_id', 'image_library_id', 'image_count', 'knowledge_base_id', 'fixed_category_id',
            'article_limit', 'draft_limit', 'publish_interval', 'category_mode', 'model_selection_mode',
            'need_review', 'is_loop', 'auto_keywords', 'auto_description',
        ];
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, AdminOpsMirrorRequest::data($request))) {
                $out[$key] = AdminOpsMirrorRequest::data($request)[$key];
            }
        }

        return $out;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('overview|form_options|detail|create|update|toggle|delete|batch_start_stop')->required(),
            'task_id' => $schema->integer(),
            'task_name' => $schema->string(),
            'title_library_id' => $schema->integer(),
            'prompt_id' => $schema->integer(),
            'ai_model_id' => $schema->integer(),
            'status' => $schema->string()->description('active 或 paused'),
            'current_status' => $schema->string()->description('toggle 时当前状态'),
            'batch_action' => $schema->string()->description('batch_start_stop: start|stop'),
            'payload_json' => $schema->string(),
        ];
    }
}
