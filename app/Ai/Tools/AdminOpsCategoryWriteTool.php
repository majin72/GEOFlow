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
 * AI 运维：栏目增删改（只读列表用 AdminOpsListCategoriesTool）。
 */
final class AdminOpsCategoryWriteTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsCategoryWriteTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '栏目写入：create、update、delete。update/delete 定位支持 category_id、id、slug、category_name。字段与后台栏目表单一致（name、slug、description、sort_order 等）。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $action = AdminOpsMirrorRequest::action($request);
        $body = AdminOpsMirrorRequest::payloadJson($request);
        if ($body === []) {
            $body = AdminOpsMirrorRequest::data($request);
            unset($body['action']);
        }

        return match ($action) {
            'create' => $this->runner->runWrite(self::TOOL_NAME, 'category_create', $body),
            'update' => $this->runner->runWrite(self::TOOL_NAME, 'category_update', $body),
            'delete' => $this->runner->runWrite(self::TOOL_NAME, 'category_delete', $body),
            default => json_encode(['ok' => false, 'error' => 'action 须为 create|update|delete。'], JSON_UNESCAPED_UNICODE) ?: '{}',
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
            'action' => $schema->string()->description('create|update|delete')->required(),
            'category_id' => $schema->integer(),
            'id' => $schema->integer(),
            'slug' => $schema->string(),
            'category_name' => $schema->string(),
            'name' => $schema->string(),
            'description' => $schema->string(),
            'sort_order' => $schema->integer(),
            'payload_json' => $schema->string(),
        ];
    }
}
