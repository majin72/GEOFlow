<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GeoMonitorAlert;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorRun;
use App\Models\GeoMonitorScore;
use App\Models\SiteSetting;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorAlertMailer;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorAlertService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorAlertSettings;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorAttributionScorer;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorDashboardService;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GeoMonitorAlertTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 连续三次失败应触发告警。
     */
    public function test_consecutive_failed_runs_fire_alert(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        $this->seedAlertSettings();
        Cache::flush();

        $project = GeoMonitorProject::query()->create([
            'name' => '告警测试',
            'slug' => 'alert-test',
            'brand_name' => '品牌',
            'primary_domain' => 'example.com',
            'status' => 'active',
        ]);

        $runs = collect(range(1, 3))->map(fn (int $index): GeoMonitorRun => GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'failed',
            'observation_count' => 1,
            'success_count' => 0,
            'started_at' => now()->subHours(4 - $index),
            'finished_at' => now()->subHours(3 - $index),
        ]));

        app(GeoMonitorAlertService::class)->evaluateCompletedRun($runs->last());

        $this->assertSame(1, GeoMonitorAlert::query()->where('alert_type', 'consecutive_failures')->count());
    }

    /**
     * 引用率大幅下降应触发告警。
     */
    public function test_citation_drop_fires_alert(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        $this->seedAlertSettings([
            GeoMonitorAlertSettings::KEY_CITATION_DROP_THRESHOLD => '0.3',
        ]);
        Cache::flush();

        $project = GeoMonitorProject::query()->create([
            'name' => '引用告警',
            'slug' => 'citation-alert',
            'brand_name' => '品牌',
            'primary_domain' => 'example.com',
            'status' => 'active',
        ]);

        $previous = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'succeeded',
            'observation_count' => 1,
            'success_count' => 1,
            'finished_at' => now()->subDay(),
        ]);

        $current = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'partial',
            'observation_count' => 1,
            'success_count' => 1,
            'finished_at' => now(),
        ]);

        $this->storeRunMetrics($previous, ['own_citation_rate' => 80.0]);
        $this->storeRunMetrics($current, ['own_citation_rate' => 20.0]);

        app(GeoMonitorAlertService::class)->evaluateCompletedRun($current);

        $this->assertSame(1, GeoMonitorAlert::query()->where('alert_type', 'citation_drop')->count());
    }

    /**
     * 验证码与探测失败应分别触发告警。
     */
    public function test_captcha_and_probe_failure_fire_alerts(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        $this->seedAlertSettings();
        Cache::flush();

        $project = GeoMonitorProject::query()->create([
            'name' => '探测告警',
            'slug' => 'probe-alert',
            'brand_name' => '品牌',
            'primary_domain' => 'example.com',
            'status' => 'active',
        ]);

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'partial',
            'observation_count' => 2,
            'success_count' => 0,
            'finished_at' => now(),
        ]);

        $prompt = GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'q1',
            'prompt_text' => '测试问题',
            'is_enabled' => true,
        ]);

        $deepseek = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();
        $doubao = GeoMonitorPlatform::query()->where('code', 'doubao')->firstOrFail();

        GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $deepseek->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'captcha_required',
            'login_status' => 'captcha_required',
        ]);

        GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $doubao->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'failed',
            'login_status' => 'logged_in',
        ]);

        app(GeoMonitorAlertService::class)->evaluateCompletedRun($run);

        $this->assertSame(1, GeoMonitorAlert::query()->where('alert_type', 'captcha_required')->count());
        $this->assertSame(1, GeoMonitorAlert::query()->where('alert_type', 'probe_data_unavailable')->count());
    }

    /**
     * 启用邮件配置时应向多位收件人发送告警。
     */
    public function test_mail_alert_is_sent_to_multiple_recipients(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        $this->seedAlertSettings([
            GeoMonitorAlertSettings::KEY_MAIL_ENABLED => '1',
            GeoMonitorAlertSettings::KEY_MAIL_FROM_NAME => 'GEO 监测',
            GeoMonitorAlertSettings::KEY_MAIL_FROM_ADDRESS => 'monitor@example.com',
            GeoMonitorAlertSettings::KEY_MAIL_RECIPIENTS => "ops@example.com\ndev@example.com",
        ]);
        Cache::flush();

        $mailer = $this->mock(GeoMonitorAlertMailer::class);
        $mailer->shouldReceive('send')
            ->once()
            ->withArgs(fn (GeoMonitorAlertSettings $settings, string $subject, string $body): bool => str_contains($body, '测试告警'));

        app(GeoMonitorAlertService::class)->fire(
            alertType: 'sidecar_unreachable',
            severity: 'critical',
            fingerprint: 'sidecar_unreachable_test',
            title: '测试告警',
            message: 'Sidecar 不可达',
        );
    }

    /**
     * 后台可打开并保存告警配置页。
     */
    public function test_admin_can_save_alert_settings(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo-monitoring.settings.index'))
            ->assertOk()
            ->assertSee(__('admin.geo_monitoring.settings.page_title'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.settings.update'), [
                'schedule_enabled' => '1',
                'alert_enabled' => '1',
                'mail_enabled' => '1',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => '465',
                'smtp_encryption' => 'ssl',
                'smtp_username' => 'monitor@example.com',
                'smtp_password' => 'app-password',
                'mail_from_name' => 'GEO 监测',
                'mail_from_address' => 'monitor@example.com',
                'mail_recipients' => "ops@example.com\ndev@example.com",
                'dedupe_minutes' => '30',
                'citation_drop_threshold' => '0.25',
            ])
            ->assertRedirect(route('admin.geo-monitoring.settings.index'))
            ->assertSessionHas('message');

        SiteSettingsBag::forget();

        $settings = GeoMonitorAlertSettings::fromSiteSettings();
        $this->assertTrue($settings->mailEnabled);
        $this->assertTrue($settings->isSmtpConfigured());
        $this->assertSame(['ops@example.com', 'dev@example.com'], $settings->mailRecipients());
        $this->assertSame(30, $settings->dedupeMinutes);
    }

    /**
     * 保存后可触发测试邮件接口。
     */
    public function test_admin_can_send_test_mail_from_form(): void
    {
        $admin = $this->createAdmin();

        $this->mock(GeoMonitorAlertMailer::class)
            ->shouldReceive('send')
            ->once()
            ->withArgs(fn (GeoMonitorAlertSettings $settings, string $subject, string $body, bool $forTest): bool => $forTest === true);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.settings.test-mail'), [
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => '465',
                'smtp_encryption' => 'ssl',
                'smtp_username' => 'monitor@example.com',
                'smtp_password' => 'secret',
                'mail_from_name' => 'GEO 监测',
                'mail_from_address' => 'monitor@example.com',
                'mail_recipients' => 'ops@example.com',
            ])
            ->assertRedirect(route('admin.geo-monitoring.settings.index'))
            ->assertSessionHas('message');
    }

    /**
     * 测试邮件缺少字段时应提示具体项。
     */
    public function test_test_mail_shows_missing_fields(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo-monitoring.settings.test-mail'), [
                'smtp_host' => 'smtp.example.com',
                'mail_from_address' => 'monitor@example.com',
            ])
            ->assertRedirect(route('admin.geo-monitoring.settings.index'))
            ->assertSessionHasErrors();
    }

    /**
     * 运行面板页面可访问。
     */
    public function test_admin_can_open_dashboard(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
        ]);

        $payload = app(GeoMonitorDashboardService::class)->build();
        $this->assertArrayHasKey('runs_24h', $payload);

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.geo-monitoring.dashboard'))
            ->assertOk()
            ->assertSee(__('admin.geo_monitoring.dashboard_title'));
    }

    /**
     * @param  array<string, string>  $overrides  覆盖默认 site_settings
     */
    private function seedAlertSettings(array $overrides = []): void
    {
        $defaults = [
            GeoMonitorAlertSettings::KEY_SCHEDULE_ENABLED => '1',
            GeoMonitorAlertSettings::KEY_ALERT_ENABLED => '1',
            GeoMonitorAlertSettings::KEY_MAIL_ENABLED => '0',
            GeoMonitorAlertSettings::KEY_SMTP_HOST => 'smtp.example.com',
            GeoMonitorAlertSettings::KEY_SMTP_PORT => '465',
            GeoMonitorAlertSettings::KEY_SMTP_ENCRYPTION => 'ssl',
            GeoMonitorAlertSettings::KEY_SMTP_USERNAME => 'monitor@example.com',
            GeoMonitorAlertSettings::KEY_SMTP_PASSWORD => 'secret',
            GeoMonitorAlertSettings::KEY_DEDUPE_MINUTES => '60',
            GeoMonitorAlertSettings::KEY_CITATION_DROP_THRESHOLD => '0.3',
        ];

        foreach (array_merge($defaults, $overrides) as $key => $value) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value],
            );
        }

        SiteSettingsBag::forget();
    }

    /**
     * @param  array<string, mixed>  $metrics  指标
     */
    private function storeRunMetrics(GeoMonitorRun $run, array $metrics): void
    {
        GeoMonitorScore::query()->create([
            'project_id' => $run->project_id,
            'run_id' => $run->id,
            'score_version' => GeoMonitorAttributionScorer::SCORE_VERSION,
            'metrics' => $metrics,
            'computed_at' => now(),
        ]);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'geo_alert_admin',
            'password' => bcrypt('secret'),
            'role' => 'super_admin',
        ]);
    }
}
