<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Exceptions\GeoFlow\GeoMonitorSidecarException;
use App\Models\Admin;
use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorProfileMaintenanceEvent;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * GEO 监测账号 profile 维护：noVNC 指引、维护事件与健康检查。
 */
class GeoMonitorMaintenanceService
{
    /**
     * @param  ScraplingBridgeClient  $bridgeClient  sidecar 客户端
     * @param  GeoMonitorRuntimeConfig  $runtime  运行环境配置
     */
    public function __construct(
        private readonly ScraplingBridgeClient $bridgeClient,
        private readonly GeoMonitorRuntimeConfig $runtime,
    ) {}

    /**
     * 从配置构造维护服务。
     */
    public static function fromConfig(): self
    {
        return new self(
            bridgeClient: app(ScraplingBridgeClient::class),
            runtime: GeoMonitorRuntimeConfig::fromConfig(),
        );
    }

    /**
     * 构建账号维护指引上下文，供后台页面展示。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @return array<string, mixed>
     */
    public function buildGuideContext(GeoMonitorAccount $account): array
    {
        $account->loadMissing(['platform', 'browserProfile', 'proxyEndpoint']);

        $pocRoot = $this->runtime->pocRoot;
        $platformCode = $account->platform->code;
        $externalId = $account->external_id;
        $triggerReason = $this->inferTriggerReason($account);
        $maintainMode = $account->status === 'needs_login' ? 'login' : 'captcha';

        $healthScript = $this->runtime->isHeadlessLinux()
            ? 'scripts/novnc/health-check.sh'
            : 'scripts/headed/health-check.sh';

        $maintainScript = $this->runtime->isHeadlessLinux()
            ? 'scripts/novnc/maintain-profile.sh'
            : 'scripts/headed/maintain-profile.sh';

        $context = [
            'runtime_mode' => $this->runtime->mode,
            'runtime_label' => $this->runtime->label(),
            'runtime_description' => $this->runtime->description(),
            'poc_root' => $pocRoot,
            'maintain_command' => sprintf(
                'cd %s && ./%s --platform %s --account-id %s --mode %s',
                $pocRoot,
                $maintainScript,
                $platformCode,
                $externalId,
                $maintainMode,
            ),
            'health_check_command' => sprintf(
                'cd %s && ./%s --platform %s --account-id %s',
                $pocRoot,
                $healthScript,
                $platformCode,
                $externalId,
            ),
            'sync_accounts_hint' => __('admin.geo_monitoring.maintenance_sync_accounts_hint'),
            'sidecar_session_hint' => sprintf(
                'curl -s "%s/v1/platforms/%s/session?account_id=%s"',
                rtrim((string) config('geoflow.geo_monitor.sidecar_url', ''), '/'),
                $platformCode,
                $externalId,
            ),
            'trigger_reason' => $triggerReason,
            'maintain_mode' => $maintainMode,
            'profile_path' => $account->profile_storage_path,
            'proxy_label' => $account->proxyEndpoint?->label,
            'steps' => $this->resolveMaintenanceSteps(),
            'supports_interactive' => $this->supportsInteractiveBrowser(),
            'command_blocks' => [],
        ];

        if ($this->supportsInteractiveBrowser()) {
            if ($this->runtime->isHeadlessLinux()) {
                /** @var array<string, mixed> $novnc */
                $novnc = config('geoflow.geo_monitor.novnc', []);
                $port = max(1024, (int) ($novnc['port'] ?? 6080));
                $sshHost = trim((string) ($novnc['ssh_tunnel_hint_host'] ?? ''));

                $context['novnc_local_url'] = sprintf('http://127.0.0.1:%d/vnc.html', $port);
                $context['ssh_tunnel_command'] = $sshHost !== ''
                    ? sprintf('ssh -N -L %d:127.0.0.1:%d %s', $port, $port, $sshHost)
                    : sprintf('ssh -N -L %d:127.0.0.1:%d user@your-server', $port, $port);
            }
        } elseif ($this->runtime->isHeadlessLinux()) {
            /** @var array<string, mixed> $novnc */
            $novnc = config('geoflow.geo_monitor.novnc', []);
            $port = max(1024, (int) ($novnc['port'] ?? 6080));
            $bindHost = (string) ($novnc['bind_host'] ?? '127.0.0.1');
            $display = (string) ($novnc['display'] ?? ':99');
            $sshHost = trim((string) ($novnc['ssh_tunnel_hint_host'] ?? ''));

            $sshTunnel = $sshHost !== ''
                ? sprintf('ssh -L %d:127.0.0.1:%d %s', $port, $port, $sshHost)
                : sprintf('ssh -L %d:127.0.0.1:%d user@your-server', $port, $port);

            $context['display'] = $display;
            $context['novnc_local_url'] = sprintf('http://127.0.0.1:%d/vnc.html', $port);
            $context['novnc_bind'] = sprintf('%s:%d', $bindHost, $port);
            $context['ssh_tunnel_command'] = $sshTunnel;
            $context['start_novnc_command'] = sprintf('cd %s && ./scripts/novnc/start-novnc.sh', $pocRoot);
            $context['command_blocks'] = [
                __('admin.geo_monitoring.maintenance_cmd_start_novnc') => $context['start_novnc_command'],
                __('admin.geo_monitoring.maintenance_cmd_ssh_tunnel') => $sshTunnel,
                __('admin.geo_monitoring.maintenance_cmd_maintain') => $context['maintain_command'],
                __('admin.geo_monitoring.maintenance_cmd_health') => $context['health_check_command'],
            ];
        } else {
            $context['command_blocks'] = [
                __('admin.geo_monitoring.maintenance_cmd_headed_maintain') => $context['maintain_command'],
                __('admin.geo_monitoring.maintenance_cmd_health') => $context['health_check_command'],
            ];
        }

        return $context;
    }

