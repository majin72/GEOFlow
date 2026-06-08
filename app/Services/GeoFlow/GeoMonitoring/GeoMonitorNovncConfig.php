<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

/**
 * GEO 监测 noVNC 公网访问与鉴权配置。
 */
final class GeoMonitorNovncConfig
{
    public const AUTH_ADMIN_SESSION = 'admin_session';

    public const AUTH_BASIC = 'basic';

    public const AUTH_BOTH = 'both';

    /**
     * @param  bool  $publicEnabled  是否通过站点路径公网暴露 noVNC（经 Nginx 反代）
     * @param  string  $publicPath  公网访问路径前缀（不含尾部斜杠）
     * @param  string  $authMode  鉴权模式：admin_session | basic | both
     * @param  string  $basicUsername  HTTP Basic 用户名
     * @param  string  $basicPassword  HTTP Basic 密码
     * @param  string  $upstreamHost  sidecar 内网上游 host:port
     */
    public function __construct(
        public readonly bool $publicEnabled,
        public readonly string $publicPath,
        public readonly string $authMode,
        public readonly string $basicUsername,
        public readonly string $basicPassword,
        public readonly string $upstreamHost,
    ) {}

    /**
     * 从应用配置构造。
     */
    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $novnc */
        $novnc = config('geoflow.geo_monitor.novnc', []);

        $path = trim((string) ($novnc['public_path'] ?? '/geo-monitor/novnc'), '/');
        $authMode = (string) ($novnc['auth_mode'] ?? self::AUTH_ADMIN_SESSION);
        if (! in_array($authMode, [self::AUTH_ADMIN_SESSION, self::AUTH_BASIC, self::AUTH_BOTH], true)) {
            $authMode = self::AUTH_ADMIN_SESSION;
        }

        return new self(
            publicEnabled: filter_var($novnc['public_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            publicPath: '/'.$path,
            authMode: $authMode,
            basicUsername: (string) ($novnc['basic_username'] ?? ''),
            basicPassword: (string) ($novnc['basic_password'] ?? ''),
            upstreamHost: (string) ($novnc['upstream_host'] ?? 'geo-monitor-sidecar:6080'),
        );
    }

    /**
     * 是否允许已登录后台管理员访问 noVNC。
     */
    public function allowsAdminSession(): bool
    {
        return in_array($this->authMode, [self::AUTH_ADMIN_SESSION, self::AUTH_BOTH], true);
    }

    /**
     * 是否启用 HTTP Basic 鉴权（由 Nginx 校验）。
     */
    public function allowsBasicAuth(): bool
    {
        return in_array($this->authMode, [self::AUTH_BASIC, self::AUTH_BOTH], true)
            && $this->basicUsername !== ''
            && $this->basicPassword !== '';
    }

    /**
     * 经 Nginx 反代时 noVNC WebSocket 路径（相对站点根，无 leading slash）。
     */
    public function publicWebsocketPath(): string
    {
        return trim($this->publicPath, '/').'/websockify';
    }

    /**
     * 公网 noVNC 页面完整 URL（含子路径反代所需的 WebSocket path 参数）。
     */
    public function publicVncUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/').$this->publicPath.'/vnc.html';
        $appUrl = (string) config('app.url');
        $query = [
            'path' => $this->publicWebsocketPath(),
            'autoconnect' => 'true',
            'resize' => 'scale',
        ];

        if (str_starts_with(strtolower($appUrl), 'https://')) {
            $query['encrypt'] = '1';
        }

        return $base.'?'.http_build_query($query);
    }

    /**
     * 后台 internal 鉴权路径（供 Nginx auth_request 调用）。
     */
    public function internalAuthPath(): string
    {
        $adminPrefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

        return '/'.$adminPrefix.'/internal/geo-monitor/novnc-auth';
    }

    /**
     * 鉴权模式后台展示文案键。
     */
    public function authModeLabelKey(): string
    {
        return match ($this->authMode) {
            self::AUTH_BASIC => 'admin.geo_monitoring.novnc_auth_mode_basic',
            self::AUTH_BOTH => 'admin.geo_monitoring.novnc_auth_mode_both',
            default => 'admin.geo_monitoring.novnc_auth_mode_admin',
        };
    }
}
