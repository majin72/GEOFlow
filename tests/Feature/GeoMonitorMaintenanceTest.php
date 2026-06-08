<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProfileMaintenanceEvent;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoMonitorMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * sidecar 未启用时，无头 Linux 维护页应展示手动 noVNC 命令。
     */
    public function test_maintenance_page_renders_novnc_commands_when_sidecar_disabled(): void
    {
        config([
            'geoflow.geo_monitor.runtime' => 'headless_linux',
            'geoflow.geo_monitor.enabled' => false,
        ]);

        $account = $this->seedAccount('needs_maintenance');

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]))
            ->assertOk()
            ->assertSee('start-novnc.sh')
            ->assertSee('scripts/novnc/maintain-profile.sh')
            ->assertDontSee(__('admin.geo_monitoring.maintenance_launch_browser_button'))
            ->assertSee($account->external_id);
    }

    /**
     * Docker 生产（headless_linux + sidecar）应展示后台一键登录，而非服务器 SSH 命令。
     */
    public function test_maintenance_page_shows_one_click_login_in_headless_with_sidecar(): void
    {
        config([
            'geoflow.geo_monitor.runtime' => 'headless_linux',
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
            'geoflow.geo_monitor.novnc.ssh_tunnel_hint_host' => 'ecs-user@203.0.113.10',
        ]);

        $account = $this->seedAccount('needs_login');

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]))
            ->assertOk()
            ->assertSee(__('admin.geo_monitoring.maintenance_interactive_title_novnc'))
            ->assertSee(__('admin.geo_monitoring.maintenance_launch_browser_button'))
            ->assertSee('ssh -N -L 6080:127.0.0.1:6080 ecs-user@203.0.113.10')
            ->assertDontSee('start-novnc.sh')
            ->assertDontSee('maintain-profile.sh');
    }

    /**
     * 有头桌面模式维护页应展示 headed 脚本而非 noVNC。
     */
    public function test_maintenance_page_renders_headed_commands_in_desktop_mode(): void
    {
        config([
            'geoflow.geo_monitor.runtime' => 'headed_desktop',
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://127.0.0.1:8765',
        ]);

        $account = $this->seedAccount('needs_maintenance');

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]))
            ->assertOk()
            ->assertSee(__('admin.geo_monitoring.maintenance_launch_browser_button'))
            ->assertDontSee('start-novnc.sh');
    }

    /**
     * 开始维护应创建事件并锁定账号。
     */
    public function test_begin_maintenance_creates_event(): void
    {
        config(['geoflow.geo_monitor.runtime' => 'headless_linux']);

        $admin = $this->createAdmin();
        $account = $this->seedAccount('active');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.accounts.maintenance.start', ['accountId' => $account->id]), [
                'trigger_reason' => 'captcha_required',
            ])
            ->assertRedirect(route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]));

        $account->refresh();

        $this->assertSame('needs_maintenance', $account->status);
        $this->assertSame(1, GeoMonitorProfileMaintenanceEvent::query()->where('account_id', $account->id)->count());

        $event = GeoMonitorProfileMaintenanceEvent::query()->where('account_id', $account->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('in_progress', $event->status);
        $this->assertSame('novnc', $event->maintenance_via); // default headless_linux
        $this->assertSame($admin->id, $event->operator_admin_id);
    }

    /**
     * 无头 Linux + sidecar 也应能一键拉起维护浏览器。
     */
    public function test_launch_browser_works_in_headless_linux_with_sidecar(): void
    {
        config([
            'geoflow.geo_monitor.runtime' => 'headless_linux',
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
            'geoflow.geo_monitor.novnc.public_enabled' => true,
            'app.url' => 'https://geo.example.com',
        ]);

        $admin = $this->createAdmin();
        $account = $this->seedAccount('needs_login');

        Http::fake([
            'http://sidecar.test/v1/maintenance/sessions' => Http::response([
                'ok' => true,
                'data' => [
                    'session_id' => 'sess-headless-01',
                    'status' => 'opening',
                    'profile_path' => 'profiles/deepseek_account_01',
                    'chat_url' => 'https://chat.deepseek.com/',
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.accounts.maintenance.launch-browser', ['accountId' => $account->id]))
            ->assertRedirect(route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]))
            ->assertSessionHas('geo_monitor_maintenance_session')
            ->assertSessionHas('geo_monitor_open_novnc_url', 'https://geo.example.com/geo-monitor/novnc/vnc.html');
    }

    /**
     * 后台一键拉起浏览器应调用 sidecar 维护会话 API。
     */
    public function test_launch_browser_starts_sidecar_maintenance_session(): void
    {
        config([
            'geoflow.geo_monitor.runtime' => 'headed_desktop',
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        $admin = $this->createAdmin();
        $account = $this->seedAccount('needs_login');

        Http::fake([
            'http://sidecar.test/v1/maintenance/sessions' => Http::response([
                'ok' => true,
                'data' => [
                    'session_id' => 'sess-test-01',
                    'status' => 'opening',
                    'profile_path' => 'profiles/deepseek_account_01',
                    'chat_url' => 'https://chat.deepseek.com/',
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.accounts.maintenance.launch-browser', ['accountId' => $account->id]))
            ->assertRedirect(route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]))
            ->assertSessionHas('geo_monitor_maintenance_session.session_id', 'sess-test-01');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://sidecar.test/v1/maintenance/sessions'
                && $request['platform'] === 'deepseek'
                && $request['account_id'] === 'deepseek_account_01'
                && $request['mode'] === 'login';
        });
    }

    /**
     * 保存 Profile 应完成 sidecar 会话并清除 flash session。
     */
    public function test_save_browser_completes_sidecar_session(): void
    {
        config([
            'geoflow.geo_monitor.runtime' => 'headed_desktop',
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        $admin = $this->createAdmin();
        $account = $this->seedAccount('needs_login');

        Http::fake([
            'http://sidecar.test/v1/maintenance/sessions/sess-save-01/complete' => Http::response([
                'ok' => true,
                'data' => [
                    'session_id' => 'sess-save-01',
                    'status' => 'closed',
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['geo_monitor_maintenance_session' => ['session_id' => 'sess-save-01']])
            ->post(route('admin.geo-monitoring.accounts.maintenance.save-browser', ['accountId' => $account->id]), [
                'session_id' => 'sess-save-01',
            ])
            ->assertRedirect(route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]))
            ->assertSessionHas('message')
            ->assertSessionMissing('geo_monitor_maintenance_session');
    }

    /**
     * 健康检查通过并完成维护应恢复 active。
     */
    public function test_complete_maintenance_restores_active_when_health_passed(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        $admin = $this->createAdmin();
        $account = $this->seedAccount('needs_maintenance');

        app(GeoMonitorMaintenanceService::class)->beginMaintenance($account, $admin, 'captcha_required');

        Http::fake([
            'http://sidecar.test/v1/platforms/deepseek/session*' => Http::response([
                'ok' => true,
                'data' => ['login_status' => 'logged_in'],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.accounts.maintenance.complete', ['accountId' => $account->id]), [
                'notes' => '验证码已人工处理',
            ])
            ->assertRedirect(route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]));

        $account->refresh();

        $this->assertSame('active', $account->status);
        $this->assertSame('succeeded', GeoMonitorProfileMaintenanceEvent::query()
            ->where('account_id', $account->id)
            ->value('status'));
    }

    /**
     * 重复开始维护不应产生多条 in_progress 事件。
     */
    public function test_begin_maintenance_is_idempotent(): void
    {
        $admin = $this->createAdmin();
        $account = $this->seedAccount('needs_maintenance');
        $service = app(GeoMonitorMaintenanceService::class);

        $service->beginMaintenance($account, $admin, 'captcha_required');
        $service->beginMaintenance($account, $admin, 'captcha_required');

        $this->assertSame(1, GeoMonitorProfileMaintenanceEvent::query()
            ->where('account_id', $account->id)
            ->where('status', 'in_progress')
            ->count());
    }

    /**
     * 健康检查未通过时完成维护不应恢复 active。
     */
    public function test_complete_maintenance_keeps_pending_when_health_fails(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        $admin = $this->createAdmin();
        $account = $this->seedAccount('needs_maintenance');
        $service = app(GeoMonitorMaintenanceService::class);
        $service->beginMaintenance($account, $admin, 'captcha_required');

        Http::fake([
            'http://sidecar.test/v1/platforms/deepseek/session*' => Http::response([
                'ok' => true,
                'data' => ['login_status' => 'not_logged_in'],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.accounts.maintenance.complete', ['accountId' => $account->id]))
            ->assertRedirect(route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]));

        $account->refresh();

        $this->assertSame('needs_maintenance', $account->status);
        $this->assertSame('failed', GeoMonitorProfileMaintenanceEvent::query()
            ->where('account_id', $account->id)
            ->value('status'));
    }

    /**
     * Sidecar 会话检查应解析登录态。
     */
    public function test_health_check_uses_sidecar_session_endpoint(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        $account = $this->seedAccount('needs_login');

        Http::fake([
            'http://sidecar.test/v1/platforms/deepseek/session*' => Http::response([
                'ok' => true,
                'data' => ['login_status' => 'logged_in', 'duration_ms' => 1200],
            ]),
        ]);

        $result = app(GeoMonitorMaintenanceService::class)->runHealthCheck($account);

        $this->assertTrue($result['ok']);
        $this->assertSame('logged_in', $result['login_status']);
    }

    /**
     * @param  string  $status  账号状态
     */
    private function seedAccount(string $status): GeoMonitorAccount
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        return GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_account_01',
            'label' => 'DeepSeek 维护测试',
            'status' => $status,
            'profile_storage_path' => 'profiles/deepseek_account_01',
            'last_error_message' => '需要验证码',
        ]);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'geo-maint',
            'password' => bcrypt('secret'),
            'name' => '维护测试',
            'status' => 'active',
        ]);
    }
}
