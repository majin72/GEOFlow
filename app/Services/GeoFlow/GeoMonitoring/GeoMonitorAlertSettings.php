<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Support\Site\SiteSettingsBag;

/**
 * GEO 监测告警与定时计划后台配置（存储在 site_settings）。
 */
final class GeoMonitorAlertSettings
{
    public const KEY_SCHEDULE_ENABLED = 'geo_monitor_schedule_enabled';

    public const KEY_ALERT_ENABLED = 'geo_monitor_alert_enabled';

    public const KEY_MAIL_ENABLED = 'geo_monitor_alert_mail_enabled';

    public const KEY_SMTP_HOST = 'geo_monitor_alert_smtp_host';

    public const KEY_SMTP_PORT = 'geo_monitor_alert_smtp_port';

    public const KEY_SMTP_ENCRYPTION = 'geo_monitor_alert_smtp_encryption';

    public const KEY_SMTP_USERNAME = 'geo_monitor_alert_smtp_username';

    public const KEY_SMTP_PASSWORD = 'geo_monitor_alert_smtp_password';

    public const KEY_MAIL_FROM_NAME = 'geo_monitor_alert_mail_from_name';

    public const KEY_MAIL_FROM_ADDRESS = 'geo_monitor_alert_mail_from_address';

    public const KEY_MAIL_RECIPIENTS = 'geo_monitor_alert_mail_recipients';

    public const KEY_WEBHOOK_URL = 'geo_monitor_alert_webhook_url';

    public const KEY_DEDUPE_MINUTES = 'geo_monitor_alert_dedupe_minutes';

    public const KEY_CITATION_DROP_THRESHOLD = 'geo_monitor_alert_citation_drop_threshold';

    public const DEFAULT_MAIL_FROM_NAME = 'GEOFlow 监测';

    public const DEFAULT_SMTP_PORT = 465;

    public const DEFAULT_SMTP_ENCRYPTION = 'ssl';

    public const DEFAULT_DEDUPE_MINUTES = 60;

    public const DEFAULT_CITATION_DROP_THRESHOLD = 0.3;

    /**
     * @param  bool  $scheduleEnabled  全局定时计划开关
     * @param  bool  $alertEnabled  异常告警总开关
     * @param  bool  $mailEnabled  是否发送邮件
     * @param  string  $smtpHost  SMTP 服务器地址
     * @param  int  $smtpPort  SMTP 端口
     * @param  string  $smtpEncryption  加密方式 none|tls|ssl
     * @param  string  $smtpUsername  SMTP 登录账号
     * @param  string  $smtpPassword  SMTP 密码或授权码
     * @param  string  $mailFromName  发件人显示名称
     * @param  string  $mailFromAddress  发件人邮箱
     * @param  string  $mailRecipientsRaw  收件人列表（每行一个邮箱）
     * @param  string  $webhookUrl  Webhook 地址
     * @param  int  $dedupeMinutes  告警去重分钟数
     * @param  float  $citationDropThreshold  引用率下降告警阈值（0-1）
     */
    public function __construct(
        public readonly bool $scheduleEnabled,
        public readonly bool $alertEnabled,
        public readonly bool $mailEnabled,
        public readonly string $smtpHost,
        public readonly int $smtpPort,
        public readonly string $smtpEncryption,
        public readonly string $smtpUsername,
        public readonly string $smtpPassword,
        public readonly string $mailFromName,
        public readonly string $mailFromAddress,
        public readonly string $mailRecipientsRaw,
        public readonly string $webhookUrl,
        public readonly int $dedupeMinutes,
        public readonly float $citationDropThreshold,
    ) {}

