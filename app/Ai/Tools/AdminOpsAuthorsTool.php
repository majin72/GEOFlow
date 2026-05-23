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
 * AI 运维：作者列表/详情与增删改（对齐后台作者管理）。
 */
final class AdminOpsAuthorsTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsAuthorsTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '管理站点作者：list（分页列表）、detail（详情）、create、update、delete。创建/更新必填 name（作者名，勿用 author_name）；选填 email、bio、website、social_links。delete 需 author_id 且无文章引用。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $action = AdminOpsMirrorRequest::action($request);

        return match ($action) {
            'list' => $this->runner->runRead('authors_list', [
                'search' => AdminOpsMirrorRequest::string($request, 'search'),
                'page' => max(1, AdminOpsMirrorRequest::int($request, 'page', 1)),
            ]),
            'detail' => $this->runner->runRead('author_detail', [
                'author_id' => AdminOpsMirrorRequest::int($request, 'author_id'),
            ]),
            'create' => $this->runner->runWrite(self::TOOL_NAME, 'author_create', $this->authorBody($request)),
            'update' => $this->runner->runWrite(self::TOOL_NAME, 'author_update', [
                'author_id' => AdminOpsMirrorRequest::int($request, 'author_id'),
                'payload' => $this->authorBody($request),
            ]),
            'delete' => $this->runner->runWrite(self::TOOL_NAME, 'author_delete', [
                'author_id' => AdminOpsMirrorRequest::int($request, 'author_id'),
            ]),
            default => json_encode(['ok' => false, 'error' => 'action 须为 list|detail|create|update|delete。'], JSON_UNESCAPED_UNICODE) ?: '{}',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function authorBody(Request $request): array
    {
        $body = AdminOpsMirrorRequest::payloadJson($request);
        if ($body === []) {
            $body = array_filter([
                'name' => AdminOpsMirrorRequest::string($request, 'name'),
                'email' => AdminOpsMirrorRequest::string($request, 'email'),
                'bio' => AdminOpsMirrorRequest::string($request, 'bio'),
                'website' => AdminOpsMirrorRequest::string($request, 'website'),
                'social_links' => AdminOpsMirrorRequest::string($request, 'social_links'),
            ], static fn (string $v): bool => $v !== '');
        }

        return $body;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('list|detail|create|update|delete')
                ->required(),
            'author_id' => $schema->integer()->description('detail/update/delete 时必填'),
            'name' => $schema->string()->description('create/update 必填，作者显示名'),
            'search' => $schema->string()->description('list 时可选搜索'),
            'page' => $schema->integer()->description('list 页码，默认 1'),
            'email' => $schema->string(),
            'bio' => $schema->string(),
            'website' => $schema->string(),
            'social_links' => $schema->string(),
            'payload_json' => $schema->string()->description('可选：嵌套 JSON 对象覆盖顶层字段'),
        ];
    }
}
