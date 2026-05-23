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
 * AI 运维：文章列表、详情与批量/单篇写入。
 */
final class AdminOpsArticlesTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsArticlesTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '管理文章：list（filters_json 同后台列表）、detail、create、update、restore、force_delete、batch_status、batch_review、batch_soft_delete、batch_restore、batch_force_delete、trash_empty。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $action = AdminOpsMirrorRequest::action($request);

        return match ($action) {
            'list' => $this->runner->runRead('articles_list', AdminOpsMirrorRequest::filtersJson($request)),
            'detail' => $this->runner->runRead('article_detail', [
                'article_id' => AdminOpsMirrorRequest::int($request, 'article_id'),
                'only_trashed' => AdminOpsMirrorRequest::int($request, 'only_trashed') === 1,
            ]),
            'create' => $this->runner->runWrite(self::TOOL_NAME, 'article_create', AdminOpsMirrorRequest::payloadJson($request) ?: AdminOpsMirrorRequest::data($request)),
            'update' => $this->runner->runWrite(self::TOOL_NAME, 'article_update', [
                'article_id' => AdminOpsMirrorRequest::int($request, 'article_id'),
                'payload' => AdminOpsMirrorRequest::payloadJson($request) ?: AdminOpsMirrorRequest::data($request),
            ]),
            'restore' => $this->runner->runWrite(self::TOOL_NAME, 'article_restore', [
                'article_id' => AdminOpsMirrorRequest::int($request, 'article_id'),
            ]),
            'force_delete' => $this->runner->runWrite(self::TOOL_NAME, 'article_force_delete', [
                'article_id' => AdminOpsMirrorRequest::int($request, 'article_id'),
            ]),
            'batch_status' => $this->runner->runWrite(self::TOOL_NAME, 'articles_batch_status', [
                'article_ids' => AdminOpsMirrorRequest::intList($request, 'article_ids'),
                'new_status' => AdminOpsMirrorRequest::string($request, 'new_status'),
            ]),
            'batch_review' => $this->runner->runWrite(self::TOOL_NAME, 'articles_batch_review', [
                'article_ids' => AdminOpsMirrorRequest::intList($request, 'article_ids'),
                'review_status' => AdminOpsMirrorRequest::string($request, 'review_status'),
            ]),
            'batch_soft_delete' => $this->runner->runWrite(self::TOOL_NAME, 'articles_batch_soft_delete', [
                'article_ids' => AdminOpsMirrorRequest::intList($request, 'article_ids'),
            ]),
            'batch_restore' => $this->runner->runWrite(self::TOOL_NAME, 'articles_batch_restore', [
                'article_ids' => AdminOpsMirrorRequest::intList($request, 'article_ids'),
            ]),
            'batch_force_delete' => $this->runner->runWrite(self::TOOL_NAME, 'articles_batch_force_delete', [
                'article_ids' => AdminOpsMirrorRequest::intList($request, 'article_ids'),
            ]),
            'trash_empty' => $this->runner->runWrite(self::TOOL_NAME, 'articles_trash_empty', []),
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
            'article_id' => $schema->integer(),
            'article_ids' => $schema->array()->items($schema->integer()),
            'filters_json' => $schema->string(),
            'payload_json' => $schema->string(),
            'new_status' => $schema->string(),
            'review_status' => $schema->string(),
            'only_trashed' => $schema->integer(),
        ];
    }
}