    /**
     * 从 site_settings 读取当前配置。
     */
    public static function fromSiteSettings(): self
    {
        return new self(
            scheduleEnabled: self::parseBool(SiteSettingsBag::get(self::KEY_SCHEDULE_ENABLED, '1')),
            alertEnabled: self::parseBool(SiteSettingsBag::get(self::KEY_ALERT_ENABLED, '1')),
            mailEnabled: self::parseBool(SiteSettingsBag::get(self::KEY_MAIL_ENABLED, '0')),
            smtpHost: trim(SiteSettingsBag::get(self::KEY_SMTP_HOST, '')),
            smtpPort: max(1, min(65535, (int) SiteSettingsBag::get(self::KEY_SMTP_PORT, (string) self::DEFAULT_SMTP_PORT))),
            smtpEncryption: self::normalizeEncryption(SiteSettingsBag::get(self::KEY_SMTP_ENCRYPTION, self::DEFAULT_SMTP_ENCRYPTION)),
            smtpUsername: trim(SiteSettingsBag::get(self::KEY_SMTP_USERNAME, '')),
            smtpPassword: (string) SiteSettingsBag::get(self::KEY_SMTP_PASSWORD, ''),
            mailFromName: trim(SiteSettingsBag::get(self::KEY_MAIL_FROM_NAME, self::DEFAULT_MAIL_FROM_NAME)),
            mailFromAddress: trim(SiteSettingsBag::get(self::KEY_MAIL_FROM_ADDRESS, '')),
            mailRecipientsRaw: (string) SiteSettingsBag::get(self::KEY_MAIL_RECIPIENTS, ''),
            webhookUrl: trim(SiteSettingsBag::get(self::KEY_WEBHOOK_URL, '')),
            dedupeMinutes: max(5, (int) SiteSettingsBag::get(self::KEY_DEDUPE_MINUTES, (string) self::DEFAULT_DEDUPE_MINUTES)),
            citationDropThreshold: self::clampThreshold(
                (float) SiteSettingsBag::get(self::KEY_CITATION_DROP_THRESHOLD, (string) self::DEFAULT_CITATION_DROP_THRESHOLD),
            ),
        );
    }

    /**
     * @return list<string> 所有 site_settings 键名
     */
    public static function keys(): array
    {
        return [
            self::KEY_SCHEDULE_ENABLED,
            self::KEY_ALERT_ENABLED,
            self::KEY_MAIL_ENABLED,
            self::KEY_SMTP_HOST,
            self::KEY_SMTP_PORT,
            self::KEY_SMTP_ENCRYPTION,
            self::KEY_SMTP_USERNAME,
            self::KEY_SMTP_PASSWORD,
            self::KEY_MAIL_FROM_NAME,
            self::KEY_MAIL_FROM_ADDRESS,
            self::KEY_MAIL_RECIPIENTS,
            self::KEY_WEBHOOK_URL,
            self::KEY_DEDUPE_MINUTES,
            self::KEY_CITATION_DROP_THRESHOLD,
        ];
    }

