<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorRun;
use App\Models\GeoMonitorScore;
use Illuminate\Support\Collection;

/**
 * 组装 GEO 引用度后台报表：批次、平台、问题、竞品对比与 TOP 来源。
 */
class GeoMonitorAttributionReportService
{
    /**
     * @param  GeoMonitorAttributionScorer  $scorer  评分器
     */
    public function __construct(
        private readonly GeoMonitorAttributionScorer $scorer,
    ) {}

    /**
     * 构建批次完整报表（供 run 详情页使用）。
     *
     * @param  GeoMonitorRun  $run  批次运行
     * @return array<string, mixed>
     */
    public function buildRunReport(GeoMonitorRun $run): array
    {
        $run->loadMissing([
            'project',
            'observations.platform',
            'observations.prompt',
            'observations.citations',
            'observations.mentions',
        ]);

        $eligible = $this->scorer->eligibleObservations($run->observations);
        $metrics = $this->resolveRunMetrics($run);
        $total = $eligible->count();

        return [
            'score_version' => GeoMonitorAttributionScorer::SCORE_VERSION,
            'geo_score' => (float) ($metrics['geo_score'] ?? 0),
            'total' => $total,
            'eligible_observations' => (int) ($metrics['eligible_observations'] ?? $total),
            'brand_mentions' => (int) ($metrics['brand_mention_count'] ?? 0),
            'brand_mention_rate' => (float) ($metrics['brand_mention_rate'] ?? 0),
            'own_citations' => (int) ($metrics['own_citations'] ?? 0),
            'own_citation_rate' => (float) ($metrics['own_citation_rate'] ?? 0),
            'citation_coverage_rate' => (float) ($metrics['citation_coverage_rate'] ?? 0),
            'competitor_citations' => (int) ($metrics['competitor_citations'] ?? 0),
            'competitor_citation_rate' => (float) ($metrics['competitor_citation_rate'] ?? 0),
            'competitor_mentions' => (int) ($metrics['competitor_mention_count'] ?? 0),
            'competitor_mention_rate' => (float) ($metrics['competitor_mention_rate'] ?? 0),
            'platform_coverage_index' => (float) ($metrics['platform_coverage_index'] ?? 0),
            'own_citation_avg_rank' => $metrics['own_citation_avg_rank'] ?? null,
            'keyword_hits' => $keywordHits = $this->keywordHits($eligible),
            'conclusion' => $this->conclusion($metrics, count($keywordHits)),
            'platform_breakdown' => $this->platformBreakdown($eligible),
            'prompt_breakdown' => $this->promptBreakdown($eligible),
            'competitor_comparison' => $this->competitorComparison($run->project, $eligible),
            'top_sources' => $this->topCitationSources($eligible),
            'failure_distribution' => $this->failureDistribution($run->observations),
        ];
    }

    /**
     * 构建项目页摘要（最近一次已完成批次）。
     *
     * @param  GeoMonitorProject  $project  监测项目
     * @return array<string, mixed>|null
     */
    public function buildProjectSummary(GeoMonitorProject $project): ?array
    {
        $latestRun = GeoMonitorRun::query()
            ->where('project_id', $project->id)
            ->whereIn('status', ['succeeded', 'partial', 'failed'])
            ->orderByDesc('id')
            ->first();

        if ($latestRun === null) {
            return null;
        }

        $report = $this->buildRunReport($latestRun);

        return [
            'run_id' => $latestRun->id,
            'run_status' => $latestRun->status,
            'finished_at' => $latestRun->finished_at?->format('Y-m-d H:i'),
            'geo_score' => $report['geo_score'],
            'brand_mention_rate' => $report['brand_mention_rate'],
            'own_citation_rate' => $report['own_citation_rate'],
            'conclusion' => $report['conclusion'],
        ];
    }

    /**
     * 读取或即时计算批次指标。
     *
     * @param  GeoMonitorRun  $run  批次运行
     * @return array<string, mixed>
     */
    private function resolveRunMetrics(GeoMonitorRun $run): array
    {
        $stored = GeoMonitorScore::query()
            ->where('run_id', $run->id)
            ->whereNull('observation_id')
            ->where('score_version', GeoMonitorAttributionScorer::SCORE_VERSION)
            ->first();

        if ($stored !== null && is_array($stored->metrics)) {
            return $stored->metrics;
        }

        return $this->scorer->buildRunMetrics($run);
    }

