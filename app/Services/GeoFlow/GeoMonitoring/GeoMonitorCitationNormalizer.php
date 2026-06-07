<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorProject;

/**
 * 引用 URL 标准化与域名归属判定。
 */
final class GeoMonitorCitationNormalizer
{
    /**
     * @param  GeoMonitorDomainClassifier  $classifier  域名分类器
     */
    public function __construct(
        private readonly GeoMonitorDomainClassifier $classifier,
    ) {}

    /**
     * 从项目配置构造标准化器。
     *
     * @param  GeoMonitorProject  $project  监测项目
     */
    public static function forProject(GeoMonitorProject $project): self
    {
        return new self(new GeoMonitorDomainClassifier($project));
    }

    /**
     * 规范化引用 URL：去空白、去追踪参数、补全 scheme。
     *
     * @param  string  $url  原始 URL
     */
    public function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '');
        $query = $this->stripTrackingQuery((string) ($parts['query'] ?? ''));

        $normalized = $scheme.'://'.$host.$path;

        if ($query !== '') {
            $normalized .= '?'.$query;
        }

        return mb_substr($normalized, 0, 2000);
    }

    /**
     * 提取并规范化域名。
     *
     * @param  string  $url  原始或已规范 URL
     */
    public function normalizeDomain(string $url): string
    {
        return $this->classifier->extractDomain($this->normalizeUrl($url));
    }

    /**
     * 是否为我方域名。
     *
     * @param  string  $domain  已规范化域名
     */
    public function isOwnDomain(string $domain): bool
    {
        return $this->classifier->isOwnDomain($domain);
    }

    /**
     * 是否为竞品域名。
     *
     * @param  string  $domain  已规范化域名
     */
    public function isCompetitorDomain(string $domain): bool
    {
        return $this->classifier->isCompetitorDomain($domain);
    }

    /**
     * 去掉常见追踪参数，保留业务查询串。
     *
     * @param  string  $query  原始 query（不含 ?）
     */
    private function stripTrackingQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $trackingKeys = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid',
        ];

        $pairs = [];
        parse_str($query, $pairs);

        foreach ($trackingKeys as $key) {
            unset($pairs[$key]);
        }

        if ($pairs === []) {
            return '';
        }

        return http_build_query($pairs);
    }
}
