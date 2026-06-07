<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessGeoMonitorProbeJob;
use App\Models\Admin;
use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorCitation;
use App\Models\GeoMonitorMention;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorResourceAssignment;
use App\Models\GeoMonitorRun;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorProbePersister;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceHealthService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceScheduler;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRunOpsService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRunService;
use App\Services\GeoFlow\GeoMonitoring\ScraplingBridgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoMonitorProbeJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_persists_sidecar_probe_result(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
            'geoflow.geo_monitor.lock_cache_store' => 'array',
        ]);

        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'deepseek_account_01',
            'label' => 'DeepSeek 测试账号',
            'status' => 'active',
            'profile_storage_path' => 'profiles/deepseek_account_01',
        ]);

        $project = GeoMonitorProject::query()->create([
            'name' => '测试项目',
            'slug' => 'test-project',
            'brand_name' => 'GEOFlow',
            'primary_domain' => 'geoflow.example',
            'product_keywords' => ['企业知识库'],
            'status' => 'active',
        ]);

        $prompt = GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'brand_generic',
            'prompt_text' => 'GEOFlow 是什么？',
            'intent' => 'brand',
        ]);

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'running',
            'platform_scope' => ['deepseek'],
            'prompt_count' => 1,
            'observation_count' => 1,
            'started_at' => now(),
        ]);

        $observation = GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $platform->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'pending',
        ]);

        Http::fake([
            'http://sidecar.test/v1/probe' => Http::response([
                'ok' => true,
                'data' => [
                    'platform' => 'deepseek',
                    'account_id' => 'deepseek_account_01',
                    'status' => 'success',
                    'login_status' => 'logged_in',
                    'answer_text' => 'GEOFlow 是企业知识库方案。',
                    'citations' => [
                        [
                            'url' => 'https://geoflow.example/docs',
                            'title' => '官方文档',
                            'position' => 1,
                        ],
                    ],
                    'evidence' => [
                        'html_path' => 'evidence/run-1/how_to.html',
                        'screenshot_path' => 'evidence/run-1/how_to.png',
                    ],
                    'duration_ms' => 1500,
                    'meta' => [
                        'resource' => ['account_id' => 'deepseek_account_01'],
                    ],
                ],
            ]),
        ]);

        $job = new ProcessGeoMonitorProbeJob($observation->id);
        $job->handle(
            app(ScraplingBridgeClient::class),
            app(GeoMonitorProbePersister::class),
            app(GeoMonitorRunService::class),
            app(GeoMonitorRunOpsService::class),
            app(GeoMonitorResourceScheduler::class),
            app(GeoMonitorResourceHealthService::class),
        );

        $observation->refresh();
        $run->refresh();

        $this->assertSame('success', $observation->status);

        $assignment = GeoMonitorResourceAssignment::query()
            ->where('observation_id', $observation->id)
            ->first();
        $this->assertNotNull($assignment);
        $this->assertSame('deepseek_account_01', $assignment->account?->external_id);
        $this->assertContains($assignment->scheduler_strategy, ['pool_least_busy', 'pinned_account']);
        $this->assertSame('GEOFlow 是企业知识库方案。', $observation->answer_text);
        $this->assertSame(1, GeoMonitorCitation::query()->where('observation_id', $observation->id)->count());
        $this->assertSame(2, GeoMonitorMention::query()->where('observation_id', $observation->id)->count());
        $this->assertTrue(GeoMonitorMention::query()
            ->where('observation_id', $observation->id)
            ->where('entity_name', 'GEOFlow')
            ->where('entity_type', 'own_brand')
            ->exists());
        $this->assertTrue(GeoMonitorMention::query()
            ->where('observation_id', $observation->id)
            ->where('entity_name', '企业知识库')
            ->where('entity_type', 'product_keyword')
            ->exists());
        $this->assertTrue(
            GeoMonitorCitation::query()->where('observation_id', $observation->id)->value('is_own_domain')
        );
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(1, $run->success_count);

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.geo-monitoring.run', ['runId' => $run->id]))
            ->assertOk()
            ->assertSee(__('admin.geo_monitoring.report_title'))
            ->assertSee('GEOFlow')
            ->assertSee('企业知识库');
    }

    /**
     * 创建后台管理员用于访问运行报表页。
     */
    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'geo_monitor_report_admin',
            'password' => 'secret-123',
            'email' => 'geo-monitor-report@example.com',
            'display_name' => 'GEO Monitor Report Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
