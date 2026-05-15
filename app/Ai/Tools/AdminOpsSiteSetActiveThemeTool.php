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
 * AI 运维写库工具：设置启用主题（active_theme）。
 */
final class AdminOpsSiteSetActiveThemeTool implements Tool
{
    public function __construct(
        private readonly AdminOpsSiteWriteService $siteWrite,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '设置站点启用主题（site_settings.active_theme）。theme_id 必须为已安装主题 id，或空字符串表示使用系统默认主题；请先调用 AdminOpsListThemesTool。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $themeId = trim((string) Arr::get($request->toArray(), 'theme_id', ''));

        return json_encode($this->siteWrite->setActiveTheme($themeId), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'theme_id' => $schema->string()
                ->description('主题目录名（与 AdminOpsListThemesTool 返回的 id 一致）；空字符串表示恢复默认主题。'),
        ];
    }
}
