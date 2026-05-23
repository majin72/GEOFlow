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
 * AI 运维：AI 模型与提示词配置读写。
 */
final class AdminOpsAiConfigTool implements Tool
{
    private const TOOL_NAME = 'AdminOpsAiConfigTool';

    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return 'AI 配置：models_list、prompts_list、special_prompts_read；model_create|update|delete|test；prompt_create|update|delete；special_prompt_keyword|special_prompt_description。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $action = AdminOpsMirrorRequest::action($request);
        $body = AdminOpsMirrorRequest::payloadJson($request);
        $data = AdminOpsMirrorRequest::data($request);

        return match ($action) {
            'models_list' => $this->runner->runRead('ai_models_list'),
            'prompts_list' => $this->runner->runRead('ai_prompts_list'),
            'special_prompts_read' => $this->runner->runRead('ai_special_prompts_read'),
            'model_create' => $this->runner->runWrite(self::TOOL_NAME, 'ai_model_create', $body ?: $data),
            'model_update' => $this->runner->runWrite(self::TOOL_NAME, 'ai_model_update', [
                'model_id' => AdminOpsMirrorRequest::int($request, 'model_id'),
                'payload' => $body ?: $data,
            ]),
            'model_delete' => $this->runner->runWrite(self::TOOL_NAME, 'ai_model_delete', [
                'model_id' => AdminOpsMirrorRequest::int($request, 'model_id'),
            ]),
            'model_test' => $this->runner->runWrite(self::TOOL_NAME, 'ai_model_test', [
                'model_id' => AdminOpsMirrorRequest::int($request, 'model_id'),
            ]),
            'prompt_create' => $this->runner->runWrite(self::TOOL_NAME, 'ai_prompt_create', $body ?: $data),
            'prompt_update' => $this->runner->runWrite(self::TOOL_NAME, 'ai_prompt_update', [
                'prompt_id' => AdminOpsMirrorRequest::int($request, 'prompt_id'),
                'payload' => $body ?: $data,
            ]),
            'prompt_delete' => $this->runner->runWrite(self::TOOL_NAME, 'ai_prompt_delete', [
                'prompt_id' => AdminOpsMirrorRequest::int($request, 'prompt_id'),
            ]),
            'special_prompt_keyword' => $this->runner->runWrite(self::TOOL_NAME, 'ai_special_prompt_keyword', [
                'content' => AdminOpsMirrorRequest::string($request, 'content'),
            ]),
            'special_prompt_description' => $this->runner->runWrite(self::TOOL_NAME, 'ai_special_prompt_description', [
                'content' => AdminOpsMirrorRequest::string($request, 'content'),
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
            'model_id' => $schema->integer(),
            'prompt_id' => $schema->integer(),
            'content' => $schema->string(),
            'payload_json' => $schema->string(),
        ];
    }
}
