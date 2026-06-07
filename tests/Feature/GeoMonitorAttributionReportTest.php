<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GeoMonitorCitation;
use App\Models\GeoMonitorMention;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorRun;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorAttributionReportService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorAttributionScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoMonitorAttributionReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 报表服务应输出平台拆解与 TOP 来源。
     */
    public function test_report_service_builds_platform_and_top_sources(): void
    {
        $run = $this->seedScoredRun();
        $service = new GeoMonitorAttributionReportService(new GeoMonitorAttributionScorer);

        $report = $service->buildRunReport($run);

        $this->assertGreaterThan(0, $report['geo_score']);
        $this->assertNotEmpty($report['platform_breakdown']);
        $this->assertNotEmpty($report['top_sources']);
        $this->assertSame('zuche.com', $report['top_sources'][0]['domain']);
    }

    /**
     * 批次详情页应展示 GEO 综合分与竞品对比区块。
     */
    public function test_run_page_renders_attribution_report_sections(): void
    {
        $run = $this->seedScoredRun();

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.geo-monitoring.run', ['runId' => $run->id]))
            ->assertOk()
            ->assertSee(__('admin.geo_monitoring.report_geo_score'))
            ->assertSee(__('admin.geo_monitoring.report_competitor_comparison'))
            ->assertSee(__('admin.geo_monitoring.report_top_sources'));
    }

    private function seedScoredRun(): GeoMonitorRun
    {
        $project = GeoMonitorProject::query()->create([
            'name' => '报表测试',
            'slug' => 'report-test',
            'brand_name' => '神州租车',
            'primary_domain' => 'zuche.com',
            'competitor_brands' => ['一嗨租车'],
            'competitor_domains' => ['1hai.cn'],
            'status' => 'active',
        ]);

        $prompt = GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'brand_generic',
            'prompt_text' => '北京租车推荐',
            'intent' => 'generic',
            'is_enabled' => true,
        ]);

        $platform = GeoMonitorPlatform::query()->where('code', 'yuanbao')->firstOrFail();

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'partial',
            'observation_count' => 1,
            'success_count' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $observation = GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $platform->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'partial',
            'probed_at' => now(),
            'answer_text' => '推荐神州租车。',
        ]);

        GeoMonitorMention::query()->create([
            'observation_id' => $observation->id,
            'entity_name' => '神州租车',
            'entity_type' => 'own_brand',
            'mention_text' => '神州租车',
            'position' => 1,
        ]);

        GeoMonitorCitation::query()->create([
            'observation_id' => $observation->id,
            'url' => 'https://zuche.com/a',
            'domain' => 'zuche.com',
            'position' => 1,
            'is_own_domain' => true,
            'is_competitor_domain' => false,
        ]);

        $scorer = new GeoMonitorAttributionScorer;
        $scorer->scoreRun($run);

        return $run->fresh(['project', 'observations.platform', 'observations.prompt', 'observations.citations', 'observations.mentions']);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'geo_report_admin',
            'password' => 'secret-123',
            'email' => 'geo-report@example.com',
            'display_name' => 'GEO Report Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
