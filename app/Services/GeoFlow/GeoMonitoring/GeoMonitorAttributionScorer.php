<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorRun;
use App\Models\GeoMonitorScore;
use Illuminate\Support\Collection;

/**
 * GEO 引用度评分：观测级与批次级指标计算并写入 geo_monitor_scores。
 */
class GeoMonitorAttributionScorer
{
    public const SCORE_VERSION = 'v1';

    /**
     * @param  array<string, float>  $weights  综合分权重
     */
    public function __construct(
        private readonly array $weights = [],
    ) {}

    /**
     * 从应用配置构造评分器。
     */
    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('geoflow.geo_monitor.scoring_weights', []);

        return new self([
            'brand_mention' => (float) ($config['brand_mention'] ?? 0.35),
            'own_citation' => (float) ($config['own_citation'] ?? 0.35),
            'citation_coverage' => (float) ($config['citation_coverage'] ?? 0.15),
            'platform_coverage' => (float) ($config['platform_coverage'] ?? 0.15),
        ]);
    }

    /**
     * 计算并持久化单条观测评分。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     */
    public function scoreObservation(GeoMonitorObservation $observation): GeoMonitorScore
    {
        $observation->loadMissing(['citations', 'mentions', 'project']);

        $metrics = $this->buildObservationMetrics($observation);

        return GeoMonitorScore::query()->updateOrCreate(
            [
                'project_id' => $observation->project_id,
                'observation_id' => $observation->id,
                'score_version' => self::SCORE_VERSION,
            ],
            [
                'run_id' => $observation->run_id,
                'metrics' => $metrics,
                'computed_at' => now(),
            ],
        );
    }

    /**
     * 计算并持久化批次评分。
     *
     * @param  GeoMonitorRun  $run  批次运行
     */
    public function scoreRun(GeoMonitorRun $run): GeoMonitorScore
    {
        $run->loadMissing([
            'project',
            'observations.citations',
            'observations.mentions',
            'observations.platform',
        ]);

        $metrics = $this->buildRunMetrics($run);

        return GeoMonitorScore::query()->updateOrCreate(
            [
                'project_id' => $run->project_id,
                'run_id' => $run->id,
                'observation_id' => null,
                'score_version' => self::SCORE_VERSION,
            ],
            [
                'metrics' => $metrics,
                'computed_at' => now(),
            ],
        );
    }

    /**
     * 构建单条观测指标。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     * @return array<string, mixed>
     */
    public function buildObservationMetrics(GeoMonitorObservation $observation): array
    {
        $citations = $observation->citations;
        $mentions = $observation->mentions;

        $ownCitations = $citations->where('is_own_domain', true);
        $hasBrandMention = $mentions->where('entity_type', 'own_brand')->isNotEmpty();
        $hasOwnCitation = $ownCitations->isNotEmpty();
        $ownCitationRank = $ownCitations->min('position');

        $observationScore = 0.0;

        if ($hasBrandMention) {
            $observationScore += 40;
        }

        if ($hasOwnCitation) {
            $observationScore += 35;
            $observationScore += match (true) {
                $ownCitationRank === 1 => 15,
                $ownCitationRank !== null && $ownCitationRank <= 3 => 10,
                default => 5,
            };
        }

        if ($mentions->whereIn('entity_type', ['product_keyword', 'prompt_keyword'])->isNotEmpty()) {
            $observationScore += 10;
        }

        return [
            'geo_score' => round(min(100.0, $observationScore), 1),
            'has_brand_mention' => $hasBrandMention,
            'has_own_citation' => $hasOwnCitation,
            'has_competitor_mention' => $mentions->where('entity_type', 'competitor_brand')->isNotEmpty(),
            'has_competitor_citation' => $citations->where('is_competitor_domain', true)->isNotEmpty(),
            'citation_count' => $citations->count(),
            'own_citation_count' => $ownCitations->count(),
            'competitor_citation_count' => $citations->where('is_competitor_domain', true)->count(),
            'own_citation_rank' => $ownCitationRank,
            'brand_mention_count' => $mentions->where('entity_type', 'own_brand')->count(),
            'competitor_mention_count' => $mentions->where('entity_type', 'competitor_brand')->count(),
            'keyword_hit_count' => $mentions->whereIn('entity_type', ['product_keyword', 'prompt_keyword'])->count(),
            'status' => $observation->status,
        ];
    }

    /**
     * 构建批次级指标。
     *
     * @param  GeoMonitorRun  $run  批次运行
     * @return array<string, mixed>
     */
    public function buildRunMetrics(GeoMonitorRun $run): array
    {
        $eligible = $this->eligibleObservations($run->observations);
        $total = $eligible->count();

        if ($total === 0) {
            return [
                'geo_score' => 0.0,
                'eligible_observations' => 0,
                'brand_mention_rate' => 0.0,
                'own_citation_rate' => 0.0,
                'citation_coverage_rate' => 0.0,
                'competitor_mention_rate' => 0.0,
                'competitor_citation_rate' => 0.0,
                'platform_coverage_index' => 0.0,
            ];
        }

        $brandMentionCount = $eligible->filter(
            fn (GeoMonitorObservation $item): bool => $item->mentions->where('entity_type', 'own_brand')->isNotEmpty()
        )->count();
        $ownCitationCount = $eligible->filter(
            fn (GeoMonitorObservation $item): bool => $item->citations->where('is_own_domain', true)->isNotEmpty()
        )->count();
        $citationCoverageCount = $eligible->filter(
            fn (GeoMonitorObservation $item): bool => $item->citations->isNotEmpty()
        )->count();
        $competitorMentionCount = $eligible->filter(
            fn (GeoMonitorObservation $item): bool => $item->mentions->where('entity_type', 'competitor_brand')->isNotEmpty()
        )->count();
        $competitorCitationCount = $eligible->filter(
            fn (GeoMonitorObservation $item): bool => $item->citations->where('is_competitor_domain', true)->isNotEmpty()
        )->count();

        $brandMentionRate = $this->percentage($brandMentionCount, $total);
        $ownCitationRate = $this->percentage($ownCitationCount, $total);
        $citationCoverageRate = $this->percentage($citationCoverageCount, $total);
        $competitorMentionRate = $this->percentage($competitorMentionCount, $total);
        $competitorCitationRate = $this->percentage($competitorCitationCount, $total);
        $platformCoverageIndex = $this->platformCoverageIndex($eligible);

        $geoScore = $this->compositeScore(
            $brandMentionRate,
            $ownCitationRate,
            $citationCoverageRate,
            $platformCoverageIndex,
        );

        $ownRanks = $eligible
            ->flatMap(fn (GeoMonitorObservation $item) => $item->citations
                ->where('is_own_domain', true)
                ->pluck('position'))
            ->filter(fn ($position): bool => $position !== null);

        return [
            'geo_score' => round($geoScore, 1),
            'eligible_observations' => $total,
            'brand_mention_count' => $brandMentionCount,
            'brand_mention_rate' => $brandMentionRate,
            'own_citation_observation_count' => $ownCitationCount,
            'own_citation_rate' => $ownCitationRate,
            'citation_coverage_rate' => $citationCoverageRate,
            'competitor_mention_count' => $competitorMentionCount,
            'competitor_mention_rate' => $competitorMentionRate,
            'competitor_citation_count' => $competitorCitationCount,
            'competitor_citation_rate' => $competitorCitationRate,
            'platform_coverage_index' => $platformCoverageIndex,
            'own_citation_avg_rank' => $ownRanks->isEmpty() ? null : round((float) $ownRanks->avg(), 1),
            'total_citations' => $eligible->sum(fn (GeoMonitorObservation $item): int => $item->citations->count()),
            'own_citations' => $eligible->sum(
                fn (GeoMonitorObservation $item): int => $item->citations->where('is_own_domain', true)->count()
            ),
            'competitor_citations' => $eligible->sum(
                fn (GeoMonitorObservation $item): int => $item->citations->where('is_competitor_domain', true)->count()
            ),
        ];
    }

    /**
     * 筛选可用于评分的观测（已探测且未取消）。
     *
     * @param  Collection<int, GeoMonitorObservation>  $observations  观测集合
     * @return Collection<int, GeoMonitorObservation>
     */
    public function eligibleObservations(Collection $observations): Collection
    {
        return $observations
            ->filter(fn (GeoMonitorObservation $item): bool => $item->probed_at !== null)
            ->reject(fn (GeoMonitorObservation $item): bool => $item->status === 'cancelled');
    }

    /**
     * 计算平台覆盖指数：有品牌提及或我方引用的平台占比。
     *
     * @param  Collection<int, GeoMonitorObservation>  $eligible  合格观测
     */
    private function platformCoverageIndex(Collection $eligible): float
    {
        $platforms = $eligible->groupBy('platform_id');

        if ($platforms->isEmpty()) {
            return 0.0;
        }

        $positivePlatforms = $platforms->filter(function (Collection $group): bool {
            return $group->contains(function (GeoMonitorObservation $item): bool {
                return $item->mentions->where('entity_type', 'own_brand')->isNotEmpty()
                    || $item->citations->where('is_own_domain', true)->isNotEmpty();
            });
        });

        return $this->percentage($positivePlatforms->count(), $platforms->count());
    }

    /**
     * 按权重合成 GEO 综合分（0–100）。
     *
     * @param  float  $brandMentionRate  品牌提及率
     * @param  float  $ownCitationRate  我方引用率
     * @param  float  $citationCoverageRate  引用覆盖率
     * @param  float  $platformCoverageIndex  平台覆盖指数
     */
    private function compositeScore(
        float $brandMentionRate,
        float $ownCitationRate,
        float $citationCoverageRate,
        float $platformCoverageIndex,
    ): float {
        $weights = $this->normalizedWeights();

        return ($brandMentionRate * $weights['brand_mention'])
            + ($ownCitationRate * $weights['own_citation'])
            + ($citationCoverageRate * $weights['citation_coverage'])
            + ($platformCoverageIndex * $weights['platform_coverage']);
    }

    /**
     * @return array{brand_mention: float, own_citation: float, citation_coverage: float, platform_coverage: float}
     */
    private function normalizedWeights(): array
    {
        $weights = [
            'brand_mention' => max(0.0, $this->weights['brand_mention'] ?? 0.35),
            'own_citation' => max(0.0, $this->weights['own_citation'] ?? 0.35),
            'citation_coverage' => max(0.0, $this->weights['citation_coverage'] ?? 0.15),
            'platform_coverage' => max(0.0, $this->weights['platform_coverage'] ?? 0.15),
        ];

        $sum = array_sum($weights);

        if ($sum <= 0) {
            return [
                'brand_mention' => 0.35,
                'own_citation' => 0.35,
                'citation_coverage' => 0.15,
                'platform_coverage' => 0.15,
            ];
        }

        foreach ($weights as $key => $value) {
            $weights[$key] = $value / $sum;
        }

        return $weights;
    }

    /**
     * 计算百分比（0–100，保留一位小数）。
     *
     * @param  int  $part  分子
     * @param  int  $total  分母
     */
    private function percentage(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 1);
    }
}
