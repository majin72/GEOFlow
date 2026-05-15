<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AdminOps\AdminOpsSiteWriteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * AI 运维写库工具：设置站点默认 embedding 模型（site_settings.default_embedding_model_id）。
 */
final class AdminOpsSetDefaultEmbeddingModelTool implements Tool
{
    public function __construct(
        private readonly AdminOpsSiteWriteService $siteWrite,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '设置知识库/向量同步使用的默认 embedding 模型 ID（与后台「AI 模型」页一致）：必须为 status=active 且 model_type=embedding 的 ai_models.id；传 0 表示清除固定默认、交由系统自动策略。写入前建议用 AdminOpsSiteInfoTool 查看 integrations.embedding。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $raw = Arr::get($request->toArray(), 'model_id', 0);
        if (is_string($raw)) {
            $modelId = (int) trim($raw);
        } elseif (is_int($raw)) {
            $modelId = $raw;
        } elseif (is_float($raw)) {
            $modelId = (int) $raw;
        } else {
            $modelId = 0;
        }

        return json_encode(
            $this->siteWrite->setDefaultEmbeddingModelId($modelId),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}';
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'model_id' => $schema->string()
                ->description('默认 embedding 对应的 ai_models 主键 id（十进制整数字符串）；0 表示清除。'),
        ];
    }
}
