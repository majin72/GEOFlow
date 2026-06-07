<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorBrowserProfile;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProxyEndpoint;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 多账号 / Profile / 代理池调度：按健康、冷却、额度与并发锁选取资源。
 */
class GeoMonitorResourceScheduler
{
    /**
     * @param  int  $accountLockSeconds  单账号串行锁 TTL
     */
    public function __construct(
        private readonly int $accountLockSeconds = 300,
    ) {}

    /**
     * 从配置构造调度器。
     */
    public static function fromConfig(): self
    {
        $seconds = max(60, (int) config('geoflow.geo_monitor.account_lock_seconds', 300));

        return new self(accountLockSeconds: $seconds);
    }

    /**
     * 为平台选取当前最优可调度资源包。
     *
     * @param  GeoMonitorPlatform  $platform  目标平台
     */
    public function selectForPlatform(GeoMonitorPlatform $platform): ?GeoMonitorResourceBundle
    {
        $candidates = GeoMonitorAccount::query()
            ->with(['browserProfile', 'proxyEndpoint'])
            ->where('platform_id', $platform->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (GeoMonitorAccount $account): bool => $this->isAccountSchedulable($account));

        if ($candidates->isEmpty()) {
            return null;
        }

        $account = $this->pickLeastBusyAccount($candidates);

        return $this->bundleForAccount($account, 'pool_least_busy');
    }

    /**
     * 将已绑定账号组装为资源包（需仍满足调度条件）。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @param  string  $strategy  调度策略
     */
    public function bundleForAccount(
        GeoMonitorAccount $account,
        string $strategy = 'pinned_account',
    ): ?GeoMonitorResourceBundle {
        $account->loadMissing(['browserProfile', 'proxyEndpoint']);

        if (! $this->isAccountSchedulable($account)) {
            return null;
        }

        return new GeoMonitorResourceBundle(
            account: $account,
            profile: $account->browserProfile,
            proxy: $this->resolveProxy($account),
            schedulerStrategy: $strategy,
        );
    }

    /**
     * 账号是否可被派发探测。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    public function isAccountSchedulable(GeoMonitorAccount $account): bool
    {
        if ($account->status !== 'active') {
            return false;
        }

        if ($this->isCoolingDown($account->cooldown_until)) {
            return false;
        }

        if (! $this->isProfileSchedulable($account->browserProfile)) {
            return false;
        }

        $proxy = $account->proxyEndpoint;

        if ($proxy !== null && ! $this->isProxySchedulable($proxy)) {
            return false;
        }

        if ($this->isHourlyQuotaExceeded($account)) {
            return false;
        }

        if ($this->isDailyQuotaExceeded($account)) {
            return false;
        }

        return true;
    }

    /**
     * 尝试获取账号串行锁，避免同一 Profile 并发探测。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    public function acquireAccountLock(GeoMonitorAccount $account): bool
    {
        return $this->cache()
            ->lock($this->lockKey($account->id), $this->accountLockSeconds)
            ->get();
    }

    /**
     * 释放账号串行锁。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    public function releaseAccountLock(GeoMonitorAccount $account): void
    {
        $this->forceReleaseLockKey($this->lockKey($account->id));
    }

    /**
     * 账号是否正被其他 Job 占用（只读检测，不尝试抢锁）。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    public function isAccountLocked(GeoMonitorAccount $account): bool
    {
        return $this->lockKeyExists($this->lockKey($account->id));
    }

    /**
     * 释放所有账号串行锁，用于清理历史残留锁。
     *
     * @return int 已释放的锁数量
     */
    public function releaseAllAccountLocks(): int
    {
        $released = 0;

        foreach (GeoMonitorAccount::query()->pluck('id') as $accountId) {
            $key = $this->lockKey((int) $accountId);

            if (! $this->lockKeyExists($key)) {
                continue;
            }

            $this->forceReleaseLockKey($key);
            $released++;
        }

        return $released;
    }

    /**
     * 统计账号运行态指标，供后台资源池页展示。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @return array{
     *     schedulable: bool,
     *     locked: bool,
     *     lock_orphaned: bool,
     *     running_observations: int,
     *     hourly_usage: int,
     *     daily_usage: int,
     *     cooldown_until: string|null
     * }
     */
    public function accountRuntimeStats(GeoMonitorAccount $account): array
    {
        $locked = $this->isAccountLocked($account);
        $runningObservations = $this->runningObservationCount($account->id);

        return [
            'schedulable' => $this->isAccountSchedulable($account),
            'locked' => $locked,
            'lock_orphaned' => $locked && $runningObservations === 0,
            'running_observations' => $runningObservations,
            'hourly_usage' => $this->usageCount($account->id, now()->subHour()),
            'daily_usage' => $this->usageCount($account->id, now()->subDay()),
            'cooldown_until' => $account->cooldown_until?->toIso8601String(),
        ];
    }

