<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProxyEndpoint;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorSidecarAccountsExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GeoMonitorSidecarAccountsExporterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 导出 accounts.json 应包含代理 URL 与 profile 路径。
     */
    public function test_export_writes_accounts_json_with_proxy(): void
    {
        $pocRoot = storage_path('framework/testing/geo-monitor-poc');
        File::ensureDirectoryExists($pocRoot);

        config([
            'geoflow.geo_monitor.novnc.poc_root' => $pocRoot,
        ]);

        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $proxy = GeoMonitorProxyEndpoint::query()->create([
            'label' => '测试代理',
            'proxy_type' => 'http',
            'host' => '10.0.0.8',
            'port' => 7890,
            'status' => 'active',
            'meta' => ['username' => 'user', 'password' => 'secret'],
        ]);

        GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_account_01',
            'label' => 'DeepSeek',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_account_01',
            'proxy_endpoint_id' => $proxy->id,
        ]);

        $path = app(GeoMonitorSidecarAccountsExporter::class)->exportToPocRoot();

        $this->assertFileExists($path);

        /** @var array{accounts: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $payload['accounts']);
        $this->assertSame('deepseek_account_01', $payload['accounts'][0]['id']);
        $this->assertSame('./profiles/deepseek_account_01', $payload['accounts'][0]['profile_dir']);
        $this->assertStringContainsString('10.0.0.8:7890', (string) $payload['accounts'][0]['proxy']);

        File::deleteDirectory($pocRoot);
    }
}
