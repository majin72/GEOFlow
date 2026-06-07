<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorProject;

/**
 * 判断引用 URL 是否属于我方或竞品域名。
 */
final class GeoMonitorDomainClassifier
{
    /**
     * @param  GeoMonitorProject  $project  监测项目
     */
    public function __construct(
        private readonly GeoMonitorProject $project,
    ) {}

    /**
     * 从 URL 提取主机名（小写、无 www 前缀）。
     *
     * @param  string  $url  完整 URL
     */
    public function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * 是否为我方主域或子域。
     *
     * @param  string  $domain  已规范化的域名
     */
    public function isOwnDomain(string $domain): bool
    {
        return $this->matchesDomain($domain, (string) $this->project->primary_domain);
    }

    /**
     * 是否为配置的竞品域名。
     *
     * @param  string  $domain  已规范化的域名
     */
    public function isCompetitorDomain(string $domain): bool
    {
        foreach ($this->normalizedCompetitorDomains() as $competitor) {
            if ($this->matchesDomain($domain, $competitor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function normalizedCompetitorDomains(): array
    {
        $domains = $this->project->competitor_domains;

        if (! is_array($domains)) {
            return [];
        }

        $normalized = [];

        foreach ($domains as $domain) {
            if (! is_string($domain) || trim($domain) === '') {
                continue;
            }

            $normalized[] = strtolower(trim($domain));
        }

        return $normalized;
    }

    /**
     * @param  string  $domain  待检测域名
     * @param  string  $configured  配置中的域名（可含协议或路径，仅取 host 部分）
     */
    private function matchesDomain(string $domain, string $configured): bool
    {
        $configured = strtolower(trim($configured));

        if ($configured === '' || $domain === '') {
            return false;
        }

        if (str_contains($configured, '://')) {
            $configured = $this->extractDomain($configured);
        } else {
            $configured = str_starts_with($configured, 'www.') ? substr($configured, 4) : $configured;
        }

        return $domain === $configured
            || str_ends_with($domain, '.'.$configured);
    }
}
