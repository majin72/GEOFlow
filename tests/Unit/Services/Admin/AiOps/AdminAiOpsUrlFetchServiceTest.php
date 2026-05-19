<?php

namespace Tests\Unit\Services\Admin\AiOps;

use App\Services\Admin\AiOps\AdminAiOpsUrlFetchService;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchResult;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AdminAiOpsUrlFetchServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_rejects_localhost_url(): void
    {
        $service = app(AdminAiOpsUrlFetchService::class);
        $result = $service->fetch('http://127.0.0.1/admin');

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertStringContainsString('内网', (string) ($result['error'] ?? ''));
    }

    public function test_rejects_non_http_scheme(): void
    {
        $service = app(AdminAiOpsUrlFetchService::class);
        $result = $service->fetch('file:///etc/passwd');

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertStringContainsString('http', (string) ($result['error'] ?? ''));
    }

    public function test_fetches_public_url_via_http_client(): void
    {
        Http::fake([
            'https://api.example.com/v1/ping' => Http::response(['status' => 'ok'], 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $service = app(AdminAiOpsUrlFetchService::class);
        $result = $service->fetch('https://api.example.com/v1/ping');

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame(200, $result['status'] ?? null);
        $this->assertStringContainsString('ok', (string) ($result['body_preview'] ?? ''));
        $this->assertSame('http', $result['via'] ?? null);
    }

    public function test_respects_host_allowlist_when_configured(): void
    {
        Config::set('geoflow.admin_ai_ops_url_fetch.allow_hosts', ['allowed.example']);

        $service = app(AdminAiOpsUrlFetchService::class);
        $result = $service->fetch('https://other.example/data');

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertStringContainsString('白名单', (string) ($result['error'] ?? ''));
    }

    public function test_returns_error_when_feature_disabled(): void
    {
        Config::set('geoflow.admin_ai_ops_url_fetch.enabled', false);

        $service = app(AdminAiOpsUrlFetchService::class);
        $result = $service->fetch('https://example.com');

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertStringContainsString('未启用', (string) ($result['error'] ?? ''));
    }

    public function test_uses_external_browser_when_domain_matches(): void
    {
        Config::set('geoflow.external_fetch.enabled', true);
        Config::set('geoflow.external_fetch.domains', ['protected.example']);

        $external = Mockery::mock(ExternalFetchService::class);
        $external->shouldReceive('shouldUseExternal')
            ->once()
            ->with('https://protected.example/page')
            ->andReturn(true);
        $external->shouldReceive('fetch')
            ->once()
            ->andReturn(new ExternalFetchResult(
                markdown: "# Title\n\nBody text",
                format: 'markdown',
                node: 'node-1',
                fetchedAtMillis: 1,
            ));

        $this->app->instance(ExternalFetchService::class, $external);

        $service = app(AdminAiOpsUrlFetchService::class);
        $result = $service->fetch('https://protected.example/page');

        $this->assertTrue((bool) ($result['ok'] ?? true));
        $this->assertSame('external_browser', $result['via'] ?? null);
        $this->assertStringContainsString('Title', (string) ($result['body_preview'] ?? ''));
    }
}