    /**
     * 开始维护：记录事件并锁定账号调度。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @param  Admin  $operator  操作管理员
     * @param  string  $triggerReason  触发原因
     */
    public function beginMaintenance(
        GeoMonitorAccount $account,
        Admin $operator,
        string $triggerReason,
    ): GeoMonitorProfileMaintenanceEvent {
        $account->loadMissing(['browserProfile', 'proxyEndpoint']);

        $existing = $this->resolveOpenEvent($account);

        if ($existing !== null) {
            return $existing;
        }

        if ($triggerReason === '') {
            $triggerReason = $this->inferTriggerReason($account);
        }

        $lockStatus = in_array($account->status, ['needs_login', 'needs_maintenance'], true)
            ? $account->status
            : 'needs_maintenance';

        $account->update([
            'status' => $lockStatus,
            'cooldown_until' => null,
        ]);

        return GeoMonitorProfileMaintenanceEvent::query()->create([
            'account_id' => $account->id,
            'browser_profile_id' => $account->browserProfile?->id,
            'proxy_endpoint_id' => $account->proxyEndpoint?->id,
            'trigger_reason' => $triggerReason,
            'maintenance_via' => $this->runtime->maintenanceVia(),
            'status' => 'in_progress',
            'operator_admin_id' => $operator->id,
            'egress_ip_summary' => $account->proxyEndpoint?->egress_ip_summary,
            'started_at' => now(),
        ]);
    }

    /**
     * 是否支持后台一键拉起浏览器（sidecar 可用即可；无头 Linux 通过 noVNC 远程桌面展示）。
     */
    public function supportsInteractiveBrowser(): bool
    {
        return $this->bridgeClient->isOperational();
    }