    /**
     * 关键词命中列表。
     *
     * @param  Collection<int, GeoMonitorObservation>  $eligible  合格观测
     * @return list<string>
     */
    private function keywordHits(Collection $eligible): array
    {
        return $eligible
            ->flatMap(fn ($observation) => $observation->mentions
                ->whereIn('entity_type', ['product_keyword', 'prompt_keyword'])
                ->pluck('entity_name'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 根据指标生成可读结论。
     *
     * @param  array<string, mixed>  $metrics  批次指标
     * @param  int  $keywordHitCount  关键词命中种类数
     */
    private function conclusion(array $metrics, int $keywordHitCount): string
    {
        $brandMentions = (int) ($metrics['brand_mention_count'] ?? 0);
        $ownCitations = (int) ($metrics['own_citations'] ?? 0);

        if ($brandMentions > 0 && $ownCitations > 0) {
            return __('admin.geo_monitoring.report_conclusion_mentioned_with_own_citation');
        }

        if ($brandMentions > 0) {
            return __('admin.geo_monitoring.report_conclusion_mentioned_without_own_citation');
        }

        if ($keywordHitCount > 0) {
            return __('admin.geo_monitoring.report_conclusion_keywords_only');
        }

        return __('admin.geo_monitoring.report_conclusion_no_mentions');
    }

    /**
     * 平台维度拆解。
     *
     * @param  Collection<int, GeoMonitorObservation>  $eligible  合格观测
     * @return list<array<string, mixed>>
     */
    private function platformBreakdown(Collection $eligible): array
    {
        return $eligible
            ->groupBy(fn ($observation) => $observation->platform_id)
            ->map(function (Collection $group): array {
                $platform = $group->first()?->platform;
                $total = $group->count();
                $brandHits = $group->filter(
                    fn ($item): bool => $item->mentions->where('entity_type', 'own_brand')->isNotEmpty()
                )->count();
                $ownCitationHits = $group->filter(
                    fn ($item): bool => $item->citations->where('is_own_domain', true)->isNotEmpty()
                )->count();

                return [
                    'platform_code' => $platform?->code ?? 'unknown',
                    'platform_label' => $platform?->label ?? __('admin.geo_monitoring.report_unknown_platform'),
                    'observations' => $total,
                    'brand_mention_rate' => $this->rate($brandHits, $total),
                    'own_citation_rate' => $this->rate($ownCitationHits, $total),
                    'geo_score' => round(($this->rate($brandHits, $total) * 0.5) + ($this->rate($ownCitationHits, $total) * 0.5), 1),
                ];
            })
            ->sortByDesc('geo_score')
            ->values()
            ->all();
    }

    /**
     * 问题维度拆解。
     *
     * @param  Collection<int, GeoMonitorObservation>  $eligible  合格观测
     * @return list<array<string, mixed>>
     */
    private function promptBreakdown(Collection $eligible): array
    {
        return $eligible
            ->groupBy('prompt_id')
            ->map(function (Collection $group): array {
                $observation = $group->first();
                $total = $group->count();
                $brandHits = $group->filter(
                    fn ($item): bool => $item->mentions->where('entity_type', 'own_brand')->isNotEmpty()
                )->count();
                $ownCitationHits = $group->filter(
                    fn ($item): bool => $item->citations->where('is_own_domain', true)->isNotEmpty()
                )->count();

                return [
                    'prompt_text' => $observation?->prompt_text_snapshot ?? '',
                    'observations' => $total,
                    'brand_mention_rate' => $this->rate($brandHits, $total),
                    'own_citation_rate' => $this->rate($ownCitationHits, $total),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 竞品品牌与我方对比。
     *
     * @param  GeoMonitorProject  $project  监测项目
     * @param  Collection<int, GeoMonitorObservation>  $eligible  合格观测
     * @return list<array<string, mixed>>
     */
    private function competitorComparison(GeoMonitorProject $project, Collection $eligible): array
    {
        $rows = [];

        $ownBrandMentions = $eligible->filter(
            fn ($item): bool => $item->mentions->where('entity_type', 'own_brand')->isNotEmpty()
        )->count();
        $ownBrandCitations = $eligible->sum(
            fn ($item): int => $item->citations->where('is_own_domain', true)->count()
        );

        if ((string) $project->brand_name !== '') {
            $rows[] = [
                'name' => $project->brand_name,
                'type' => 'own',
                'mention_observations' => $ownBrandMentions,
                'citation_count' => $ownBrandCitations,
            ];
        }

        foreach ($this->competitorBrandNames($project) as $competitor) {
            $mentionHits = $eligible->filter(
                fn ($item): bool => $item->mentions
                    ->where('entity_type', 'competitor_brand')
                    ->where('entity_name', $competitor)
                    ->isNotEmpty()
            )->count();

            $rows[] = [
                'name' => $competitor,
                'type' => 'competitor',
                'mention_observations' => $mentionHits,
                'citation_count' => 0,
            ];
        }

        foreach ($this->competitorDomainNames($project) as $domain) {
            $citationHits = $eligible->sum(
                fn ($item): int => $item->citations
                    ->filter(fn ($citation): bool => $this->domainMatches((string) $citation->domain, $domain))
                    ->count()
            );

            $rows[] = [
                'name' => $domain,
                'type' => 'competitor_domain',
                'mention_observations' => 0,
                'citation_count' => $citationHits,
            ];
        }

        return $rows;
    }

    /**
     * TOP 引用来源域名。
     *
     * @param  Collection<int, GeoMonitorObservation>  $eligible  合格观测
     * @return list<array<string, mixed>>
     */
    private function topCitationSources(Collection $eligible): array
    {
        $counts = [];

        foreach ($eligible as $observation) {
            foreach ($observation->citations as $citation) {
                $domain = (string) $citation->domain;

                if ($domain === '') {
                    continue;
                }

                if (! isset($counts[$domain])) {
                    $counts[$domain] = [
                        'domain' => $domain,
                        'count' => 0,
                        'is_own' => (bool) $citation->is_own_domain,
                        'is_competitor' => (bool) $citation->is_competitor_domain,
                    ];
                }

                $counts[$domain]['count']++;
            }
        }

        usort($counts, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice(array_values($counts), 0, 10);
    }

    /**
     * 失败与异常状态分布。
     *
     * @param  Collection<int, GeoMonitorObservation>  $observations  全部观测
     * @return list<array{status: string, count: int}>
     */
    private function failureDistribution(Collection $observations): array
    {
        $successStatuses = ['success', 'partial'];

        return $observations
            ->reject(fn ($item): bool => in_array($item->status, $successStatuses, true))
            ->groupBy('status')
            ->map(fn (Collection $group, string $status): array => [
                'status' => $status,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  GeoMonitorProject  $project  监测项目
     * @return list<string>
     */
    private function competitorBrandNames(GeoMonitorProject $project): array
    {
        if (! is_array($project->competitor_brands)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item): string => is_string($item) ? trim($item) : '',
            $project->competitor_brands,
        )));
    }

    /**
     * @param  GeoMonitorProject  $project  监测项目
     * @return list<string>
     */
    private function competitorDomainNames(GeoMonitorProject $project): array
    {
        if (! is_array($project->competitor_domains)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item): string => is_string($item) ? trim($item) : '',
            $project->competitor_domains,
        )));
    }

    /**
     * 判断引用域名是否匹配配置域名（含子域）。
     *
     * @param  string  $citationDomain  引用域名
     * @param  string  $configured  配置域名
     */
    private function domainMatches(string $citationDomain, string $configured): bool
    {
        $citationDomain = strtolower(trim($citationDomain));
        $configured = strtolower(trim($configured));

        if ($citationDomain === '' || $configured === '') {
            return false;
        }

        if (str_contains($configured, '://')) {
            $configured = (string) parse_url($configured, PHP_URL_HOST);
        }

        $configured = str_starts_with($configured, 'www.') ? substr($configured, 4) : $configured;

        return $citationDomain === $configured
            || str_ends_with($citationDomain, '.'.$configured);
    }

    /**
     * 计算百分比。
     *
     * @param  int  $hits  命中数
     * @param  int  $total  总数
     */
    private function rate(int $hits, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($hits / $total) * 100, 1);
    }
}
