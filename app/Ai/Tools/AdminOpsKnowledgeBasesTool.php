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
 * AI 运维：知识库 CRUD（文件上传仅后台页面）。
 */
final class AdminOpsKnowledgeBasesTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsKnowledgeBasesTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '知识库：list、detail、knowledge_base_create|update|delete。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $action = AdminOpsMirrorRequest::action($request);
        $kbId = AdminOpsMirrorRequest::int($request, 'knowledge_base_id');
        $body = AdminOpsMirrorRequest::payloadJson($request);

        return match ($action) {
            'list' => $this->runner->runRead('knowledge_bases_list'),
            'detail' => $this->runner->runRead('knowledge_base_detail', ['knowledge_base_id' => $kbId]),
            'create' => $this->runner->runWrite(self::TOOL_NAME, 'knowledge_base_create', $body ?: AdminOpsMirrorRequest::data($request)),
            'update' => $this->runner->runWrite(self::TOOL_NAME, 'knowledge_base_update', [
                'knowledge_base_id' => $kbId,
                'payload' => $body ?: AdminOpsMirrorRequest::data($request),
            ]),
            'delete' => $this->runner->runWrite(self::TOOL_NAME, 'knowledge_base_delete', ['knowledge_base_id' => $kbId]),
            default => json_encode(['ok' => false, 'error' => 'action 须为 list|detail|create|update|delete。'], JSON_UNESCAPED_UNICODE) ?: '{}',
        };
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->required(),
            'knowledge_base_id' => $schema->integer(),
            'payload_json' => $schema->string(),
        ];
    }
}
