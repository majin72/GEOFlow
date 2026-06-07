<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Exceptions\GeoFlow\GeoMonitorSidecarException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Laravel 与 Python GEO Monitor sidecar 的 HTTP 桥接客户端。
 */
class ScraplingBridgeClient
{
    /**
     * @param  GeoMonitorConfig  $config  连接配置
     * @param  HttpFactory  $http  HTTP 客户端工厂
     */
    public function __construct(
        private readonly GeoMonitorConfig $config,
        private readonly HttpFactory $http,
    ) {}

    /**
     * 功能是否已启用且配置了 endpoint。
     */
    public function isOperational(): bool
    {
        return $this->config->isOperational();
    }

    /**
     * 健康检查（无需鉴权）。
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request('GET', '/health', authenticated: false);
    }

    /**
     * 列出 sidecar 支持的平台。
     *
     * @return array<string, mixed>
     */
    public function listPlatforms(): array
    {
        return $this->request('GET', '/v1/platforms');
    }

    /**
     * 检查指定平台账号登录态。
     *
     * @param  string  $platformCode  平台 code
     * @param  string  $accountExternalId  账号 external_id
     * @return array<string, mixed>
     */
    public function checkSession(string $platformCode, string $accountExternalId): array
    {
        $path = '/v1/platforms/'.rawurlencode($platformCode).'/session';

        return $this->request('GET', $path, query: [
            'account_id' => $accountExternalId,
        ]);
    }

    /**
     * 执行单次探测。
     *
     * @param  array<string, mixed>  $payload  请求体
     * @return array<string, mixed> sidecar ProbeResult 字典
     */
    public function probe(array $payload): array
    {
        return $this->request('POST', '/v1/probe', body: $payload);
    }

    /**
     * 启动交互式 profile 维护（sidecar 弹出可见浏览器）。
     *
     * @param  string  $platformCode  平台 code
     * @param  string  $accountExternalId  账号 external_id
     * @param  string  $mode  login 或 captcha
     * @return array<string, mixed>
     */
    public function startMaintenanceSession(
        string $platformCode,
        string $accountExternalId,
        string $mode = 'login',
    ): array {
        return $this->request('POST', '/v1/maintenance/sessions', body: [
            'platform' => $platformCode,
            'account_id' => $accountExternalId,
            'mode' => $mode,
        ]);
    }

    /**
     * 查询交互式维护会话状态。
     *
     * @param  string  $sessionId  sidecar 会话 ID
     * @return array<string, mixed>
     */
    public function getMaintenanceSession(string $sessionId): array
    {
        $path = '/v1/maintenance/sessions/'.rawurlencode($sessionId);

        return $this->request('GET', $path);
    }

    /**
     * 完成交互式维护：关闭浏览器并保存 profile。
     *
     * @param  string  $sessionId  sidecar 会话 ID
     * @return array<string, mixed>
     */
    public function completeMaintenanceSession(string $sessionId): array
    {
        $path = '/v1/maintenance/sessions/'.rawurlencode($sessionId).'/complete';

        return $this->request(
            'POST',
            $path,
            timeoutSeconds: max($this->config->probeTimeoutSeconds, 180),
        );
    }

    /**
     * @param  string  $method  HTTP 方法
     * @param  string  $path  路径
     * @param  array<string, mixed>  $body  POST JSON 体
     * @param  array<string, string>  $query  查询参数
     * @param  bool  $authenticated  是否附带 Token
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        bool $authenticated = true,
        ?int $timeoutSeconds = null,
    ): array {
        if (! $this->config->isOperational()) {
            throw new GeoMonitorSidecarException('GEO 监测 sidecar 未启用或未配置 URL');
        }

        $url = $this->config->sidecarUrl.$path;
        $pending = $this->http->timeout($timeoutSeconds ?? $this->config->probeTimeoutSeconds)
            ->acceptJson()
            ->asJson();

        if ($authenticated && $this->config->sidecarToken !== '') {
            $pending = $pending->withToken($this->config->sidecarToken);
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url, $query),
                'POST' => $pending->post($url, $body),
                default => throw new GeoMonitorSidecarException("不支持的 HTTP 方法: {$method}"),
            };
        } catch (ConnectionException $exception) {
            throw new GeoMonitorSidecarException(
                '无法连接 GEO 监测 sidecar: '.$exception->getMessage(),
                'BROWSER_UNAVAILABLE',
                503,
            );
        }

        return $this->decodeEnvelope($response);
    }

    /**
     * 解析 sidecar 标准 JSON 信封。
     *
     * @param  Response  $response  HTTP 响应
     * @return array<string, mixed>
     */
    private function decodeEnvelope(Response $response): array
    {
        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        if (! is_array($json)) {
            throw new GeoMonitorSidecarException(
                'sidecar 返回非 JSON 响应',
                'INTERNAL_ERROR',
                $response->status(),
            );
        }

        if (($json['ok'] ?? false) === true) {
            $data = $json['data'] ?? [];

            return is_array($data) ? $data : [];
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = (string) ($error['code'] ?? 'INTERNAL_ERROR');
        $message = (string) ($error['message'] ?? 'sidecar 请求失败');
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        throw new GeoMonitorSidecarException($message, $code, $response->status(), $details);
    }
}
