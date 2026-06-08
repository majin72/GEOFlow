<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorBrowserProfile;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorProxyEndpoint;
use App\Models\GeoMonitorRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoMonitorResourceDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_account_and_cascade_profile(): void
    {
        $admin = $this->createAdmin();
        $account = $this->createAccountWithProfile();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.accounts.delete', ['accountId' => $account->id]))
            ->assertRedirect(route('admin.geo-monitoring.accounts.index'))
            ->assertSessionHas('message', __('admin.geo_monitoring.message.account_deleted'));

        $this->assertNull(GeoMonitorAccount::query()->find($account->id));
        $this->assertSame(0, GeoMonitorBrowserProfile::query()->count());
    }

    public function test_admin_cannot_delete_account_with_active_observations(): void
    {
        $admin = $this->createAdmin();
        $account = $this->createAccountWithProfile();
        $this->createObservationForAccount($account, 'running');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.accounts.delete', ['accountId' => $account->id]))
            ->assertRedirect(route('admin.geo-monitoring.accounts.index'))
            ->assertSessionHasErrors();

        $this->assertNotNull(GeoMonitorAccount::query()->find($account->id));
    }

    public function test_admin_can_delete_proxy_without_bound_accounts(): void
    {
        $admin = $this->createAdmin();
        $proxy = GeoMonitorProxyEndpoint::query()->create([
            'label' => '测试代理',
            'proxy_type' => 'http',
            'host' => '127.0.0.1',
            'port' => 8080,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.proxies.delete', ['proxyId' => $proxy->id]))
            ->assertRedirect(route('admin.geo-monitoring.proxies.index'))
            ->assertSessionHas('message', __('admin.geo_monitoring.message.proxy_deleted'));

        $this->assertNull(GeoMonitorProxyEndpoint::query()->find($proxy->id));
    }

    public function test_admin_cannot_delete_proxy_with_bound_accounts(): void
    {
        $admin = $this->createAdmin();
        $proxy = GeoMonitorProxyEndpoint::query()->create([
            'label' => '绑定代理',
            'proxy_type' => 'http',
            'host' => '10.0.0.1',
            'port' => 3128,
            'status' => 'active',
        ]);

        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();
        GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_bound_proxy',
            'label' => '绑定代理账号',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_bound_proxy',
            'proxy_endpoint_id' => $proxy->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.proxies.delete', ['proxyId' => $proxy->id]))
            ->assertRedirect(route('admin.geo-monitoring.proxies.index'))
            ->assertSessionHasErrors();

        $this->assertNotNull(GeoMonitorProxyEndpoint::query()->find($proxy->id));
    }

    public function test_admin_can_delete_browser_profile_while_account_remains(): void
    {
        $admin = $this->createAdmin();
        $account = $this->createAccountWithProfile();
        $profileId = $account->browserProfile?->id;
        $this->assertNotNull($profileId);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.profiles.delete', ['profileId' => $profileId]))
            ->assertRedirect(route('admin.geo-monitoring.profiles.index'))
            ->assertSessionHas('message', __('admin.geo_monitoring.message.profile_deleted'));

        $this->assertNotNull(GeoMonitorAccount::query()->find($account->id));
        $this->assertNull(GeoMonitorBrowserProfile::query()->find($profileId));
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'geo_delete_admin',
            'password' => 'secret-123',
            'email' => 'geo-delete@example.com',
            'display_name' => 'GEO Delete Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function createAccountWithProfile(): GeoMonitorAccount
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $account = GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_delete_test',
            'label' => '待删除账号',
            'status' => 'disabled',
            'profile_storage_path' => 'profiles/deepseek_delete_test',
        ]);

        GeoMonitorBrowserProfile::query()->create([
            'account_id' => $account->id,
            'profile_key' => $account->external_id,
            'storage_path' => $account->profile_storage_path,
            'health_status' => 'unknown',
        ]);

        return $account->fresh(['browserProfile']) ?? $account;
    }

    private function createObservationForAccount(GeoMonitorAccount $account, string $status): GeoMonitorObservation
    {
        $project = GeoMonitorProject::query()->create([
            'name' => '删除测试项目',
            'slug' => 'delete-test',
            'brand_name' => 'Test',
            'primary_domain' => 'example.com',
            'status' => 'active',
        ]);

        $prompt = GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'delete_prompt',
            'prompt_text' => '测试问题',
            'intent' => 'generic',
            'is_enabled' => true,
        ]);

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'running',
            'prompt_count' => 1,
            'observation_count' => 1,
        ]);

        return GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $account->platform_id,
            'account_id' => $account->id,
            'prompt_text_snapshot' => '测试问题',
            'status' => $status,
        ]);
    }
}
