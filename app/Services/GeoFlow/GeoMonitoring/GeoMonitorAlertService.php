<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorAlert;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorRun;
use App\Models\GeoMonitorScore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GEO 监测异常告警：写入告警表并按后台配置分发到日志/邮件/Webhook。
 */
class GeoMonitorAlertService
{
    /**
     * @param  GeoMonitorConfig  $config  sidecar 配置
     * @param  GeoMonitorAttributionScorer  $scorer  评分器
     * @param  GeoMonitorAlertMailer  $mailer  告警邮件发送器
     * @param  GeoMonitorAlertSettings|null  $alertSettings  告警配置（测试可注入）
     */
    public function __construct(
        private readonly GeoMonitorConfig $config,
        private readonly GeoMonitorAttributionScorer $scorer,
        private readonly GeoMonitorAlertMailer $mailer,
        private readonly ?GeoMonitorAlertSettings $alertSettings = null,
    ) {}

    /**
     * 批次完成后评估告警条件。
     *
     * @param  GeoMonitorRun  $run  已结束的批次
     */
    public function evaluateCompletedRun(GeoMonitorRun $run): void
    {
        if (! $this->settings()->alertEnabled) {
            return;
        }

        $run->loadMissing(['project', 'observations']);

        if (in_array($run->status, ['failed'], true)) {
            $this->evaluateConsecutiveFailures($run);
        }

        $this->evaluateAccountIssues($run);
        $this->evaluateProbeDataFailures($run);
        $this->evaluateCitationDrop($run);
    }

    /**
     * 检查 sidecar 可达性并必要时告警。
     *
     * @param  array<string, mixed>|null  $health  sidecar /health 响应
     */
    public function evaluateSidecarHealth(?array $health): void
    {
        if (! $this->settings()->alertEnabled || ! $this->config->isOperational()) {
            return;
        }

        $reachable = is_array($health) && (($health['reachable'] ?? true) !== false);

        if ($reachable) {
            return;
        }

        $this->fire(
            alertType: 'sidecar_unreachable',
            severity: 'critical',
            fingerprint: 'sidecar_unreachable',
            title: __('admin.geo_monitoring.alert.sidecar_unreachable_title'),
            message: __('admin.geo_monitoring.alert.sidecar_unreachable_message'),
        );
    }

    /**
     * 写入告警记录并分发到配置通道。
     *
     * @param  string  $alertType  告警类型
     * @param  string  $severity  严重级别 info|warning|critical
     * @param  string  $fingerprint  去重指纹
     * @param  string  $title  标题
     * @param  string  $message  正文
     * @param  array<string, mixed>  $context  附加上下文
     * @param  int|null  $projectId  项目 ID
     * @param  int|null  $runId  批次 ID
     */
    public function fire(
        string $alertType,
        string $severity,
        string $fingerprint,
        string $title,
        string $message,
        array $context = [],
        ?int $projectId = null,
        ?int $runId = null,
    ): void {
        if (! $this->settings()->alertEnabled) {
            return;
        }

        $dedupeMinutes = $this->settings()->dedupeMinutes;
        $cacheKey = 'geo_monitor:alert:'.$fingerprint;

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->addMinutes($dedupeMinutes));

        $alert = GeoMonitorAlert::query()->create([
            'alert_type' => $alertType,
            'severity' => $severity,
            'fingerprint' => $fingerprint,
            'title' => $title,
            'message' => $message,
            'context' => $context !== [] ? $context : null,
            'project_id' => $projectId,
            'run_id' => $runId,
        ]);

