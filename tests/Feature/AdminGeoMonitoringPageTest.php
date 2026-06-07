<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessGeoMonitorProbeJob;
use App\Models\Admin;
use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorProxyEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminGeoMonitoringPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_geo_monitoring_index(): void
    {
        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.geo-monitoring.index'))
            ->assertOk()
            ->assertSee(__('admin.geo_monitoring.page_heading'))
            ->assertSee(__('admin.geo_monitoring.button_create_project'));
    }

    public function test_admin_can_create_project_with_questions_per_line(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.projects.store'), [
                'name' => '北京租车',
                'slug' => 'zuche',
                'brand_name' => '神州租车',
                'primary_domain' => 'zuche.com',
                'monitoring_questions' => "北京租车推荐\n神州租车怎么样",
                'status' => 'active',
            ])
            ->assertRedirect();

        $project = GeoMonitorProject::query()->where('slug', 'zuche')->first();
        $this->assertNotNull($project);
        $this->assertSame(2, GeoMonitorPrompt::query()->where('project_id', $project->id)->count());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo-monitoring.project', ['projectId' => $project->id]))
            ->assertOk()
            ->assertSee('北京租车推荐')
            ->assertSee('神州租车怎么样')
            ->assertDontSee('批量导入');
    }

    public function test_admin_can_manage_accounts_proxies_and_profiles(): void
    {
        config(['geoflow.geo_monitor.runtime' => 'headless_linux']);

        $admin = $this->createAdmin();

        foreach (['deepseek', 'doubao', 'yuanbao'] as $code) {
            $platform = GeoMonitorPlatform::query()->where('code', $code)->first();
            $this->assertNotNull($platform);

            $this->actingAs($admin, 'admin')
                ->post(route('admin.geo-monitoring.accounts.store'), [
                    'platform_id' => $platform->id,
                    'label' => $code.' 主账号',
                ])
                ->assertRedirect(route('admin.geo-monitoring.accounts.maintenance', [
                    'accountId' => GeoMonitorAccount::query()
                        ->where('platform_id', $platform->id)
                        ->where('label', $code.' 主账号')
                        ->value('id'),
                ]));

            $account = GeoMonitorAccount::query()
                ->with('browserProfile')
                ->where('platform_id', $platform->id)
                ->where('label', $code.' 主账号')
                ->first();

            $this->assertNotNull($account);
            $this->assertSame('needs_login', $account->status);
            $this->assertStringStartsWith($code.'_', $account->external_id);
            $this->assertSame('profiles/'.$account->external_id, $account->profile_storage_path);
            $this->assertNotNull($account->browserProfile);
        }

        $this->assertSame(3, GeoMonitorAccount::query()->count());
    }

    /**
     * 新建账号在有头模式下应自动拉起 sidecar 维护浏览器。
     */
    public function test_store_auto_launches_browser_in_headed_mode(): void
    {
        config([
            'geoflow.geo_monitor.runtime' => 'headed_desktop',
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        Http::fake([
            'http://sidecar.test/v1/maintenance/sessions' => Http::response([
                'ok' => true,
                'data' => [
                    'session_id' => 'sess-auto-01',
                    'status' => 'opening',
                    'profile_path' => 'profiles/doubao_account_01',
                    'chat_url' => 'https://www.doubao.com/chat/',
                ],
            ]),
        ]);

        $admin = $this->createAdmin();
        $platform = GeoMonitorPlatform::query()->where('code', 'doubao')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.accounts.store'), [
                'platform_id' => $platform->id,
                'label' => '豆包测试号',
            ])
            ->assertRedirect(route('admin.geo-monitoring.accounts.maintenance', [
                'accountId' => GeoMonitorAccount::query()->where('label', '豆包测试号')->value('id'),
            ]))
            ->assertSessionHas('geo_monitor_maintenance_session.session_id', 'sess-auto-01');
    }

    public function test_admin_can_trigger_run_and_redirect_to_run_detail(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://127.0.0.1:8765',
        ]);

        Queue::fake();

        $project = GeoMonitorProject::query()->create([
            'name' => 'GEOFlow 监测',
            'slug' => 'geoflow-monitor',
            'brand_name' => 'GEOFlow',
            'primary_domain' => 'example.com',
            'status' => 'active',
        ]);

        GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'brand_generic',
            'prompt_text' => '推荐的企业知识库有哪些？',
            'intent' => 'generic',
            'is_enabled' => true,
        ]);

        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->first();
        $this->assertNotNull($platform);

        GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_account_01',
            'label' => 'DeepSeek 主账号',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_account_01',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.geo-monitoring.runs.store', ['projectId' => $project->id]), [
                'platforms' => ['deepseek'],
            ]);

        $response->assertRedirect();
        Queue::assertPushed(ProcessGeoMonitorProbeJob::class);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'geo_monitor_admin',
            'password' => 'secret-123',
            'email' => 'geo-monitor@example.com',
            'display_name' => 'GEO Monitor Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
