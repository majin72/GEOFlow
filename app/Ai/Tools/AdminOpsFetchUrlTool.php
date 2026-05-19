<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AiOps\AdminAiOpsUrlFetchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * AI 运维只读工具：抓取外部 HTTPS/HTTP 页面或 API 响应，供参考页面结构或接口数据。
 */
final class AdminOpsFetchUrlTool implements Tool
{
    public function __construct(
        private readonly AdminAiOpsUrlFetchService $urlFetch,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '抓取外部公开 URL（页面 HTML 或 REST/JSON API），返回 HTTP 状态、Content-Type 与正文预览（过长会截断）。用于对照竞品页面、读取接口文档示例或验证第三方 API 返回；仅 GET/POST/HEAD，不能访问内网/本机。勿用其修改 GEOFlow 后台或站点数据库。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->toArray();
        $url = trim((string) Arr::get($data, 'url', ''));
        $method = trim((string) Arr::get($data, 'method', 'GET'));
        $body = Arr::get($data, 'body');
        $bodyStr = is_string($body) ? $body : null;

        $headers = [];
        $headersRaw = trim((string) Arr::get($data, 'headers_json', ''));
        if ($headersRaw !== '') {
            try {
                $decoded = json_decode($headersRaw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    foreach ($decoded as $key => $value) {
                        if (is_string($key) && (is_string($value) || is_numeric($value))) {
                            $headers[$key] = (string) $value;
                        }
                    }
                }
            } catch (Throwable) {
                return json_encode([
                    'ok' => false,
                    'error' => 'headers_json 不是合法 JSON 对象。',
                ], JSON_UNESCAPED_UNICODE) ?: '{}';
            }
        }

        try {
            $result = $this->urlFetch->fetch($url, $method !== '' ? $method : 'GET', $headers, $bodyStr);
        } catch (Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => '抓取失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('要抓取的完整 URL，或省略协议的域名路径（将默认使用 https）。')
                ->required(),
            'method' => $schema->string()
                ->description('HTTP 方法：GET（默认）、POST 或 HEAD。POST 时可通过 body 传 JSON 字符串。'),
            'headers_json' => $schema->string()
                ->description('可选：额外请求头 JSON 对象字符串，如 {"Authorization":"Bearer xxx","Accept":"application/json"}。'),
            'body' => $schema->string()
                ->description('可选：POST 请求体（通常为 JSON 字符串）；GET/HEAD 忽略。'),
        ];
    }
}