        $this->dispatch($alert);
    }

    /**
     * 连续失败批次检测。
     *
     * @param  GeoMonitorRun  $run  当前批次
     */
    private function evaluateConsecutiveFailures(GeoMonitorRun $run): void
    {
        $recentRuns = GeoMonitorRun::query()
            ->where('project_id', $run->project_id)
            ->whereIn('status', ['succeeded', 'partial', 'failed'])
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        if ($recentRuns->count() < 3 || ! $recentRuns->every(fn (GeoMonitorRun $item): bool => $item->status === 'failed')) {
            return;
        }

        $this->fire(
            alertType: 'consecutive_failures',
            severity: 'critical',
            fingerprint: 'consecutive_failures:project:'.$run->project_id,
            title: __('admin.geo_monitoring.alert.consecutive_failures_title', ['project' => $run->project->name]),
            message: __('admin.geo_monitoring.alert.consecutive_failures_message'),
            context: ['run_ids' => $recentRuns->pluck('id')->all()],
            projectId: $run->project_id,
            runId: $run->id,
        );
    }

    /**
     * 账号登录/验证码异常检测。
     *
     * @param  GeoMonitorRun  $run  当前批次
     */
    private function evaluateAccountIssues(GeoMonitorRun $run): void
    {
        $issueObservations = $run->observations->filter(function (GeoMonitorObservation $observation): bool {
            return in_array($observation->login_status, ['needs_login', 'captcha_required'], true)
                || in_array($observation->status, ['needs_login', 'captcha_required', 'blocked'], true);
        });

        if ($issueObservations->isEmpty()) {
            return;
        }

        $needsLogin = $issueObservations->contains(
            fn (GeoMonitorObservation $observation): bool => in_array($observation->login_status, ['needs_login'], true)
                || $observation->status === 'needs_login',
        );

        $alertType = $needsLogin ? 'account_needs_login' : 'captcha_required';
        $fingerprint = $alertType.':run:'.$run->id;

        $this->fire(
            alertType: $alertType,
            severity: 'warning',
            fingerprint: $fingerprint,
            title: __('admin.geo_monitoring.alert.'.$alertType.'_title'),
            message: __('admin.geo_monitoring.alert.'.$alertType.'_message', [
                'count' => $issueObservations->count(),
            ]),
            context: [
                'observation_ids' => $issueObservations->pluck('id')->all(),
            ],
            projectId: $run->project_id,
            runId: $run->id,
        );
    }

    /**
     * 探测失败或无法获取回答数据时告警。
     *
     * @param  GeoMonitorRun  $run  当前批次
     */
    private function evaluateProbeDataFailures(GeoMonitorRun $run): void
    {
        $failedObservations = $run->observations->filter(function (GeoMonitorObservation $observation): bool {
            if (in_array($observation->status, ['needs_login', 'captcha_required', 'cancelled'], true)) {
                return false;
            }

            if (in_array($observation->login_status, ['needs_login', 'captcha_required'], true)) {
                return false;
            }

            return in_array($observation->status, ['failed', 'blocked'], true);
        });

        if ($failedObservations->isEmpty()) {
            return;
        }

        $this->fire(
            alertType: 'probe_data_unavailable',
            severity: 'warning',
            fingerprint: 'probe_data_unavailable:run:'.$run->id,
            title: __('admin.geo_monitoring.alert.probe_data_unavailable_title'),
            message: __('admin.geo_monitoring.alert.probe_data_unavailable_message', [
                'count' => $failedObservations->count(),
            ]),
            context: [
                'observation_ids' => $failedObservations->pluck('id')->all(),
                'statuses' => $failedObservations->pluck('status', 'id')->all(),
            ],
            projectId: $run->project_id,
            runId: $run->id,
        );
    }

    /**
     * 引用率异常下降检测。
     *
     * @param  GeoMonitorRun  $run  当前批次
     */
    private function evaluateCitationDrop(GeoMonitorRun $run): void
    {
        $previousRun = GeoMonitorRun::query()
            ->where('project_id', $run->project_id)
            ->where('id', '<', $run->id)
            ->whereIn('status', ['succeeded', 'partial'])
            ->orderByDesc('id')
            ->first();

        if ($previousRun === null) {
            return;
        }

        $currentRate = $this->ownCitationRate($run);
        $previousRate = $this->ownCitationRate($previousRun);

        if ($previousRate <= 0) {
            return;
        }

        $threshold = $this->settings()->citationDropThreshold;
        $dropRatio = ($previousRate - $currentRate) / $previousRate;

        if ($dropRatio < $threshold) {
            return;
        }

        $this->fire(
            alertType: 'citation_drop',
            severity: 'warning',
            fingerprint: 'citation_drop:project:'.$run->project_id.':run:'.$run->id,
            title: __('admin.geo_monitoring.alert.citation_drop_title', ['project' => $run->project->name]),
            message: __('admin.geo_monitoring.alert.citation_drop_message', [
                'previous' => number_format($previousRate, 1),
                'current' => number_format($currentRate, 1),
            ]),
            context: [
                'previous_run_id' => $previousRun->id,
                'previous_rate' => $previousRate,
                'current_rate' => $currentRate,
                'drop_ratio' => round($dropRatio * 100, 1),
            ],
            projectId: $run->project_id,
            runId: $run->id,
        );
    }

    /**
     * 读取批次官网引用率（优先使用已存评分）。
     *
     * @param  GeoMonitorRun  $run  批次运行
     */
    private function ownCitationRate(GeoMonitorRun $run): float
    {
        $stored = GeoMonitorScore::query()
            ->where('run_id', $run->id)
            ->whereNull('observation_id')
            ->where('score_version', GeoMonitorAttributionScorer::SCORE_VERSION)
            ->first();

        if ($stored !== null && is_array($stored->metrics)) {
            return (float) ($stored->metrics['own_citation_rate'] ?? 0);
        }

        $metrics = $this->scorer->buildRunMetrics($run);

        return (float) ($metrics['own_citation_rate'] ?? 0);
    }

    /**
     * 读取告警配置。
     */
    private function settings(): GeoMonitorAlertSettings
    {
        return $this->alertSettings ?? GeoMonitorAlertSettings::fromSiteSettings();
    }

    /**
     * 按配置通道分发告警。
     *
     * @param  GeoMonitorAlert  $alert  告警记录
     */
    private function dispatch(GeoMonitorAlert $alert): void
    {
        $settings = $this->settings();
        $channels = $settings->channels();

        if (in_array('log', $channels, true)) {
            Log::warning('[GEO Monitor Alert] '.$alert->title, [
                'alert_id' => $alert->id,
                'type' => $alert->alert_type,
                'severity' => $alert->severity,
                'message' => $alert->message,
                'context' => $alert->context,
            ]);
        }

        if (in_array('mail', $channels, true)) {
            $this->dispatchMail($alert, $settings);
        }

        if (in_array('webhook', $channels, true)) {
            $this->dispatchWebhook($alert, $settings->webhookUrl);
        }
    }

    /**
     * 发送邮件告警（支持多收件人）。
     *
     * @param  GeoMonitorAlert  $alert  告警记录
     * @param  GeoMonitorAlertSettings  $settings  告警配置
     */
    private function dispatchMail(GeoMonitorAlert $alert, GeoMonitorAlertSettings $settings): void
    {
        try {
            $this->mailer->send(
                $settings,
                '[GEO Monitor] '.$alert->title,
                $alert->title."\n\n".$alert->message,
            );
        } catch (\Throwable $exception) {
            Log::error('GEO monitor alert mail failed', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * 发送 Webhook 告警。
     *
     * @param  GeoMonitorAlert  $alert  告警记录
     * @param  string  $webhookUrl  Webhook 地址
     */
    private function dispatchWebhook(GeoMonitorAlert $alert, string $webhookUrl): void
    {
        if ($webhookUrl === '') {
            return;
        }

        try {
            Http::timeout(10)->post($webhookUrl, [
                'alert_id' => $alert->id,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'title' => $alert->title,
                'message' => $alert->message,
                'context' => $alert->context,
                'project_id' => $alert->project_id,
                'run_id' => $alert->run_id,
                'created_at' => $alert->created_at?->toIso8601String(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('GEO monitor alert webhook failed', ['error' => $exception->getMessage()]);
        }
    }
}
