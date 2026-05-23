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
 * AI 运维：仪表盘与素材统计只读查询。
 */
final class AdminOpsDashboardTool implements Tool
{
    public function __construct(
        private readonly AdminOpsMirrorToolRunner $runner,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '只读：dashboard_summary（站点统计）、materials_stats（素材库统计）、legacy_ai_configurator（旧版 AI 配置说明）。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $action = AdminOpsMirrorRequest::action($request);

        return match ($action) {
            'dashboard_summary' => $this->runner->runRead('dashboard_summary'),
            'materials_stats' => $this->runner->runRead('materials_stats'),
            'legacy_ai_configurator' => $this->runner->runRead('legacy_ai_configurator'),
            default => json_encode(['ok' => false, 'error' => 'action 须为 dashboard_summary|materials_stats|legacy_ai_configurator。'], JSON_UNESCAPED_UNICODE) ?: '{}',
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
        ];
    }
}
