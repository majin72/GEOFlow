<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\ArticleSearch;

use App\Support\Site\SiteSettingsBag;

/**
 * 文章生成联网搜索的运行时配置。
 *
 * 配置存储在 site_settings，便于后台保存后立即被队列 Worker 读取。
 */
final class ArticleSearchConfig
{
    public const KEY_ENABLED = 'article_search_enabled';

    public const KEY_PROVIDER = 'article_search_provider';

    public const KEY_ENDPOINT = 'article_search_endpoint';

    public const KEY_API_KEY = 'article_search_api_key';

    public const KEY_TIMEOUT = 'article_search_timeout';

    public const KEY_MAX_RESULTS = 'article_search_max_results';

    public const KEY_SEARCH_DEPTH = 'article_search_depth';

    public const KEY_INCLUDE_DOMAINS = 'article_search_include_domains';

    public const KEY_CACHE_TTL = 'article_search_cache_ttl';

    public const DEFAULT_PROVIDER = 'tavily';

    public const DEFAULT_ENDPOINT = 'https://api.tavily.com/search';

    public const DEFAULT_TIMEOUT = 20;

    public const DEFAULT_MAX_RESULTS = 5;

    public const DEFAULT_SEARCH_DEPTH = 'basic';

    public const DEFAULT_CACHE_TTL = 43200;

    /**
     * @param  list<string>  $includeDomains
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $provider,
        public readonly string $endpoint,
        public readonly string $apiKey,
        public readonly int $timeout,
        public readonly int $maxResults,
        public readonly string $searchDepth,
        public readonly array $includeDomains,
        public readonly int $cacheTtl,
    ) {}

    /**
     * 从 site_settings 读取当前配置。
     */
    public static function fromSettings(): self
    {
        return new self(
            enabled: self::parseBool(SiteSettingsBag::get(self::KEY_ENABLED, '0')),
            provider: trim(SiteSettingsBag::get(self::KEY_PROVIDER, self::DEFAULT_PROVIDER)) ?: self::DEFAULT_PROVIDER,
            endpoint: trim(SiteSettingsBag::get(self::KEY_ENDPOINT, self::DEFAULT_ENDPOINT)) ?: self::DEFAULT_ENDPOINT,
            apiKey: trim(SiteSettingsBag::get(self::KEY_API_KEY, '')),
            timeout: self::parseInt(SiteSettingsBag::get(self::KEY_TIMEOUT, (string) self::DEFAULT_TIMEOUT), 1, 120, self::DEFAULT_TIMEOUT),
            maxResults: self::parseInt(SiteSettingsBag::get(self::KEY_MAX_RESULTS, (string) self::DEFAULT_MAX_RESULTS), 1, 20, self::DEFAULT_MAX_RESULTS),
            searchDepth: self::parseSearchDepth(SiteSettingsBag::get(self::KEY_SEARCH_DEPTH, self::DEFAULT_SEARCH_DEPTH)),
            includeDomains: self::parseCsv(SiteSettingsBag::get(self::KEY_INCLUDE_DOMAINS, '')),
            cacheTtl: self::parseInt(SiteSettingsBag::get(self::KEY_CACHE_TTL, (string) self::DEFAULT_CACHE_TTL), 0, 604800, self::DEFAULT_CACHE_TTL),
        );
    }

    /**
     * 判断当前配置是否足以执行搜索。
     */
    public function isUsable(): bool
    {
        return $this->enabled
            && $this->provider === self::DEFAULT_PROVIDER
            && $this->endpoint !== ''
            && $this->apiKey !== '';
    }

    private static function parseBool(string $raw): bool
    {
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function parseInt(string $raw, int $min, int $max, int $default): int
    {
        $value = (int) trim($raw);
        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }

    private static function parseSearchDepth(string $raw): string
    {
        $value = strtolower(trim($raw));

        return in_array($value, ['basic', 'advanced'], true) ? $value : self::DEFAULT_SEARCH_DEPTH;
    }

    /**
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
}
