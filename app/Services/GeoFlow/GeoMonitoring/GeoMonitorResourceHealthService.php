<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorBrowserProfile;
use App\Models\GeoMonitorProxyEndpoint;

/**
 * 探测结果驱动的账号 / Profile / 代理健康与冷却更新。
 */
class GeoMonitorResourceHealthService
{
    /**
     * @param  int  $captchaCooldownMinutes  验证码冷却分钟数
     * @param  int  $failureCooldownMinutes  连续失败冷却分钟数
     * @param  int  $failuresBeforeCooldown  触发冷却的失败次数阈值
     */
    public function __construct(
        private readonly int $captchaCooldownMinutes = 120,
        private readonly int $failureCooldownMinutes = 30,
        private readonly int $failuresBeforeCooldown = 3,
    ) {}

    /**
     * 从配置构造健康服务。
     */
    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('geoflow.geo_monitor.resource_health', []);

        return new self(
            captchaCooldownMinutes: max(5, (int) ($config['captcha_cooldown_minutes'] ?? 120)),
            failureCooldownMinutes: max(5, (int) ($config['failure_cooldown_minutes'] ?? 30)),
            failuresBeforeCooldown: max(1, (int) ($config['failures_before_cooldown'] ?? 3)),
        );
    }

    /**
     * 根据观测终态回写资源池状态。
     *
     * @param  GeoMonitorAccount  $account  使用的账号
     * @param  string  $observationStatus  观测状态
     * @param  string  $errorMessage  错误信息
     */
    public function handleProbeOutcome(
        GeoMonitorAccount $account,
        string $observationStatus,
        string $errorMessage = '',
    ): void {
        $account->loadMissing(['browserProfile', 'proxyEndpoint']);

        match ($observationStatus) {
            'success', 'partial' => $this->markHealthy($account),
            'captcha_required' => $this->markCaptchaRequired($account, $errorMessage),
            'needs_login' => $this->markNeedsLogin($account, $errorMessage),
            'failed', 'selector_miss' => $this->markFailure($account, $errorMessage),
            default => null,
        };
    }

    /**
     * 探测成功：清理错误并复位连续失败计数。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    private function markHealthy(GeoMonitorAccount $account): void
    {
        $meta = is_array($account->meta) ? $account->meta : [];
        $meta['consecutive_failures'] = 0;

        $account->update([
            'last_error_message' => null,
            'meta' => $meta,
        ]);
    }

    /**
     * 验证码：账号进入维护态，Profile 降级，并冷却。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @param  string  $errorMessage  错误信息
     */
    private function markCaptchaRequired(GeoMonitorAccount $account, string $errorMessage): void
    {
        $account->update([
            'status' => 'needs_maintenance',
            'cooldown_until' => now()->addMinutes($this->captchaCooldownMinutes),
            'last_error_message' => $errorMessage !== '' ? $errorMessage : '需要人工过验证码',
        ]);

        $this->degradeProfile($account->browserProfile, 'degraded');
        $this->incrementProxyFailure($account->proxyEndpoint);
    }

    /**
     * 登录失效：标记 needs_login，暂停自动调度。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @param  string  $errorMessage  错误信息
     */
    private function markNeedsLogin(GeoMonitorAccount $account, string $errorMessage): void
    {
        $account->update([
            'status' => 'needs_login',
            'last_login_status' => 'not_logged_in',
            'last_login_check_at' => now(),
            'last_error_message' => $errorMessage !== '' ? $errorMessage : '登录态失效',
        ]);

        $this->degradeProfile($account->browserProfile, 'maintenance');
    }

    /**
     * 普通失败：累计失败次数，达阈值后冷却。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @param  string  $errorMessage  错误信息
     */
    private function markFailure(GeoMonitorAccount $account, string $errorMessage): void
    {
        $meta = is_array($account->meta) ? $account->meta : [];
        $failures = (int) ($meta['consecutive_failures'] ?? 0) + 1;
        $meta['consecutive_failures'] = $failures;

        $updates = [
            'last_error_message' => $errorMessage !== '' ? $errorMessage : '探测失败',
            'meta' => $meta,
        ];

        if ($failures >= $this->failuresBeforeCooldown) {
            $updates['status'] = 'cooldown';
            $updates['cooldown_until'] = now()->addMinutes($this->failureCooldownMinutes);
            $meta['consecutive_failures'] = 0;
            $updates['meta'] = $meta;
        }

        $account->update($updates);
        $this->incrementProxyFailure($account->proxyEndpoint);
    }

    /**
     * 降级 Profile 健康状态。
     *
     * @param  GeoMonitorBrowserProfile|null  $profile  浏览器 Profile
     * @param  string  $healthStatus  健康状态
     */
    private function degradeProfile(?GeoMonitorBrowserProfile $profile, string $healthStatus): void
    {
        if ($profile === null) {
            return;
        }

        $profile->update(['health_status' => $healthStatus]);
    }

    /**
     * 代理失败计数，达阈值进入冷却。
     *
     * @param  GeoMonitorProxyEndpoint|null  $proxy  代理出口
     */
    private function incrementProxyFailure(?GeoMonitorProxyEndpoint $proxy): void
    {
        if ($proxy === null) {
            return;
        }

        $failures = $proxy->failure_count + 1;
        $updates = [
            'failure_count' => $failures,
            'last_health_status' => 'degraded',
            'last_health_check_at' => now(),
        ];

        if ($failures >= $this->failuresBeforeCooldown) {
            $updates['status'] = 'cooldown';
            $updates['cooldown_until'] = now()->addMinutes($this->failureCooldownMinutes);
            $updates['failure_count'] = 0;
        }

        $proxy->update($updates);
    }
}
