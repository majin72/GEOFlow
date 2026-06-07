<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorBrowserProfile;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProxyEndpoint;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceHealthService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GeoMonitorResourceSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'geoflow.geo_monitor.lock_cache_store' => 'array',
            'geoflow.geo_monitor.account_lock_seconds' => 120,
        ]);
    }

    /**
     * 活跃账号应能被平台池选中。
     */
    public function test_select_for_platform_returns_active_account(): void
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $account = GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_account_01',
            'label' => 'DeepSeek',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_account_01',
        ]);

        $bundle = GeoMonitorResourceScheduler::fromConfig()->selectForPlatform($platform);

        $this->assertNotNull($bundle);
        $this->assertSame($account->id, $bundle->account->id);
        $this->assertSame('pool_least_busy', $bundle->schedulerStrategy);
    }

    /**
     * 冷却中的账号应被跳过。
     */
    public function test_select_skips_account_in_cooldown(): void
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_cooldown',
            'label' => 'Cooldown',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_cooldown',
            'cooldown_until' => now()->addHour(),
        ]);

        $bundle = GeoMonitorResourceScheduler::fromConfig()->selectForPlatform($platform);

        $this->assertNull($bundle);
    }

    /**
     * 降级 Profile 应阻止调度。
     */
    public function test_select_skips_degraded_profile(): void
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'doubao')->firstOrFail();

        $account = GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'doubao_degraded',
            'label' => 'Degraded',
            'status' => 'active',
            'profile_storage_path' => 'profiles/doubao_degraded',
        ]);

        GeoMonitorBrowserProfile::query()->create([
            'account_id' => $account->id,
            'profile_key' => 'doubao_degraded',
            'storage_path' => 'profiles/doubao_degraded',
            'health_status' => 'degraded',
        ]);

        $bundle = GeoMonitorResourceScheduler::fromConfig()->selectForPlatform($platform);

        $this->assertNull($bundle);
    }

    /**
     * 代理冷却应阻止调度。
     */
    public function test_select_skips_proxy_in_cooldown(): void
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'yuanbao')->firstOrFail();

        $proxy = GeoMonitorProxyEndpoint::query()->create([
            'label' => 'Proxy A',
            'proxy_type' => 'http',
            'host' => '127.0.0.1',
            'port' => 1080,
            'status' => 'active',
            'cooldown_until' => now()->addMinutes(30),
        ]);

        GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'yuanbao_proxy_cooldown',
            'label' => 'Proxy cooldown',
            'status' => 'active',
            'profile_storage_path' => 'profiles/yuanbao_proxy_cooldown',
            'proxy_endpoint_id' => $proxy->id,
        ]);

        $bundle = GeoMonitorResourceScheduler::fromConfig()->selectForPlatform($platform);

        $this->assertNull($bundle);
    }

    /**
     * 释放全部账号锁应清空残留锁。
     */
    public function test_release_all_account_locks_clears_stale_locks(): void
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $account = GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_stale_lock',
            'label' => 'Stale lock',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_stale_lock',
        ]);

        $scheduler = GeoMonitorResourceScheduler::fromConfig();

        $this->assertTrue($scheduler->acquireAccountLock($account));
        $this->assertTrue($scheduler->isAccountLocked($account));

        $released = $scheduler->releaseAllAccountLocks();

        $this->assertSame(1, $released);
        $this->assertFalse($scheduler->isAccountLocked($account));
    }

    /**
     * isAccountLocked 探测锁时不应误占锁。
     */
    public function test_is_account_locked_does_not_hold_lock(): void
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $account = GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_lock_probe',
            'label' => 'Lock probe',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_lock_probe',
        ]);

        $scheduler = GeoMonitorResourceScheduler::fromConfig();

        $this->assertFalse($scheduler->isAccountLocked($account));
        $this->assertTrue($scheduler->acquireAccountLock($account));
        $scheduler->releaseAccountLock($account);
    }

    /**
     * 账号串行锁应互斥。
     */
    public function test_account_lock_is_exclusive(): void
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $account = GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_lock',
            'label' => 'Lock test',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_lock',
        ]);

        $scheduler = GeoMonitorResourceScheduler::fromConfig();

        $this->assertTrue($scheduler->acquireAccountLock($account));
        $this->assertFalse($scheduler->acquireAccountLock($account));
        $this->assertTrue($scheduler->isAccountLocked($account));

        $scheduler->releaseAccountLock($account);
        $this->assertTrue($scheduler->acquireAccountLock($account));
    }

    /**
     * 验证码结果应标记维护态并冷却 Profile。
     */
    public function test_health_service_marks_captcha_outcome(): void
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $account = GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_captcha',
            'label' => 'Captcha',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_captcha',
        ]);

        $profile = GeoMonitorBrowserProfile::query()->create([
            'account_id' => $account->id,
            'profile_key' => 'deepseek_captcha',
            'storage_path' => 'profiles/deepseek_captcha',
            'health_status' => 'healthy',
        ]);

        $health = new GeoMonitorResourceHealthService(captchaCooldownMinutes: 60);
        $health->handleProbeOutcome($account, 'captcha_required', '需要验证码');

        $account->refresh();
        $profile->refresh();

        $this->assertSame('needs_maintenance', $account->status);
        $this->assertNotNull($account->cooldown_until);
        $this->assertSame('degraded', $profile->health_status);
    }

    /**
     * 连续失败达阈值后应进入冷却。
     */
    public function test_health_service_cooldown_after_consecutive_failures(): void
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $account = GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_failures',
            'label' => 'Failures',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_failures',
            'meta' => ['consecutive_failures' => 2],
        ]);

        $health = new GeoMonitorResourceHealthService(
            failureCooldownMinutes: 15,
            failuresBeforeCooldown: 3,
        );

        $health->handleProbeOutcome($account, 'failed', 'timeout');

        $account->refresh();

        $this->assertSame('cooldown', $account->status);
        $this->assertNotNull($account->cooldown_until);
    }

    protected function tearDown(): void
    {
        Cache::store('array')->flush();

        parent::tearDown();
    }
}
