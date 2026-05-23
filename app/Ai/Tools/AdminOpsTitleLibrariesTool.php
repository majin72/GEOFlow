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
 * AI 运维：标题库 CRUD 与标题条目管理。
 */
final class AdminOpsTitleLibrariesTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsTitleLibrariesTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '标题库：libraries_list、library_detail、library_create|update|delete、title_add、title_delete_batch、library_ai_generate。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $action = AdminOpsMirrorRequest::action($request);
        $libId = AdminOpsMirrorRequest::int($request, 'library_id');
        $body = AdminOpsMirrorRequest::payloadJson($request);

        return match ($action) {
            'libraries_list' => $this->runner->runRead('title_libraries_list'),
            'library_detail' => $this->runner->runRead('title_library_detail', [
                'library_id' => $libId,
                'page' => max(1, AdminOpsMirrorRequest::int($request, 'page', 1)),
            ]),
            'library_create' => $this->runner->runWrite(self::TOOL_NAME, 'title_library_create', $body ?: AdminOpsMirrorRequest::data($request)),
            'library_update' => $this->runner->runWrite(self::TOOL_NAME, 'title_library_update', [
                'library_id' => $libId,
                'payload' => $body ?: AdminOpsMirrorRequest::data($request),
            ]),
            'library_delete' => $this->runner->runWrite(self::TOOL_NAME, 'title_library_delete', ['library_id' => $libId]),
            'title_add' => $this->runner->runWrite(self::TOOL_NAME, 'title_add', [
                'library_id' => $libId,
                'title' => AdminOpsMirrorRequest::string($request, 'title'),
            ]),
            'title_delete_batch' => $this->runner->runWrite(self::TOOL_NAME, 'title_delete_batch', [
                'library_id' => $libId,
                'title_ids' => AdminOpsMirrorRequest::intList($request, 'title_ids'),
            ]),
            'library_ai_generate' => $this->runner->runWrite(self::TOOL_NAME, 'title_library_ai_generate', [
                'library_id' => $libId,
                'payload' => $body ?: AdminOpsMirrorRequest::data($request),
            ]),
            default => json_encode(['ok' => false, 'error' => 'action 无效。'], JSON_UNESCAPED_UNICODE) ?: '{}',
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
            'library_id' => $schema->integer(),
            'title' => $schema->string(),
            'title_ids' => $schema->array()->items($schema->integer()),
            'page' => $schema->integer(),
            'payload_json' => $schema->string(),
        ];
    }
}
