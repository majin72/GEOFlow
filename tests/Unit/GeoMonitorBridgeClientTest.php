<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\GeoFlow\GeoMonitorSidecarException;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorConfig;
use App\Services\GeoFlow\GeoMonitoring\ScraplingBridgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoMonitorBridgeClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_returns_data_envelope(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        Http::fake([
            'http://sidecar.test/health' => Http::response([
                'ok' => true,
                'data' => ['service' => 'geo-monitor-sidecar', 'version' => '0.1.0'],
            ]),
        ]);

        $client = new ScraplingBridgeClient(
            GeoMonitorConfig::fromConfig(),
            app(HttpFactory::class),
        );

        $this->assertSame('geo-monitor-sidecar', $client->health()['service']);
    }

    public function test_probe_throws_on_sidecar_error_envelope(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        Http::fake([
            'http://sidecar.test/v1/probe' => Http::response([
                'ok' => false,
                'error' => [
                    'code' => 'PROBE_TIMEOUT',
                    'message' => '探测超时',
                ],
            ], 408),
        ]);

        $client = new ScraplingBridgeClient(
            GeoMonitorConfig::fromConfig(),
            app(HttpFactory::class),
        );

        $this->expectException(GeoMonitorSidecarException::class);
        $this->expectExceptionMessage('探测超时');

        $client->probe(['platform' => 'doubao', 'account_id' => 'doubao_account_01', 'prompt_text' => 'test']);
    }

    public function test_probe_returns_probe_result_dict(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        Http::fake([
            'http://sidecar.test/v1/probe' => Http::response([
                'ok' => true,
                'data' => [
                    'platform' => 'deepseek',
                    'status' => 'success',
                    'login_status' => 'logged_in',
                    'answer_text' => '回答正文',
                    'citations' => [
                        ['url' => 'https://example.com/a', 'title' => 'A', 'position' => 1],
                    ],
                    'evidence' => ['html_path' => '/tmp/a.html'],
                    'duration_ms' => 900,
                    'meta' => ['resource' => ['account_id' => 'deepseek_account_01']],
                ],
            ]),
        ]);

        $client = new ScraplingBridgeClient(
            GeoMonitorConfig::fromConfig(),
            app(HttpFactory::class),
        );

        $result = $client->probe([
            'platform' => 'deepseek',
            'account_id' => 'deepseek_account_01',
            'prompt_text' => 'GEOFlow 是什么？',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $result['citations']);
    }
}
