<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorAlertMailer;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorAlertSettings;
use App\Support\AdminWeb;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GEO 监测告警、邮件与定时计划后台配置。
 */
class GeoMonitoringSettingsController extends Controller
{
    /**
     * 展示告警与通知配置页。
     */
    public function index(): View
    {
        return view('admin.geo-monitoring.settings', [
            'pageTitle' => __('admin.geo_monitoring.settings.page_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'settings' => GeoMonitorAlertSettings::fromSiteSettings()->toFormArray(),
            'defaults' => [
                'mail_from_name' => GeoMonitorAlertSettings::DEFAULT_MAIL_FROM_NAME,
                'smtp_port' => GeoMonitorAlertSettings::DEFAULT_SMTP_PORT,
                'smtp_encryption' => GeoMonitorAlertSettings::DEFAULT_SMTP_ENCRYPTION,
                'dedupe_minutes' => GeoMonitorAlertSettings::DEFAULT_DEDUPE_MINUTES,
                'citation_drop_threshold' => GeoMonitorAlertSettings::DEFAULT_CITATION_DROP_THRESHOLD,
            ],
        ]);
    }

    /**
     * 保存告警与通知配置。
     */
    public function update(Request $request): RedirectResponse
    {
        $payload = $this->validateSettingsPayload($request);
        $storedPassword = $this->storedSmtpPassword();
        $mailEnabled = ! empty($payload['mail_enabled']);

        if ($error = $this->validateMailPayload($payload, $storedPassword, $mailEnabled)) {
            return redirect()
                ->route('admin.geo-monitoring.settings.index')
                ->withErrors($error)
                ->withInput();
        }

        $this->persistSettings($payload, $storedPassword, $mailEnabled);

        return redirect()
            ->route('admin.geo-monitoring.settings.index')
            ->with('message', __('admin.geo_monitoring.settings.saved'));
    }

    /**
     * 使用当前表单内容发送测试邮件（无需先保存）。
     */
    public function sendTestMail(Request $request, GeoMonitorAlertMailer $mailer): RedirectResponse
    {
        $payload = $this->validateSettingsPayload($request);
        $storedPassword = $this->storedSmtpPassword();
        $settings = GeoMonitorAlertSettings::fromMailFormPayload(
            $this->normalizeMailPayload($payload),
            $storedPassword,
        );

        if (! $settings->isMailDeliveryReady()) {
            $missing = $settings->mailDeliveryMissingLabels();

            return redirect()
                ->route('admin.geo-monitoring.settings.index')
                ->withErrors(__('admin.geo_monitoring.settings.error_test_mail_missing', [
                    'fields' => implode('、', $missing),
                ]))
                ->withInput();
        }

        try {
            $mailer->send(
                $settings,
                '[GEO Monitor] '.__('admin.geo_monitoring.settings.test_mail_subject'),
                __('admin.geo_monitoring.settings.test_mail_body'),
                forTest: true,
            );
        } catch (\Throwable $exception) {
            return redirect()
                ->route('admin.geo-monitoring.settings.index')
                ->withErrors(__('admin.geo_monitoring.settings.error_test_mail_failed', [
                    'error' => $exception->getMessage(),
                ]))
                ->withInput();
        }

        return redirect()
            ->route('admin.geo-monitoring.settings.index')
            ->with('message', __('admin.geo_monitoring.settings.test_mail_sent'));
    }

    /**
     * 校验并返回表单 payload。
     *
     * @return array<string, mixed>
     */
    private function validateSettingsPayload(Request $request): array
    {
        return $request->validate([
            'schedule_enabled' => ['nullable'],
            'alert_enabled' => ['nullable'],
            'mail_enabled' => ['nullable'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['nullable', 'string', 'in:none,tls,ssl'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:512'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_recipients' => ['nullable', 'string', 'max:5000'],
            'webhook_url' => ['nullable', 'url', 'max:500'],
            'dedupe_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'citation_drop_threshold' => ['nullable', 'numeric', 'min:0.05', 'max:0.95'],
        ]);
    }

    /**
     * 读取已保存的 SMTP 密码。
     */
    private function storedSmtpPassword(): string
    {
        return (string) SiteSetting::query()
            ->where('setting_key', GeoMonitorAlertSettings::KEY_SMTP_PASSWORD)
            ->value('setting_value');
    }

    /**
     * 校验邮件相关字段；失败时返回错误文案。
     *
     * @param  array<string, mixed>  $payload  表单数据
     * @param  string  $storedPassword  已保存密码
     * @param  bool  $mailEnabled  是否启用邮件告警
     */
    private function validateMailPayload(array $payload, string $storedPassword, bool $mailEnabled): ?string
    {
        if (! $mailEnabled) {
            return null;
        }

        $recipientsRaw = trim((string) ($payload['mail_recipients'] ?? ''));
        $fromAddress = trim((string) ($payload['mail_from_address'] ?? ''));
        $smtpHost = trim((string) ($payload['smtp_host'] ?? ''));
        $smtpUsername = trim((string) ($payload['smtp_username'] ?? ''));
        $smtpPassword = trim((string) ($payload['smtp_password'] ?? ''));

        if ($recipientsRaw === '' || $fromAddress === '' || $smtpHost === '' || $smtpUsername === '') {
            return __('admin.geo_monitoring.settings.error_mail_incomplete');
        }

        if ($smtpPassword === '' && $storedPassword === '') {
            return __('admin.geo_monitoring.settings.error_smtp_password_required');
        }

        return null;
    }

    /**
     * 写入 site_settings。
     *
     * @param  array<string, mixed>  $payload  表单数据
     * @param  string  $storedPassword  已保存密码
     * @param  bool  $mailEnabled  是否启用邮件
     */
    private function persistSettings(array $payload, string $storedPassword, bool $mailEnabled): void
    {
        $normalized = $this->normalizeMailPayload($payload);
        $smtpPassword = trim((string) ($normalized['smtp_password'] ?? ''));

        if ($smtpPassword === '') {
            $smtpPassword = $storedPassword;
        }

        $values = [
            GeoMonitorAlertSettings::KEY_SCHEDULE_ENABLED => ! empty($payload['schedule_enabled']) ? '1' : '0',
            GeoMonitorAlertSettings::KEY_ALERT_ENABLED => ! empty($payload['alert_enabled']) ? '1' : '0',
            GeoMonitorAlertSettings::KEY_MAIL_ENABLED => $mailEnabled ? '1' : '0',
            GeoMonitorAlertSettings::KEY_SMTP_HOST => trim((string) ($normalized['smtp_host'] ?? '')),
            GeoMonitorAlertSettings::KEY_SMTP_PORT => (string) ((int) ($normalized['smtp_port'] ?? GeoMonitorAlertSettings::DEFAULT_SMTP_PORT)),
            GeoMonitorAlertSettings::KEY_SMTP_ENCRYPTION => (string) ($normalized['smtp_encryption'] ?? GeoMonitorAlertSettings::DEFAULT_SMTP_ENCRYPTION),
            GeoMonitorAlertSettings::KEY_SMTP_USERNAME => trim((string) ($normalized['smtp_username'] ?? '')),
            GeoMonitorAlertSettings::KEY_SMTP_PASSWORD => $smtpPassword,
            GeoMonitorAlertSettings::KEY_MAIL_FROM_NAME => trim((string) ($normalized['mail_from_name'] ?? GeoMonitorAlertSettings::DEFAULT_MAIL_FROM_NAME)),
            GeoMonitorAlertSettings::KEY_MAIL_FROM_ADDRESS => trim((string) ($normalized['mail_from_address'] ?? '')),
            GeoMonitorAlertSettings::KEY_MAIL_RECIPIENTS => $this->normalizeRecipients(trim((string) ($normalized['mail_recipients'] ?? ''))),
            GeoMonitorAlertSettings::KEY_WEBHOOK_URL => trim((string) ($payload['webhook_url'] ?? '')),
            GeoMonitorAlertSettings::KEY_DEDUPE_MINUTES => (string) ((int) ($payload['dedupe_minutes'] ?? GeoMonitorAlertSettings::DEFAULT_DEDUPE_MINUTES)),
            GeoMonitorAlertSettings::KEY_CITATION_DROP_THRESHOLD => (string) ($payload['citation_drop_threshold'] ?? GeoMonitorAlertSettings::DEFAULT_CITATION_DROP_THRESHOLD),
        ];

        foreach ($values as $settingKey => $settingValue) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $settingKey],
                ['setting_value' => $settingValue],
            );
        }

        SiteSettingsBag::forget();
    }

    /**
     * 规范化邮件表单字段（收件人去空行等）。
     *
     * @param  array<string, mixed>  $payload  原始表单
     * @return array<string, mixed>
     */
    private function normalizeMailPayload(array $payload): array
    {
        $payload['mail_recipients'] = $this->normalizeRecipients(trim((string) ($payload['mail_recipients'] ?? '')));

        return $payload;
    }

    /**
     * 规范化收件人列表（每行一个邮箱，去空行）。
     *
     * @param  string  $raw  原始文本
     */
    private function normalizeRecipients(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $lines = preg_split('/\R/u', $raw) ?: [];
        $cleaned = array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== '',
        ));

        return implode("\n", $cleaned);
    }
}