    /**
     * 在候选账号中选取当前最空闲的一个。
     *
     * @param  Collection<int, GeoMonitorAccount>  $candidates  候选账号
     */
    private function pickLeastBusyAccount(Collection $candidates): GeoMonitorAccount
    {
        return $candidates
            ->sortBy(fn (GeoMonitorAccount $account): array => [
                $this->runningObservationCount($account->id),
                $this->usageCount($account->id, now()->subHour()),
                $account->id,
            ])
            ->first();
    }

    /**
     * 解析账号实际使用的代理（绑定代理优先）。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    private function resolveProxy(GeoMonitorAccount $account): ?GeoMonitorProxyEndpoint
    {
        $proxy = $account->proxyEndpoint;

        if ($proxy === null) {
            return null;
        }

        return $this->isProxySchedulable($proxy) ? $proxy : null;
    }

    /**
     * Profile 是否允许调度。
     *
     * @param  GeoMonitorBrowserProfile|null  $profile  浏览器 Profile
     */
    private function isProfileSchedulable(?GeoMonitorBrowserProfile $profile): bool
    {
        if ($profile === null) {
            return true;
        }

        return in_array($profile->health_status, ['unknown', 'healthy'], true);
    }

    /**
     * 代理是否允许调度。
     *
     * @param  GeoMonitorProxyEndpoint  $proxy  代理出口
     */
    private function isProxySchedulable(GeoMonitorProxyEndpoint $proxy): bool
    {
        if ($proxy->status !== 'active') {
            return false;
        }

        return ! $this->isCoolingDown($proxy->cooldown_until);
    }

    /**
     * 是否仍处于冷却窗口。
     *
     * @param  Carbon|null  $until  冷却截止时间
     */
    private function isCoolingDown(?Carbon $until): bool
    {
        return $until !== null && $until->isFuture();
    }

    /**
     * 小时额度是否已用尽。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    private function isHourlyQuotaExceeded(GeoMonitorAccount $account): bool
    {
        if ($account->hourly_quota === null) {
            return false;
        }

        return $this->usageCount($account->id, now()->subHour()) >= $account->hourly_quota;
    }

    /**
     * 日额度是否已用尽。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    private function isDailyQuotaExceeded(GeoMonitorAccount $account): bool
    {
        if ($account->daily_quota === null) {
            return false;
        }

        return $this->usageCount($account->id, now()->subDay()) >= $account->daily_quota;
    }

    /**
     * 统计账号自某时刻以来已完成探测次数。
     *
     * @param  int  $accountId  账号 ID
     * @param  Carbon  $since  起始时间
     */
    private function usageCount(int $accountId, Carbon $since): int
    {
        return GeoMonitorObservation::query()
            ->where('account_id', $accountId)
            ->whereNotNull('probed_at')
            ->where('probed_at', '>=', $since)
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    /**
     * 统计账号当前 running 观测数。
     *
     * @param  int  $accountId  账号 ID
     */
    private function runningObservationCount(int $accountId): int
    {
        return GeoMonitorObservation::query()
            ->where('account_id', $accountId)
            ->where('status', 'running')
            ->count();
    }

    /**
     * 账号锁缓存键。
     *
     * @param  int  $accountId  账号 ID
     */
    private function lockKey(int $accountId): string
    {
        return 'geo-monitor:account-lock:'.$accountId;
    }

    /**
     * 判断锁键是否已存在（Redis 用 EXISTS，其它驱动用短时探测）。
     *
     * @param  string  $key  锁键
     */
    private function lockKeyExists(string $key): bool
    {
        $store = $this->cache()->getStore();

        if ($store instanceof RedisStore) {
            return (bool) $store->lockConnection()->exists($store->getPrefix().$key);
        }

        $lock = $this->cache()->lock($key, $this->accountLockSeconds);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    /**
     * 强制删除锁键，不校验持有者。
     *
     * @param  string  $key  锁键
     */
    private function forceReleaseLockKey(string $key): void
    {
        $this->cache()
            ->lock($key, $this->accountLockSeconds)
            ->forceRelease();
    }

    /**
     * 获取分布式锁使用的缓存仓库。
     */
    private function cache(): CacheRepository
    {
        return Cache::store(
            (string) config('geoflow.geo_monitor.lock_cache_store', config('cache.default'))
        );
    }
}
