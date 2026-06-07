<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GeoMonitorCitation;
use App\Models\GeoMonitorMention;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorRun;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorAttributionScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoMonitorAttributionScorerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 批次评分应反映品牌提及与官网引用率。
     */
    public function test_run_score_reflects_brand_and_own_citation_rates(): void
    {
        $fixture = $this->seedRunWithObservations();
        $scorer = new GeoMonitorAttributionScorer;

        $metrics = $scorer->buildRunMetrics($fixture['run']);

        $this->assertSame(2, $metrics['eligible_observations']);
        $this->assertSame(50.0, $metrics['brand_mention_rate']);
        $this->assertSame(50.0, $metrics['own_citation_rate']);
        $this->assertGreaterThan(0, $metrics['geo_score']);
    }

    /**
     * 观测评分应在有品牌提及和首位官网引用时接近满分区间。
     */
    public function test_observation_score_rewards_brand_and_first_own_citation(): void
    {
        $fixture = $this->seedRunWithObservations();
        $scorer = new GeoMonitorAttributionScorer;
        $positive = $fixture['positive'];

        $metrics = $scorer->buildObservationMetrics($positive);

        $this->assertTrue($metrics['has_brand_mention']);
        $this->assertTrue($metrics['has_own_citation']);
        $this->assertSame(1, $metrics['own_citation_rank']);
        $this->assertGreaterThanOrEqual(85.0, $metrics['geo_score']);
    }

    /**
     * 评分结果应持久化到 geo_monitor_scores。
     */
    public function test_score_run_persists_snapshot(): void
    {
        $fixture = $this->seedRunWithObservations();
        $scorer = new GeoMonitorAttributionScorer;

        $score = $scorer->scoreRun($fixture['run']);

        $this->assertSame(GeoMonitorAttributionScorer::SCORE_VERSION, $score->score_version);
        $this->assertIsArray($score->metrics);
        $this->assertArrayHasKey('geo_score', $score->metrics);
    }

    /**
     * @return array{run: GeoMonitorRun, positive: GeoMonitorObservation}
     */
    private function seedRunWithObservations(): array
    {
        $project = GeoMonitorProject::query()->create([
            'name' => '评分测试',
            'slug' => 'score-test',
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

        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'partial',
            'observation_count' => 2,
            'success_count' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $positive = GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $platform->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'partial',
            'probed_at' => now(),
            'answer_text' => '推荐神州租车，官网 zuche.com 可预订。',
        ]);

        GeoMonitorMention::query()->create([
            'observation_id' => $positive->id,
            'entity_name' => '神州租车',
            'entity_type' => 'own_brand',
            'mention_text' => '神州租车',
            'position' => 1,
        ]);

        GeoMonitorCitation::query()->create([
            'observation_id' => $positive->id,
            'url' => 'https://www.zuche.com/help',
            'domain' => 'zuche.com',
            'position' => 1,
            'is_own_domain' => true,
            'is_competitor_domain' => false,
        ]);

        $negative = GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $platform->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'failed',
            'probed_at' => now(),
            'error_message' => 'captcha',
        ]);

        $run->load(['observations.citations', 'observations.mentions', 'observations.platform']);
        $positive->load(['citations', 'mentions']);

        return ['run' => $run, 'positive' => $positive, 'negative' => $negative];
    }
}