    /**
     * 维护页步骤文案（交互式优先，否则回退到手动命令流程）。
     *
     * @return list<string>
     */
    private function resolveMaintenanceSteps(): array
    {
        if ($this->supportsInteractiveBrowser()) {
            if ($this->runtime->isHeadlessLinux()) {
                return [
                    __('admin.geo_monitoring.maintenance_step_interactive_novnc_tunnel'),
                    __('admin.geo_monitoring.maintenance_step_interactive_launch'),
                    __('admin.geo_monitoring.maintenance_step_interactive_novnc_login'),
                    __('admin.geo_monitoring.maintenance_step_interactive_save'),
                    __('admin.geo_monitoring.maintenance_step_health'),
                ];
            }

            return [
                __('admin.geo_monitoring.maintenance_step_interactive_launch'),
                __('admin.geo_monitoring.maintenance_step_interactive_login'),
                __('admin.geo_monitoring.maintenance_step_interactive_save'),
                __('admin.geo_monitoring.maintenance_step_health'),
            ];
        }

        if ($this->runtime->isHeadlessLinux()) {
            return [
                __('admin.geo_monitoring.maintenance_step_sync'),
                __('admin.geo_monitoring.maintenance_step_start'),
                __('admin.geo_monitoring.maintenance_step_tunnel'),
                __('admin.geo_monitoring.maintenance_step_novnc'),
                __('admin.geo_monitoring.maintenance_step_maintain'),
                __('admin.geo_monitoring.maintenance_step_health'),
            ];
        }

        return [
            __('admin.geo_monitoring.maintenance_step_sync'),
            __('admin.geo_monitoring.maintenance_step_headed_maintain'),
            __('admin.geo_monitoring.maintenance_step_headed_browser'),
            __('admin.geo_monitoring.maintenance_step_health'),
        ];
    }

