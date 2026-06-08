<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use Illuminate\Support\Facades\Mail;

/**
 * 使用后台 site_settings 中的 SMTP 参数发送 GEO 监测告警邮件。
 */
class GeoMonitorAlertMailer
{
    private const MAILER_NAME = 'geo_monitor_alert_smtp';

    /**
     * 发送纯文本告警邮件。
     *
     * @param  GeoMonitorAlertSettings  $settings  告警与 SMTP 配置
     * @param  string  $subject  邮件主题
     * @param  string  $body  邮件正文
     * @param  bool  $forTest  测试邮件时不检查告警总开关
     *
     * @throws \Throwable  SMTP 连接或投递失败时抛出
     */
    public function send(GeoMonitorAlertSettings $settings, string $subject, string $body, bool $forTest = false): void
    {
        $ready = $forTest ? $settings->isMailDeliveryReady() : $settings->shouldSendMail();

        if (! $ready) {
            return;
        }

        config([
            'mail.mailers.'.self::MAILER_NAME => $settings->smtpMailerConfig(),
        ]);

        Mail::mailer(self::MAILER_NAME)->raw($body, function ($message) use ($settings, $subject): void {
            $message->from(
                $settings->mailFromAddress,
                $settings->mailFromName !== '' ? $settings->mailFromName : null,
            )
                ->to($settings->mailRecipients())
                ->subject($subject);
        });
    }
}
