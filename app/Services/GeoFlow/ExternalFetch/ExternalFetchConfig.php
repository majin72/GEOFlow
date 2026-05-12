<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\ExternalFetch;

use App\Support\Site\SiteSettingsBag;

/**
 * 外部浏览器抓取（External Fetch）的运行时配置载体。
 *
 * 配置存储位置：site_settings 表（键值对），通过 {@see SiteSettingsBag} 读取。
 * 选择放在 SiteSetting 而非 .env / config 文件的原因：
 *   1. 让 admin 在后台改完立即生效（SiteSettingsBag 有 60s 短缓存，写入后会 forget）
 *   2. 多机部署时所有节点共享同一份配置，无需逐台改 .env
 *
 * 注意：管理后台 UI 计划在 Stage 1 之后做（详见 docs/external-fetch-plan.md §4 Stage 5）。
 * 首次部署阶段，可以通过 tinker / SQL 直接 INSERT 对应键。
 */
final class ExternalFetchConfig
{
    public const KEY_ENABLED = 'external_fetch_enabled';

    public const KEY_ENDPOINT = 'external_fetch_endpoint';

    public const KEY_TOKEN = 'external_fetch_token';

    public const KEY_TIMEOUT = 'external_fetch_timeout';

    public const KEY_DOMAINS = 'external_fetch_domains';

    public const KEY_RETRY_ON_STATUS = 'external_fetch_retry_on_status';

    /**
     * 默认域名白名单：命中即首选走外部浏览器（Stage 1 的内置候选列表）。
     */
    public const DEFAULT_DOMAINS = 'zhuanlan.zhihu.com,zhihu.com,xiaohongshu.com,mp.weixin.qq.com';

    /**
     * 默认 fallback 触发状态码：普通直连抓取拿到这些状态时回退到外部浏览器。
     */
    public const DEFAULT_RETRY_ON_STATUS = '403,429';

    /**
     * 默认 HTTP 超时（秒）；Bridge 单次抓取实测 3~6s，留较大余量以容忍冷启动 / 重试。
     */
    public const DEFAULT_TIMEOUT = 60;

    /**
     * @param  bool          $enabled        是否启用外部浏览器抓取（总开关）
     * @param  string        $endpoint       Bridge 端点，例如 "http://host.docker.internal:19826"
     * @param  string        $token          Bearer 鉴权 token；空字符串表示未启用鉴权
     * @param  int           $timeout        HTTP 请求超时秒数；必须 > 0，否则使用 {@see self::DEFAULT_TIMEOUT}
     * @param  list<string>  $domains        命中即首选外部抓取的域名白名单（小写、不含协议）
     * @param  list<int>     $retryOnStatus  普通抓取遇到这些 HTTP 状态时触发回退
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $endpoint,
        public readonly string $token,
        public readonly int $timeout,
        public readonly array $domains,
        public readonly array $retryOnStatus,
    ) {
    }

    /**
     * 从 site_settings 表读取当前配置并构建实例。
     *
     * 读不到的键全部回落到内置默认值；不会抛异常，使 Service 可以始终被 resolve
     * （是否实际工作由 {@see ExternalFetchService::isEnabled()} 决定）。
     */
    public static function fromSettings(): self
    {
        $rawDomains = SiteSettingsBag::get(self::KEY_DOMAINS, self::DEFAULT_DOMAINS);
        $rawRetry = SiteSettingsBag::get(self::KEY_RETRY_ON_STATUS, self::DEFAULT_RETRY_ON_STATUS);

        return new self(
            enabled: self::parseBool(SiteSettingsBag::get(self::KEY_ENABLED, '0')),
            endpoint: trim(SiteSettingsBag::get(self::KEY_ENDPOINT, '')),
            token: trim(SiteSettingsBag::get(self::KEY_TOKEN, '')),
            timeout: self::parseTimeout(SiteSettingsBag::get(self::KEY_TIMEOUT, (string) self::DEFAULT_TIMEOUT)),
            domains: array_map('strtolower', self::parseCsv($rawDomains)),
            retryOnStatus: self::parseIntCsv($rawRetry),
        );
    }

    /**
     * 兼容 "1"/"true"/"yes"/"on" 等常见 truthy 字面量。
     */
    private static function parseBool(string $raw): bool
    {
        $normalized = strtolower(trim($raw));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 解析超时秒数；非正整数回落到默认值。
     */
    private static function parseTimeout(string $raw): int
    {
        $value = (int) trim($raw);

        return $value > 0 ? $value : self::DEFAULT_TIMEOUT;
    }

    /**
     * 解析逗号分隔字符串，剔除空白项，保持出现顺序。
     *
     * @return list<string>
     */
    private static function parseCsv(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));

        return array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));
    }

    /**
     * 解析整数 CSV（HTTP 状态码），剔除非正整数项。
     *
     * @return list<int>
     */
    private static function parseIntCsv(string $raw): array
    {
        $ints = array_map('intval', self::parseCsv($raw));

        return array_values(array_filter($ints, static fn (int $code): bool => $code > 0));
    }
}
