<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AdminOps\AdminOpsSiteWriteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * AI 运维写库工具：覆盖保存文章详情页广告位配置（article_detail_ads JSON）。
 */
final class AdminOpsSiteSetArticleAdsTool implements Tool
{
    public function __construct(
        private readonly AdminOpsSiteWriteService $siteWrite,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '覆盖写入文章详情页广告位（site_settings.article_detail_ads）。ads_json 为 JSON 数组，元素字段：id, name, badge, title, copy, button_text, button_url, enabled；每条非空广告需 copy、button_text、button_url。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $raw = trim((string) Arr::get($request->toArray(), 'ads_json', '[]'));

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return json_encode(['ok' => false, 'error' => 'ads_json 不是合法 JSON 数组。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        if (! is_array($decoded)) {
            return json_encode(['ok' => false, 'error' => 'ads_json 必须为 JSON 数组。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return json_encode($this->siteWrite->setArticleDetailAds($decoded), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ads_json' => $schema->string()
                ->description('广告位列表的 JSON 数组字符串；可为 [] 清空。'),
        ];
    }
}
