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
 * AI 运维：图片库 CRUD 与图片批量删除。
 */
final class AdminOpsImageLibrariesTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsImageLibrariesTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '图片库：libraries_list、library_detail、library_create|update|delete、image_delete_batch。图片上传仅能通过后台页面上传。';
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
            'libraries_list' => $this->runner->runRead('image_libraries_list'),
            'library_detail' => $this->runner->runRead('image_library_detail', [
                'library_id' => $libId,
                'page' => max(1, AdminOpsMirrorRequest::int($request, 'page', 1)),
            ]),
            'library_create' => $this->runner->runWrite(self::TOOL_NAME, 'image_library_create', $body ?: AdminOpsMirrorRequest::data($request)),
            'library_update' => $this->runner->runWrite(self::TOOL_NAME, 'image_library_update', [
                'library_id' => $libId,
                'payload' => $body ?: AdminOpsMirrorRequest::data($request),
            ]),
            'library_delete' => $this->runner->runWrite(self::TOOL_NAME, 'image_library_delete', ['library_id' => $libId]),
            'image_delete_batch' => $this->runner->runWrite(self::TOOL_NAME, 'image_delete_batch', [
                'library_id' => $libId,
                'image_ids' => AdminOpsMirrorRequest::intList($request, 'image_ids'),
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
            'image_ids' => $schema->array()->items($schema->integer()),
            'page' => $schema->integer(),
            'payload_json' => $schema->string(),
        ];
    }
}
