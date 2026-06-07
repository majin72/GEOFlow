<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

/**
 * GEO 监测 sidecar 连接配置（来自 config/geoflow.php）。
 */
final class GeoMonitorConfig
{
    /**
     * @param  bool  $enabled  功能总开关
     * @param  string  $sidecarUrl  sidecar 根 URL
     * @param  string  $sidecarToken  Bearer Token
     * @param  int  $probeTimeoutSeconds  HTTP 超时秒数
     * @param  string  $evidenceDisk  证据存储 disk 名
     * @param  string  $evidencePathPrefix  证据相对路径前缀
     * @param  string  $evidenceRoot  sidecar 证据根目录绝对路径
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $sidecarUrl,
        public readonly string $sidecarToken,
        public readonly int $probeTimeoutSeconds,
        public readonly string $evidenceDisk,
        public readonly string $evidencePathPrefix,
        public readonly string $evidenceRoot,
    ) {}

    /**
     * 从应用配置构造实例。
     */
    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('geoflow.geo_monitor', []);

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            sidecarUrl: rtrim((string) ($config['sidecar_url'] ?? ''), '/'),
            sidecarToken: (string) ($config['sidecar_token'] ?? ''),
            probeTimeoutSeconds: max(30, (int) ($config['probe_timeout_seconds'] ?? 150)),
            evidenceDisk: (string) ($config['evidence_disk'] ?? 'local'),
            evidencePathPrefix: trim((string) ($config['evidence_path_prefix'] ?? 'geo-monitor/evidence'), '/'),
            evidenceRoot: rtrim((string) ($config['evidence_root'] ?? base_path('tools/geo-monitor-poc/evidence/sidecar')), '/'),
        );
    }

    /**
     * 是否具备调用 sidecar 的最低配置。
     */
    public function isOperational(): bool
    {
        return $this->enabled && $this->sidecarUrl !== '';
    }
}
