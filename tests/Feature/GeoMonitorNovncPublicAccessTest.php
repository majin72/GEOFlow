<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoMonitorNovncPublicAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 公网 noVNC 未启用时，internal 鉴权应拒绝。
     */
    public function test_novnc_auth_returns_forbidden_when_public_disabled(): void
    {
        config([
            'geoflow.geo_monitor.novnc.public_enabled' => false,
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.internal.geo-monitoring.novnc-auth'))
            ->assertForbidden();
    }

    /**
     * 公网 noVNC 已启用且管理员已登录时，internal 鉴权应通过。
     */
    public function test_novnc_auth_returns_no_content_for_logged_in_admin(): void
    {
        config([
            'geoflow.geo_monitor.novnc.public_enabled' => true,
            'geoflow.geo_monitor.novnc.auth_mode' => 'admin_session',
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.internal.geo-monitoring.novnc-auth'))
            ->assertNoContent();
    }

    /**
     * 公网 noVNC 已启用但访客未登录后台时，internal 鉴权应拒绝。
     */
    public function test_novnc_auth_returns_unauthorized_for_guest(): void
    {
        config([
            'geoflow.geo_monitor.novnc.public_enabled' => true,
        ]);

        $this->get(route('admin.internal.geo-monitoring.novnc-auth'))
            ->assertUnauthorized();
    }

    /**
     * 公网 noVNC 模式下维护页应展示公网链接而非 SSH 隧道。
     */
    public function test_maintenance_page_shows_public_novnc_url_without_ssh_tunnel(): void
    {
        config([
            'geoflow.geo_monitor.runtime' => 'headless_linux',
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
            'geoflow.geo_monitor.novnc.public_enabled' => true,
            'geoflow.geo_monitor.novnc.auth_mode' => 'both',
            'geoflow.geo_monitor.novnc.ssh_tunnel_hint_host' => 'ecs-user@203.0.113.10',
            'app.url' => 'https://geo.example.com',
        ]);

        $account = $this->seedAccount();

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]))
            ->assertOk()
            ->assertSee('path=geo-monitor%2Fnovnc%2Fwebsockify')
            ->assertSee('autoconnect=true')
            ->assertSee(__('admin.geo_monitoring.maintenance_interactive_title_novnc_public'))
            ->assertDontSee('ssh -N -L 6080');
    }

    /**
     * @return GeoMonitorAccount
     */
    private function seedAccount(): GeoMonitorAccount
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        return GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_account_01',
            'label' => 'DeepSeek 01',
            'status' => 'needs_login',
            'profile_storage_path' => 'profiles/deepseek_account_01',
        ]);
    }

    /**
     * @return Admin
     */
    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'novnc_admin',
            'password' => bcrypt('secret'),
            'name' => 'Novnc Admin',
            'is_super' => true,
        ]);
    }
}
