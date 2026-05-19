<?php

declare(strict_types=1);

namespace App\Services\Admin\AiOps;

use App\Exceptions\GeoFlow\ExternalFetchException;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchService;
use App\Support\AdminAiOpsUtf8;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

/**
 * AI 运维：受控 HTTP(S) 抓取外部页面或 API，供 Agent 参考页面结构或接口数据（含 SSRF 防护与体积截断）。
 */
final class AdminAiOpsUrlFetchService
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'HEAD'];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ExternalFetchService $externalFetch,
    ) {}

    /**
     * 抓取指定 URL 并返回可给模型阅读的摘要结构。
     *
     * @param  array<string, string>  $extraHeaders  额外请求头（键名大小写不敏感）
     * @return array{
     *     ok: bool,
     *     error?: string,
     *     url?: string,
     *     method?: string,
     *     status?: int,
     *     content_type?: string,
     *     body_preview?: string,
     *     body_length?: int,
     *     truncated?: bool,
     *     via?: string
     * }
     */
    public function fetch(
        string $url,
        string $method = 'GET',
        array $extraHeaders = [],
        ?string $body = null,
    ): array {
        if (! (bool) config('geoflow.admin_ai_ops_url_fetch.enabled', true)) {
            return ['ok' => false, 'error' => 'AI 运维外部 URL 抓取未启用。'];
        }

        $method = strtoupper(trim($method));
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            return ['ok' => false, 'error' => '仅支持 GET、POST、HEAD 方法。'];
        }

        try {
            $normalizedUrl = $this->normalizeUrl($url);
            $this->assertHostAllowed($normalizedUrl);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($method !== 'POST') {
            $body = null;
        }

        if ($this->externalFetch->shouldUseExternal($normalizedUrl)) {
            return $this->fetchViaExternalBrowser($normalizedUrl, $method);
        }

        return $this->fetchViaHttp($normalizedUrl, $method, $extraHeaders, $body);
    }

    /**
     * 规范化并校验 URL（仅 http/https）。
     *
     * @throws InvalidArgumentException
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('url 不能为空。');
        }

        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('URL 格式无效。');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('仅允许 http 或 https 协议。');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            throw new InvalidArgumentException('URL 缺少主机名。');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URL 不得包含用户名或密码。');
        }

        return $url;
    }

    /**
     * 主机白名单（若配置非空）与 SSRF 校验。
     *
     * @throws InvalidArgumentException
     */
    private function assertHostAllowed(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new InvalidArgumentException('无法解析 URL 主机名。');
        }

        /** @var array<int, string> $allowHosts */
        $allowHosts = config('geoflow.admin_ai_ops_url_fetch.allow_hosts', []);
        if ($allowHosts !== []) {
            $matched = false;
            foreach ($allowHosts as $allowed) {
                $allowed = strtolower(trim($allowed));
                if ($allowed === '') {
                    continue;
                }
                if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                throw new InvalidArgumentException('该主机不在允许抓取的白名单内。');
            }
        }

        $this->guardAgainstPrivateTargets($host);
    }

    /**
     * 禁止解析到本机、内网或保留地址。
     *
     * @throws InvalidArgumentException
     */
    private function guardAgainstPrivateTargets(string $host): void
    {
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'], true) || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('不允许访问本地或内网地址。');
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        $allowMixedDns = (bool) config('geoflow.url_import_allow_mixed_dns', false);

        if (! is_array($records) || $records === []) {
            $resolved = @gethostbyname($host);
            if (is_string($resolved) && $resolved !== '' && $resolved !== $host) {
                $this->guardIpAddress($resolved, $allowMixedDns);
            }

            return;
        }

        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip !== '') {
                $this->guardIpAddress($ip, $allowMixedDns);
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function guardIpAddress(string $ip, bool $allowMixedDns): void
    {
        if ($allowMixedDns && $this->isUlaAddress($ip)) {
            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException('不允许访问本地或内网地址。');
        }
    }

    /**
     * 判断 IPv6 是否属于 ULA（fc00::/7）。
     */
    private function isUlaAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $bin = @inet_pton($ip);

        return $bin !== false && (ord($bin[0]) & 0xFE) === 0xFC;
    }

    /**
     * 通过站点已配置的外部浏览器 Bridge 抓取（仅 GET 类页面）。
     *
     * @return array<string, mixed>
     */
    private function fetchViaExternalBrowser(string $url, string $method): array
    {
        if ($method !== 'GET') {
            return [
                'ok' => false,
                'error' => '该域名需走外部浏览器抓取，仅支持 GET。',
                'url' => $url,
            ];
        }

        try {
            $result = $this->externalFetch->fetch($url);
            $markdown = trim($result->markdown);
            $preview = $this->buildBodyPreview($markdown, 'text/markdown');

            return [
                'ok' => true,
                'url' => $url,
                'method' => 'GET',
                'status' => 200,
                'content_type' => 'text/markdown',
                'body_preview' => $preview['text'],
                'body_length' => mb_strlen($markdown),
                'truncated' => $preview['truncated'],
                'via' => 'external_browser',
            ];
        } catch (ExternalFetchException|Throwable $e) {
            return [
                'ok' => false,
                'error' => '外部浏览器抓取失败：'.$e->getMessage(),
                'url' => $url,
            ];
        }
    }

    /**
     * 通过 Laravel HTTP 客户端直连抓取。
     *
     * @param  array<string, string>  $extraHeaders
     * @return array<string, mixed>
     */
    private function fetchViaHttp(string $url, string $method, array $extraHeaders, ?string $body): array
    {
        $timeout = max(3, (int) config('geoflow.admin_ai_ops_url_fetch.timeout_seconds', 15));
        $maxBytes = max(8192, (int) config('geoflow.admin_ai_ops_url_fetch.max_response_bytes', 262144));

        try {
            $request = $this->http
                ->timeout($timeout)
                ->connectTimeout(min(8, $timeout))
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 3,
                        'strict' => true,
                        'referer' => false,
                    ],
                ])
                ->withHeaders(array_merge([
                    'User-Agent' => 'GEOFlow-AdminAiOps/1.0',
                    'Accept' => 'text/html,application/json,text/plain,*/*;q=0.8',
                ], $extraHeaders));

            $response = match ($method) {
                'HEAD' => $request->head($url),
                'POST' => $request->withBody((string) ($body ?? ''), 'application/json')->post($url),
                default => $request->get($url),
            };
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'error' => '连接失败：'.$e->getMessage(),
                'url' => $url,
                'method' => $method,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => '请求异常：'.$e->getMessage(),
                'url' => $url,
                'method' => $method,
            ];
        }

        return $this->formatHttpResponse($url, $method, $response, $maxBytes);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatHttpResponse(string $url, string $method, Response $response, int $maxBytes): array
    {
        $contentTypeHeader = strtolower(trim((string) $response->header('Content-Type')));
        $rawBody = $this->normalizeResponseBodyEncoding((string) $response->body(), $contentTypeHeader);
        $bodyLength = strlen($rawBody);
        if ($bodyLength > $maxBytes) {
            $rawBody = substr($rawBody, 0, $maxBytes);
        }

        $contentType = explode(';', $contentTypeHeader)[0] ?? $contentTypeHeader;
        $preview = $this->buildBodyPreview($rawBody, $contentType);

        return [
            'ok' => true,
            'url' => $url,
            'method' => $method,
            'status' => $response->status(),
            'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
            'body_preview' => $preview['text'],
            'body_length' => $bodyLength,
            'truncated' => $bodyLength > $maxBytes || $preview['truncated'],
            'via' => 'http',
        ];
    }

    /**
     * 将响应体整理为模型可读预览（JSON 美化、HTML 去标签）。
     *
     * @return array{text: string, truncated: bool}
     */
    private function buildBodyPreview(string $body, string $contentType): array
    {
        $maxChars = max(500, (int) config('geoflow.admin_ai_ops_url_fetch.max_body_preview_chars', 12000));
        $truncated = false;

        if (str_contains($contentType, 'json') || $this->looksLikeJson($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) || is_object($decoded)) {
                $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $body = is_string($encoded) ? $encoded : $body;
            }
        } elseif (str_contains($contentType, 'html') || $this->looksLikeHtml($body)) {
            $body = $this->htmlToPlainPreview($body);
        }

        if (mb_strlen($body) > $maxChars) {
            $body = mb_substr($body, 0, $maxChars).'…';
            $truncated = true;
        }

        return ['text' => AdminAiOpsUtf8::sanitizeString($body), 'truncated' => $truncated];
    }

    /**
     * 按 Content-Type charset 将响应体转为 UTF-8，并剔除非法字节。
     */
    private function normalizeResponseBodyEncoding(string $body, string $contentType): string
    {
        $charset = 'utf-8';
        if (preg_match('/charset\s*=\s*["\']?([a-zA-Z0-9_\-]+)/i', $contentType, $matches) === 1) {
            $charset = strtolower($matches[1]);
        }

        if (! in_array($charset, ['utf-8', 'utf8'], true)) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') {
                $body = $converted;
            }
        }

        return AdminAiOpsUtf8::sanitizeString($body);
    }

    /**
     * 粗略判断字符串是否为 JSON。
     */
    private function looksLikeJson(string $body): bool
    {
        $trim = ltrim($body);

        return $trim !== '' && ($trim[0] === '{' || $trim[0] === '[');
    }

    /**
     * 粗略判断字符串是否为 HTML。
     */
    private function looksLikeHtml(string $body): bool
    {
        return str_contains(strtolower($body), '<html') || str_contains(strtolower($body), '<!doctype');
    }

    /**
     * 将 HTML 转为较短的纯文本预览。
     */
    private function htmlToPlainPreview(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
