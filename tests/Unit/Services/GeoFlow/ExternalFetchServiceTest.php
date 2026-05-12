<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GeoFlow;

use App\Exceptions\GeoFlow\ExternalFetchException;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchConfig;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 覆盖 ExternalFetchService 的协议层职责：
 * - 启用判定（isEnabled / shouldUseExternal 受 enabled+endpoint 联合控制）
 * - 域名白名单匹配（含子域名、防后缀绕过）
 * - fallback 状态码识别
 * - HTTP 调用成功 / 各类失败路径
 * - Bearer token 透传
 */
class ExternalFetchServiceTest extends TestCase
{
    private const ENDPOINT = 'http://bridge.local:19826';

    /**
     * 构造可注入参数的服务实例；未覆盖的参数走默认值，便于单测聚焦关注点。
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeService(array $overrides = []): ExternalFetchService
    {
        $config = new ExternalFetchConfig(
            enabled: $overrides['enabled'] ?? true,
            endpoint: $overrides['endpoint'] ?? self::ENDPOINT,
            token: $overrides['token'] ?? 'secret-token',
            timeout: $overrides['timeout'] ?? 30,
            domains: $overrides['domains'] ?? ['zhihu.com', 'mp.weixin.qq.com'],
            retryOnStatus: $overrides['retryOnStatus'] ?? [403, 429],
        );

        return new ExternalFetchService($config, app(HttpFactory::class));
    }

    public function test_is_enabled_returns_false_when_flag_off(): void
    {
        $service = $this->makeService(['enabled' => false]);

        $this->assertFalse($service->isEnabled());
    }

    public function test_is_enabled_returns_false_when_endpoint_empty(): void
    {
        $service = $this->makeService(['endpoint' => '']);

        $this->assertFalse($service->isEnabled());
    }

    public function test_should_use_external_matches_subdomain(): void
    {
        $service = $this->makeService();

        $this->assertTrue($service->shouldUseExternal('https://zhuanlan.zhihu.com/p/123'));
        $this->assertTrue($service->shouldUseExternal('https://www.zhihu.com/question/1'));
    }

    public function test_should_use_external_matches_exact_root_domain(): void
    {
        $service = $this->makeService();

        $this->assertTrue($service->shouldUseExternal('https://zhihu.com/some'));
    }

    public function test_should_use_external_prevents_suffix_bypass(): void
    {
        $service = $this->makeService();

        // "fake-zhihu.com" 不能被白名单 "zhihu.com" 命中（防止以后缀绕过）
        $this->assertFalse($service->shouldUseExternal('https://fake-zhihu.com/p/1'));
    }

    public function test_should_use_external_misses_unrelated_domain(): void
    {
        $service = $this->makeService();

        $this->assertFalse($service->shouldUseExternal('https://example.com'));
    }

    public function test_should_use_external_returns_false_when_disabled(): void
    {
        $service = $this->makeService(['enabled' => false]);

        $this->assertFalse($service->shouldUseExternal('https://zhihu.com/p/1'));
    }

    public function test_is_fallback_status_recognises_configured_codes(): void
    {
        $service = $this->makeService();

        $this->assertTrue($service->isFallbackStatus(403));
        $this->assertTrue($service->isFallbackStatus(429));
        $this->assertFalse($service->isFallbackStatus(200));
        $this->assertFalse($service->isFallbackStatus(500));
    }

    public function test_is_fallback_status_returns_false_when_disabled(): void
    {
        $service = $this->makeService(['enabled' => false]);

        $this->assertFalse($service->isFallbackStatus(403));
    }

    public function test_fetch_throws_when_service_disabled(): void
    {
        $service = $this->makeService(['enabled' => false]);

        $this->expectException(ExternalFetchException::class);
        $service->fetch('https://zhihu.com/p/1');
    }

    public function test_fetch_returns_result_on_success(): void
    {
        Http::fake([
            self::ENDPOINT.'/fetch' => Http::response([
                'markdown' => '# Title',
                'format' => 'markdown',
                'node' => 'mac-laptop',
                'fetched_at' => 1_714_896_000_000,
            ], 200),
        ]);

        $service = $this->makeService();

        $result = $service->fetch('https://zhihu.com/p/1');

        $this->assertSame('# Title', $result->markdown);
        $this->assertSame('markdown', $result->format);
        $this->assertSame('mac-laptop', $result->node);
        $this->assertSame(1_714_896_000_000, $result->fetchedAtMillis);
    }

    public function test_fetch_sends_bearer_token_when_configured(): void
    {
        Http::fake([
            self::ENDPOINT.'/fetch' => Http::response(['markdown' => '# ok'], 200),
        ]);

        $service = $this->makeService(['token' => 'top-secret']);
        $service->fetch('https://zhihu.com/p/1');

        Http::assertSent(static function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer top-secret');
        });
    }

    public function test_fetch_omits_authorization_header_when_token_empty(): void
    {
        Http::fake([
            self::ENDPOINT.'/fetch' => Http::response(['markdown' => '# ok'], 200),
        ]);

        $service = $this->makeService(['token' => '']);
        $service->fetch('https://zhihu.com/p/1');

        Http::assertSent(static function (Request $request): bool {
            return ! $request->hasHeader('Authorization');
        });
    }

    public function test_fetch_throws_on_bridge_non_2xx_response(): void
    {
        Http::fake([
            self::ENDPOINT.'/fetch' => Http::response(['error' => 'busy'], 503),
        ]);

        $service = $this->makeService();

        $this->expectException(ExternalFetchException::class);
        $service->fetch('https://zhihu.com/p/1');
    }

    public function test_fetch_throws_on_invalid_json_body(): void
    {
        Http::fake([
            self::ENDPOINT.'/fetch' => Http::response('not-a-json-object', 200),
        ]);

        $service = $this->makeService();

        $this->expectException(ExternalFetchException::class);
        $service->fetch('https://zhihu.com/p/1');
    }

    public function test_fetch_throws_on_missing_markdown_field(): void
    {
        Http::fake([
            self::ENDPOINT.'/fetch' => Http::response(['format' => 'markdown'], 200),
        ]);

        $service = $this->makeService();

        $this->expectException(ExternalFetchException::class);
        $service->fetch('https://zhihu.com/p/1');
    }
}
