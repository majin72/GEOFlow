<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\ExternalFetch;

use App\Exceptions\GeoFlow\ExternalFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 通过本地浏览器（opencli + Bridge）远程抓取需要登录态 / JS 挑战的页面。
 *
 * 单机阶段：服务器 → SSH 反向隧道 → 本地 Bridge → opencli daemon → Chrome → 目标网站。
 * 集群阶段：通过 Tailscale 多节点（详见 docs/external-fetch-plan.md §3.2）。
 *
 * 本 Service 只承担"协议层"职责：
 *   1. 判断某 URL 是否应走外部浏览器（白名单匹配 / fallback 状态码判定）
 *   2. 调用 Bridge HTTP 接口并把响应封装成 {@see ExternalFetchResult}
 *
 * 业务流程（写库、触发下一步等）由 UrlImportProcessingService 在 Stage 2 接入；
 * 这样未来切换到 Playwright Remote / Tailscale Pool 等其它 driver 时，
 * 业务侧不用动，只需要替换或扩展本 Service 的实现。
 */
class ExternalFetchService
{
    /**
     * @param  ExternalFetchConfig  $config 由 {@see ExternalFetchConfig::fromSettings()} 从 SiteSetting 构造
     * @param  HttpFactory          $http   Laravel HTTP 客户端工厂；测试时由 Http::fake() 拦截
     */
    public function __construct(
        private readonly ExternalFetchConfig $config,
        private readonly HttpFactory $http,
    ) {
    }

    /**
     * 总开关 + 必备配置完整性检查。
     *
     * 即使在 site_settings 里把 enabled 设为 true，只要 endpoint 仍为空，
     * 也视为未启用，避免向 "" 端点发送 HTTP 请求。
     */
    public function isEnabled(): bool
    {
        return $this->config->enabled && $this->config->endpoint !== '';
    }

    /**
     * 判断指定 URL 是否命中域名白名单（应优先使用外部浏览器）。
     *
     * 匹配规则：完全相等 或 主机名以 ".<domain>" 结尾（子域名）。
     * 例如白名单 "zhihu.com" 匹配：zhihu.com、www.zhihu.com、zhuanlan.zhihu.com；
     * 不匹配：fake-zhihu.com（防止后缀绕过）。
     */
    public function shouldUseExternal(string $url): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);
        foreach ($this->config->domains as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain === '') {
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断普通直连抓取返回的 HTTP 状态码是否应回退到外部浏览器。
     *
     * 未启用时一律返回 false，避免不必要的二次抓取。
     */
    public function isFallbackStatus(int $status): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return in_array($status, $this->config->retryOnStatus, true);
    }

    /**
     * 通过 Bridge 远程抓取目标 URL，返回封装好的结果。
     *
     * @throws ExternalFetchException 未启用 / 连接失败 / Bridge 非 2xx / 响应不是合法 JSON / 缺失关键字段
     */
    public function fetch(string $url): ExternalFetchResult
    {
        if (! $this->isEnabled()) {
            throw new ExternalFetchException('External fetch is not enabled or endpoint is empty');
        }

        $endpoint = rtrim($this->config->endpoint, '/').'/fetch';

        try {
            $request = $this->http
                ->timeout($this->config->timeout)
                ->acceptJson()
                ->asJson();

            if ($this->config->token !== '') {
                $request = $request->withToken($this->config->token);
            }

            $response = $request->post($endpoint, ['url' => $url]);
        } catch (ConnectionException $e) {
            Log::warning('ExternalFetch connection failed', [
                'url' => $url,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new ExternalFetchException(
                "External fetch bridge unreachable: {$e->getMessage()}",
                0,
                $e,
            );
        } catch (Throwable $e) {
            Log::warning('ExternalFetch unexpected error', [
                'url' => $url,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new ExternalFetchException("External fetch failed: {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            $body = $response->body();
            $snippet = strlen($body) > 200 ? substr($body, 0, 200).'…' : $body;

            throw new ExternalFetchException(sprintf(
                'Bridge returned HTTP %d: %s',
                $response->status(),
                $snippet,
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new ExternalFetchException('Bridge response is not a valid JSON object');
        }

        return ExternalFetchResult::fromArray($data);
    }
}
