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
 * AI 运维写库工具：合并更新外部浏览器抓取（external_fetch_* site_settings）。
 */
final class AdminOpsExternalFetchPatchTool implements Tool
{
    public function __construct(
        private readonly AdminOpsSiteWriteService $siteWrite,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return '合并写入外部浏览器抓取配置：enabled, endpoint, token, timeout, domains(逗号域名白名单), retry_on_status(逗号状态码)。与后台「外部浏览器抓取」页一致。';
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $raw = trim((string) Arr::get($request->toArray(), 'patch_json', '{}'));

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return json_encode(['ok' => false, 'error' => 'patch_json 不是合法 JSON。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        if (! is_array($decoded)) {
            return json_encode(['ok' => false, 'error' => 'patch_json 必须为 JSON 对象。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return json_encode($this->siteWrite->patchExternalFetch($decoded), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'patch_json' => $schema->string()
                ->description('要合并的 JSON 对象字符串：enabled, endpoint, token, timeout, domains, retry_on_status。'),
        ];
    }
}