    /**
     * 通过 sidecar 拉起可见浏览器供人工登录/过验证码。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @param  string  $mode  login 或 captcha
     * @return array{session_id: string, status: string, profile_path: string, chat_url: string}
     */
    public function launchInteractiveBrowser(GeoMonitorAccount $account, string $mode = 'login'): array
    {
        if (! $this->supportsInteractiveBrowser()) {
            throw new InvalidArgumentException(__('admin.geo_monitoring.maintenance_interactive_unavailable'));
        }

        $account->loadMissing('platform');

        if (! in_array($mode, ['login', 'captcha'], true)) {
            throw new InvalidArgumentException(__('admin.geo_monitoring.maintenance_invalid_mode'));
        }

        try {
            $data = $this->bridgeClient->startMaintenanceSession(
                $account->platform->code,
                $account->external_id,
                $mode,
            );
        } catch (GeoMonitorSidecarException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        return [
            'session_id' => (string) ($data['session_id'] ?? ''),
            'status' => (string) ($data['status'] ?? 'opening'),
            'profile_path' => (string) ($data['profile_path'] ?? $account->profile_storage_path),
            'chat_url' => (string) ($data['chat_url'] ?? ''),
        ];
    }

    /**
     * 通知 sidecar 关闭维护浏览器并保存 profile。
     *
     * @param  string  $sessionId  sidecar 会话 ID
     * @return array{session_id: string, status: string}
     */
    public function saveInteractiveBrowser(string $sessionId): array
    {
        if ($sessionId === '') {
            throw new InvalidArgumentException(__('admin.geo_monitoring.maintenance_session_missing'));
        }

        try {
            $data = $this->bridgeClient->completeMaintenanceSession($sessionId);
        } catch (GeoMonitorSidecarException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        return [
            'session_id' => (string) ($data['session_id'] ?? $sessionId),
            'status' => (string) ($data['status'] ?? 'closed'),
        ];
    }

    /**
     * 调用 sidecar 检查登录态。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @return array{ok: bool, login_status: string, message: string, raw: array<string, mixed>|null}
     */
    public function runHealthCheck(GeoMonitorAccount $account): array
    {
        $account->loadMissing('platform');

        if (! $this->bridgeClient->isOperational()) {
            return [
                'ok' => false,
                'login_status' => 'unknown',
                'message' => 'GEO 监测 sidecar 未启用或未配置 URL',
                'raw' => null,
            ];
        }

        try {
            $response = $this->bridgeClient->checkSession(
                $account->platform->code,
                $account->external_id,
            );
        } catch (GeoMonitorSidecarException $exception) {
            return [
                'ok' => false,
                'login_status' => 'unknown',
                'message' => $exception->getMessage(),
                'raw' => null,
            ];
        }

        /** @var array<string, mixed> $data */
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $loginStatus = (string) ($data['login_status'] ?? 'unknown');
        $ok = in_array($loginStatus, ['logged_in', 'guest_ok'], true);

        return [
            'ok' => $ok,
            'login_status' => $loginStatus,
            'message' => $ok
                ? __('admin.geo_monitoring.maintenance_health_ok')
                : __('admin.geo_monitoring.maintenance_health_failed', ['status' => $loginStatus]),
            'raw' => $data,
        ];
    }

    /**
     * 完成维护：先执行 sidecar 健康检查，通过才恢复 active。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @param  Admin  $operator  操作管理员
     * @param  string|null  $notes  运维备注
     * @return array{event: GeoMonitorProfileMaintenanceEvent, health: array{ok: bool, login_status: string, message: string, raw: array<string, mixed>|null}}
     */
    public function completeMaintenance(
        GeoMonitorAccount $account,
        Admin $operator,
        ?string $notes = null,
    ): array {
        $account->loadMissing(['browserProfile']);

        $event = $this->resolveOpenEvent($account);

        if ($event === null) {
            throw new InvalidArgumentException(__('admin.geo_monitoring.maintenance_no_open_event'));
        }

        $health = $this->runHealthCheck($account);
        $healthPassed = $health['ok'];

        $eventNotes = $notes;

        if ($eventNotes !== null && $eventNotes !== '') {
            $eventNotes .= "\n";
        }

        $eventNotes = ($eventNotes ?? '').$health['message'];

        $status = $healthPassed ? 'succeeded' : 'failed';
        $event->update([
            'status' => $status,
            'operator_admin_id' => $operator->id,
            'notes' => $eventNotes !== '' ? $eventNotes : null,
            'finished_at' => now(),
        ]);

        if ($healthPassed) {
            $account->update([
                'status' => 'active',
                'last_error_message' => null,
                'last_login_status' => 'logged_in',
                'last_login_check_at' => now(),
                'cooldown_until' => null,
            ]);

            if ($account->browserProfile !== null) {
                $account->browserProfile->update([
                    'health_status' => 'healthy',
                    'last_maintained_at' => now(),
                    'last_maintenance_via' => $this->runtime->maintenanceVia(),
                ]);
            }
        }

        return [
            'event' => $event->fresh() ?? $event,
            'health' => $health,
        ];
    }

    /**
     * 账号最近维护事件（含进行中）。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     * @return Collection<int, GeoMonitorProfileMaintenanceEvent>
     */
    public function recentEvents(GeoMonitorAccount $account, int $limit = 10): Collection
    {
        return GeoMonitorProfileMaintenanceEvent::query()
            ->where('account_id', $account->id)
            ->with('operatorAdmin')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 根据账号状态推断维护触发原因。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    private function inferTriggerReason(GeoMonitorAccount $account): string
    {
        return match ($account->status) {
            'needs_login' => 'needs_login',
            'needs_maintenance' => 'captcha_required',
            'cooldown' => 'consecutive_failures',
            default => 'manual_inspection',
        };
    }

    /**
     * 获取账号当前进行中的维护事件。
     *
     * @param  GeoMonitorAccount  $account  采集账号
     */
    private function resolveOpenEvent(GeoMonitorAccount $account): ?GeoMonitorProfileMaintenanceEvent
    {
        return GeoMonitorProfileMaintenanceEvent::query()
            ->where('account_id', $account->id)
            ->where('status', 'in_progress')
            ->orderByDesc('started_at')
            ->first();
    }
}