    /**
     * 解析收件人邮箱列表（每行一个）。
     *
     * @return list<string>
     */
    public function mailRecipients(): array
    {
        $lines = preg_split('/\R/u', $this->mailRecipientsRaw) ?: [];

        return array_values(array_unique(array_filter(
            array_map(static fn (string $line): string => strtolower(trim($line)), $lines),
            static fn (string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    /**
     * SMTP 参数是否已填写完整。
     */
    public function isSmtpConfigured(): bool
    {
        return $this->smtpHost !== ''
            && $this->smtpPort > 0
            && $this->smtpUsername !== ''
            && $this->smtpPassword !== '';
    }

    /**
     * 邮件投递所需参数是否齐全（不检查告警总开关）。
     */
    public function isMailDeliveryReady(): bool
    {
        return $this->isSmtpConfigured()
            && $this->mailRecipients() !== []
            && $this->mailFromAddress !== ''
            && filter_var($this->mailFromAddress, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * 是否应发送邮件告警。
     */
    public function shouldSendMail(): bool
    {
        return $this->alertEnabled
            && $this->mailEnabled
            && $this->isMailDeliveryReady();
    }

    /**
     * 根据后台表单 POST 数据构建配置（测试邮件用；密码留空时回退到已保存值）。
     *
     * @param  array<string, mixed>  $payload  表单字段
     * @param  string  $storedSmtpPassword  已保存的 SMTP 密码
     * @param  self|null  $base  非邮件字段的回退来源
     */
    public static function fromMailFormPayload(array $payload, string $storedSmtpPassword = '', ?self $base = null): self
    {
        $base ??= self::fromSiteSettings();
        $smtpPassword = trim((string) ($payload['smtp_password'] ?? ''));

        if ($smtpPassword === '') {
            $smtpPassword = $storedSmtpPassword !== '' ? $storedSmtpPassword : $base->smtpPassword;
        }

        $recipientsRaw = (string) ($payload['mail_recipients'] ?? '');

        return new self(
            scheduleEnabled: $base->scheduleEnabled,
            alertEnabled: $base->alertEnabled,
            mailEnabled: $base->mailEnabled,
            smtpHost: trim((string) ($payload['smtp_host'] ?? '')),
            smtpPort: max(1, min(65535, (int) ($payload['smtp_port'] ?? self::DEFAULT_SMTP_PORT))),
            smtpEncryption: self::normalizeEncryption((string) ($payload['smtp_encryption'] ?? self::DEFAULT_SMTP_ENCRYPTION)),
            smtpUsername: trim((string) ($payload['smtp_username'] ?? '')),
            smtpPassword: $smtpPassword,
            mailFromName: trim((string) ($payload['mail_from_name'] ?? self::DEFAULT_MAIL_FROM_NAME)),
            mailFromAddress: trim((string) ($payload['mail_from_address'] ?? '')),
            mailRecipientsRaw: $recipientsRaw,
            webhookUrl: $base->webhookUrl,
            dedupeMinutes: $base->dedupeMinutes,
            citationDropThreshold: $base->citationDropThreshold,
        );
    }

    /**
     * 返回邮件投递尚未满足的字段标签（用于友好错误提示）。
     *
     * @return list<string>
     */
    public function mailDeliveryMissingLabels(): array
    {
        $missing = [];

        if ($this->smtpHost === '') {
            $missing[] = __('admin.geo_monitoring.settings.field_smtp_host');
        }

        if ($this->smtpUsername === '') {
            $missing[] = __('admin.geo_monitoring.settings.field_smtp_username');
        }

        if ($this->smtpPassword === '') {
            $missing[] = __('admin.geo_monitoring.settings.field_smtp_password');
        }

        if ($this->mailFromAddress === '' || filter_var($this->mailFromAddress, FILTER_VALIDATE_EMAIL) === false) {
            $missing[] = __('admin.geo_monitoring.settings.field_mail_from_address');
        }

        if ($this->mailRecipients() === []) {
            $missing[] = __('admin.geo_monitoring.settings.field_mail_recipients');
        }

        return $missing;
    }

    /**
     * 实际启用的告警分发通道。
     *
     * @return list<string>
     */
    public function channels(): array
    {
        if (! $this->alertEnabled) {
            return [];
        }

        $channels = ['log'];

        if ($this->shouldSendMail()) {
            $channels[] = 'mail';
        }

        if ($this->webhookUrl !== '') {
            $channels[] = 'webhook';
        }

        return $channels;
    }

    /**
     * 构建 Laravel 动态 SMTP mailer 配置。
     *
     * @return array<string, mixed>
     */
    public function smtpMailerConfig(): array
    {
        $encryption = $this->smtpEncryption;

        return [
            'transport' => 'smtp',
            'host' => $this->smtpHost,
            'port' => $this->smtpPort,
            'encryption' => ($encryption === '' || $encryption === 'none') ? null : $encryption,
            'username' => $this->smtpUsername,
            'password' => $this->smtpPassword,
            'timeout' => 15,
        ];
    }

    /**
     * @return array<string, bool|string> 供后台表单展示的键值
     */
    public function toFormArray(): array
    {
        return [
            'schedule_enabled' => $this->scheduleEnabled,
            'alert_enabled' => $this->alertEnabled,
            'mail_enabled' => $this->mailEnabled,
            'smtp_host' => $this->smtpHost,
            'smtp_port' => (string) $this->smtpPort,
            'smtp_encryption' => $this->smtpEncryption,
            'smtp_username' => $this->smtpUsername,
            'smtp_password_configured' => $this->smtpPassword !== '',
            'mail_from_name' => $this->mailFromName,
            'mail_from_address' => $this->mailFromAddress,
            'mail_recipients' => $this->mailRecipientsRaw,
            'webhook_url' => $this->webhookUrl,
            'dedupe_minutes' => (string) $this->dedupeMinutes,
            'citation_drop_threshold' => (string) $this->citationDropThreshold,
        ];
    }

    /**
     * @param  string  $raw  原始布尔字符串
     */
    private static function parseBool(string $raw): bool
    {
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param  string  $raw  原始加密方式
     */
    private static function normalizeEncryption(string $raw): string
    {
        $value = strtolower(trim($raw));

        return in_array($value, ['none', 'tls', 'ssl'], true) ? $value : self::DEFAULT_SMTP_ENCRYPTION;
    }

    /**
     * @param  float  $value  原始阈值
     */
    private static function clampThreshold(float $value): float
    {
        return max(0.05, min(0.95, $value));
    }
}
