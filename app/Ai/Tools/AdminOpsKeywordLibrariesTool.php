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
 * AI 运维：关键词库 CRUD 与词条管理。
 */
final class AdminOpsKeywordLibrariesTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsKeywordLibrariesTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '关键词库：libraries_list、library_detail、library_create|update|delete、keyword_add、keyword_delete_batch、keyword_import。';
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
            'libraries_list' => $this->runner->runRead('keyword_libraries_list'),
            'library_detail' => $this->runner->runRead('keyword_library_detail', [
                'library_id' => $libId,
                'search' => AdminOpsMirrorRequest::string($request, 'search'),
                'page' => max(1, AdminOpsMirrorRequest::int($request, 'page', 1)),
            ]),
            'library_create' => $this->runner->runWrite(self::TOOL_NAME, 'keyword_library_create', $body ?: AdminOpsMirrorRequest::data($request)),
            'library_update' => $this->runner->runWrite(self::TOOL_NAME, 'keyword_library_update', [
                'library_id' => $libId,
                'payload' => $body ?: AdminOpsMirrorRequest::data($request),
            ]),
            'library_delete' => $this->runner->runWrite(self::TOOL_NAME, 'keyword_library_delete', ['library_id' => $libId]),
            'keyword_add' => $this->runner->runWrite(self::TOOL_NAME, 'keyword_add', [
                'library_id' => $libId,
                'keyword' => AdminOpsMirrorRequest::string($request, 'keyword'),
            ]),
            'keyword_delete_batch' => $this->runner->runWrite(self::TOOL_NAME, 'keyword_delete_batch', [
                'library_id' => $libId,
                'keyword_ids' => AdminOpsMirrorRequest::intList($request, 'keyword_ids'),
            ]),
            'keyword_import' => $this->runner->runWrite(self::TOOL_NAME, 'keyword_import', [
                'library_id' => $libId,
                'keywords_text' => AdminOpsMirrorRequest::string($request, 'keywords_text'),
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
            'keyword' => $schema->string(),
            'keywords_text' => $schema->string(),
            'keyword_ids' => $schema->array()->items($schema->integer()),
            'search' => $schema->string(),
            'page' => $schema->integer(),
            'payload_json' => $schema->string(),
        ];
    }
}
