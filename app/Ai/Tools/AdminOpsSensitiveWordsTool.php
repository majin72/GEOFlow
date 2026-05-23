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
 * AI 运维：敏感词列表与增删。
 */
final class AdminOpsSensitiveWordsTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsSensitiveWordsTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '敏感词：list、add（words 为多行或逗号分隔）、delete（word_id）。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $action = AdminOpsMirrorRequest::action($request);

        return match ($action) {
            'list' => $this->runner->runRead('sensitive_words_list'),
            'add' => $this->runner->runWrite(self::TOOL_NAME, 'sensitive_words_add', [
                'words' => AdminOpsMirrorRequest::string($request, 'words'),
            ]),
            'delete' => $this->runner->runWrite(self::TOOL_NAME, 'sensitive_words_delete', [
                'word_id' => AdminOpsMirrorRequest::int($request, 'word_id'),
            ]),
            default => json_encode(['ok' => false, 'error' => 'action 须为 list|add|delete。'], JSON_UNESCAPED_UNICODE) ?: '{}',
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
            'words' => $schema->string(),
            'word_id' => $schema->integer(),
        ];
    }
}
