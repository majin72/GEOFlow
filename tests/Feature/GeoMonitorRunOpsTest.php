<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessGeoMonitorProbeJob;
use App\Models\Admin;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorRun;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorEvidenceService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorProbePersister;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceHealthService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceScheduler;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRunOpsService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRunService;
use App\Services\GeoFlow\GeoMonitoring\ScraplingBridgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeoMonitorRunOpsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 管理员可查看受控证据文件。
     */
    public function test_admin_can_view_evidence_file_under_root(): void
    {
        $fixture = $this->seedRunWithEvidence();

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.geo-monitoring.observations.evidence', [
                'runId' => $fixture['run']->id,
                'observationId' => $fixture['observation']->id,
                'type' => 'txt',
            ]))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    /**
     * 单条失败观测可排队重跑并保留来源关联。
     */
    public function test_admin_can_retry_single_failed_observation(): void
    {
        Queue::fake();

        $fixture = $this->seedFailedObservation();

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.geo-monitoring.observations.retry', [
                'runId' => $fixture['run']->id,
                'observationId' => $fixture['observation']->id,
            ]))
            ->assertRedirect(route('admin.geo-monitoring.run', ['runId' => $fixture['run']->id]));

        $retry = GeoMonitorObservation::query()
            ->where('retried_from_observation_id', $fixture['observation']->id)
            ->first();

        $this->assertNotNull($retry);
        $this->assertSame('pending', $retry->status);
        Queue::assertPushed(ProcessGeoMonitorProbeJob::class);
    }

    /**
     * 批次取消应将 pending 观测标为 cancelled 并写入日志。
     */
    public function test_admin_can_cancel_run_with_pending_observations(): void
    {
        $fixture = $this->seedRunWithPendingObservation();

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.geo-monitoring.runs.cancel', ['runId' => $fixture['run']->id]))
            ->assertRedirect(route('admin.geo-monitoring.run', ['runId' => $fixture['run']->id]));

        $fixture['run']->refresh();
        $fixture['pending']->refresh();

        $this->assertSame('cancelled', $fixture['pending']->status);
        $this->assertSame('cancelled', $fixture['run']->status);

        $meta = is_array($fixture['run']->meta) ? $fixture['run']->meta : [];
        $this->assertNotEmpty($meta['logs'] ?? []);
    }

    /**
     * Job 在取消请求后应跳过 pending 观测。
     */
    public function test_probe_job_skips_pending_observation_when_run_cancelled(): void
    {
        $fixture = $this->seedRunWithPendingObservation();

        app(GeoMonitorRunOpsService::class)->cancelRun($fixture['run']);

        $job = new ProcessGeoMonitorProbeJob($fixture['pending']->id);
        $job->handle(
            app(ScraplingBridgeClient::class),
            app(GeoMonitorProbePersister::class),
            app(GeoMonitorRunService::class),
            app(GeoMonitorRunOpsService::class),
            app(GeoMonitorResourceScheduler::class),
            app(GeoMonitorResourceHealthService::class),
        );

        $fixture['pending']->refresh();
        $this->assertSame('cancelled', $fixture['pending']->status);
    }

    /**
     * 证据服务仅暴露根目录下存在的类型。
     */
    public function test_evidence_service_lists_available_types(): void
    {
        $fixture = $this->seedRunWithEvidence();
        $service = app(GeoMonitorEvidenceService::class);

        $types = $service->availableTypes($fixture['observation']);

        $this->assertContains('txt', $types);
        $this->assertNotContains('png', $types);
    }

    /**
     * @return array{run: GeoMonitorRun, observation: GeoMonitorObservation}
     */
    private function seedRunWithEvidence(): array
    {
        $root = sys_get_temp_dir().'/geo-evidence-feature-'.uniqid();
        $relativeDir = 'deepseek/deepseek_account_01/20260604T000000Z/laravel-run-1';
        $fullDir = $root.'/'.$relativeDir;
        mkdir($fullDir, 0777, true);
        file_put_contents($fullDir.'/brand_generic.txt', '神州租车推荐');

        config(['geoflow.geo_monitor.evidence_root' => $root]);

        $project = GeoMonitorProject::query()->create([
            'name' => 'Evidence Test',
            'slug' => 'evidence-test',
            'brand_name' => '神州租车',
            'primary_domain' => 'zuche.com',
            'status' => 'active',
        ]);

        $prompt = GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'brand_generic',
            'prompt_text' => '北京租车推荐',
            'intent' => 'generic',
            'is_enabled' => true,
        ]);

        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->first();
        $this->assertNotNull($platform);

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'partial',
            'observation_count' => 1,
            'success_count' => 0,
            'started_at' => now(),
        ]);

        $observation = GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $platform->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'partial',
            'login_status' => 'logged_in',
            'raw_text_path' => $relativeDir.'/brand_generic.txt',
        ]);

        return ['run' => $run, 'observation' => $observation];
    }

    /**
     * @return array{run: GeoMonitorRun, observation: GeoMonitorObservation}
     */
    private function seedFailedObservation(): array
    {
        $project = GeoMonitorProject::query()->create([
            'name' => 'Retry Test',
            'slug' => 'retry-test',
            'brand_name' => 'GEOFlow',
            'primary_domain' => 'example.com',
            'status' => 'active',
        ]);

        $prompt = GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'brand_generic',
            'prompt_text' => 'test prompt',
            'intent' => 'generic',
            'is_enabled' => true,
        ]);

        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->first();
        $this->assertNotNull($platform);

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'failed',
            'observation_count' => 1,
            'success_count' => 0,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $observation = GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $platform->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'failed',
            'login_status' => 'unknown',
            'error_message' => 'captcha',
        ]);

        return ['run' => $run, 'observation' => $observation];
    }

    /**
     * @return array{run: GeoMonitorRun, pending: GeoMonitorObservation}
     */
    private function seedRunWithPendingObservation(): array
    {
        $project = GeoMonitorProject::query()->create([
            'name' => 'Cancel Test',
            'slug' => 'cancel-test',
            'brand_name' => 'GEOFlow',
            'primary_domain' => 'example.com',
            'status' => 'active',
        ]);

        $prompt = GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'brand_generic',
            'prompt_text' => 'test prompt',
            'intent' => 'generic',
            'is_enabled' => true,
        ]);

        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->first();
        $this->assertNotNull($platform);

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'running',
            'observation_count' => 1,
            'success_count' => 0,
            'started_at' => now(),
        ]);

        $pending = GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $platform->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'pending',
            'login_status' => 'unknown',
        ]);

        return ['run' => $run, 'pending' => $pending];
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'geo_ops_admin',
            'password' => 'secret-123',
            'email' => 'geo-ops@example.com',
            'display_name' => 'GEO Ops Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
