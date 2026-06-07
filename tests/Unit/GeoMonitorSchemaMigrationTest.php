<?php

namespace Tests\Unit;

use App\Models\GeoMonitorCitation;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GeoMonitorSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_geo_monitor_tables_exist_after_migration(): void
    {
        $tables = [
            'geo_monitor_projects',
            'geo_monitor_platforms',
            'geo_monitor_prompts',
            'geo_monitor_runs',
            'geo_monitor_observations',
            'geo_monitor_citations',
            'geo_monitor_mentions',
            'geo_monitor_scores',
            'geo_monitor_accounts',
            'geo_monitor_browser_profiles',
            'geo_monitor_proxy_endpoints',
            'geo_monitor_resource_assignments',
            'geo_monitor_profile_maintenance_events',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_default_platforms_are_seeded(): void
    {
        $this->assertSame(3, GeoMonitorPlatform::query()->count());
        $this->assertNotNull(GeoMonitorPlatform::query()->where('code', 'doubao')->first());
        $this->assertNotNull(GeoMonitorPlatform::query()->where('code', 'deepseek')->first());
        $this->assertNotNull(GeoMonitorPlatform::query()->where('code', 'yuanbao')->first());
    }

    public function test_can_persist_observation_with_citation(): void
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $project = GeoMonitorProject::query()->create([
            'name' => 'GEOFlow 监测',
            'slug' => 'geoflow-monitor',
            'brand_name' => 'GEOFlow',
            'primary_domain' => 'geoflow.example',
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
            'status' => 'succeeded',
            'platform_scope' => ['deepseek'],
            'prompt_count' => 1,
            'started_at' => now(),
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
            'answer_text' => '示例回答',
            'answer_hash' => hash('sha256', '示例回答'),
            'duration_ms' => 1200,
            'probed_at' => now(),
        ]);

        GeoMonitorCitation::query()->create([
            'observation_id' => $observation->id,
            'url' => 'https://github.com/yaojingang/geoflow',
            'domain' => 'github.com',
            'title' => 'GEOFlow',
            'source_type' => 'dom_link',
            'position' => 1,
            'is_own_domain' => true,
        ]);

        $observation->load('citations', 'platform', 'prompt');

        $this->assertSame('deepseek', $observation->platform->code);
        $this->assertCount(1, $observation->citations);
        $this->assertTrue($observation->citations->first()->is_own_domain);
    }
}
