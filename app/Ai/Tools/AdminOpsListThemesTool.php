<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AdminOps\AdminOpsSiteWriteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * AI 运维只读工具：列出当前已安装的前端主题（资源目录 manifest / home.blade 探测）。
 */
final class AdminOpsListThemesTool implements Tool
{
    public function __construct(
        private readonly AdminOpsSiteWriteService $siteWrite,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '列出 GEOFlow 已安装主题（id、名称、版本、描述）。在切换主题前应先调用以确认 theme_id；与 AdminOpsSiteSetActiveThemeTool 配合使用。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $themes = $this->siteWrite->listInstalledThemes();

        return json_encode([
            'ok' => true,
            'themes' => $themes,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
