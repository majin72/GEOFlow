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
 * AI 运维：URL 导入任务查询与创建/运行/提交。
 */
final class AdminOpsUrlImportTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsUrlImportTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return 'URL 导入：index_stats、job_show、history、status、create、run、commit。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $action = AdminOpsMirrorRequest::action($request);
        $jobId = AdminOpsMirrorRequest::int($request, 'job_id');
        $body = AdminOpsMirrorRequest::payloadJson($request);

        return match ($action) {
            'index_stats' => $this->runner->runRead('url_import_index_stats'),
            'job_show' => $this->runner->runRead('url_import_job_show', ['job_id' => $jobId]),
            'history' => $this->runner->runRead('url_import_history', [
                'page' => max(1, AdminOpsMirrorRequest::int($request, 'page', 1)),
            ]),
            'status' => $this->runner->runRead('url_import_status', ['job_id' => $jobId]),
            'create' => $this->runner->runWrite(self::TOOL_NAME, 'url_import_create', $body ?: AdminOpsMirrorRequest::data($request)),
            'run' => $this->runner->runWrite(self::TOOL_NAME, 'url_import_run', ['job_id' => $jobId]),
            'commit' => $this->runner->runWrite(self::TOOL_NAME, 'url_import_commit', ['job_id' => $jobId]),
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
            'job_id' => $schema->integer(),
            'page' => $schema->integer(),
            'payload_json' => $schema->string(),
        ];
    }
}
