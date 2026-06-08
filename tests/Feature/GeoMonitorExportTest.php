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
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorReportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoMonitorExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 导出服务应包含观测明细与引用统计列。
     */
    public function test_export_service_builds_observation_rows(): void
    {
        $run = $this->seedRunWithObservation();
        $service = app(GeoMonitorReportExportService::class);

        $rows = $service->buildRowsForRun($run);

        $this->assertCount(2, $rows);
        $this->assertSame('project_name', $rows[0][0]);
        $this->assertSame('计划测试', $rows[1][0]);
        $this->assertSame('1', $rows[1][13]);
        $this->assertSame('1', $rows[1][14]);
    }

    /**
     * 管理员可下载批次 CSV。
     */
    public function test_admin_can_download_run_csv(): void
    {
        $run = $this->seedRunWithObservation();

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.geo-monitoring.runs.export', ['runId' => $run->id]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('计划测试', $response->streamedContent());
    }

    private function seedRunWithObservation(): GeoMonitorRun
    {
        $project = GeoMonitorProject::query()->create([
            'name' => '计划测试',
            'slug' => 'export-test',
            'brand_name' => '品牌',
            'primary_domain' => 'example.com',
            'status' => 'active',
        ]);

        $prompt = GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'brand_generic',
            'prompt_text' => '测试问题',
            'is_enabled' => true,
        ]);

        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'succeeded',
            'observation_count' => 1,
            'success_count' => 1,
            'started_at' => now()->subHour(),
            'finished_at' => now(),
        ]);

        $observation = GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $platform->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'success',
            'login_status' => 'logged_in',
            'screenshot_path' => 'laravel-run-1/brand_generic.png',
        ]);

        GeoMonitorCitation::query()->create([
            'observation_id' => $observation->id,
            'url' => 'https://example.com/a',
            'domain' => 'example.com',
            'is_own_domain' => true,
            'is_competitor_domain' => false,
            'position' => 1,
        ]);

        GeoMonitorMention::query()->create([
            'observation_id' => $observation->id,
            'entity_name' => '品牌',
            'mention_text' => '品牌',
            'entity_type' => 'brand',
            'position' => 1,
        ]);

        return $run->fresh(['project', 'observations.platform', 'observations.prompt', 'observations.citations', 'observations.mentions']);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'geo_export_admin',
            'password' => bcrypt('secret'),
            'role' => 'super_admin',
        ]);
    }
}
