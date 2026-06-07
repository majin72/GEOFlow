<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorBrowserProfile;
use App\Models\GeoMonitorCitation;
use App\Models\GeoMonitorMention;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorResourceAssignment;
use Illuminate\Support\Facades\DB;

/**
 * 将 sidecar ProbeResult 写入 geo_monitor_* 表。
 */
class GeoMonitorProbePersister
{
    /**
     * @param  GeoMonitorAttributionScorer|null  $scorer  引用度评分（测试可注入 null）
     */
    public function __construct(
        private readonly ?GeoMonitorAttributionScorer $scorer = null,
    ) {}

    /**
     * 用 sidecar 响应更新已有观测记录。
     *
     * @param  GeoMonitorObservation  $observation  待更新的观测
     * @param  array<string, mixed>  $probeResult  sidecar data 字典
     * @param  GeoMonitorAccount|null  $account  使用的账号（可为空）
     * @param  GeoMonitorResourceBundle|null  $bundle  调度资源包
     */
    public function persist(
        GeoMonitorObservation $observation,
        array $probeResult,
        ?GeoMonitorAccount $account = null,
        ?GeoMonitorResourceBundle $bundle = null,
    ): GeoMonitorObservation {
        $observation->loadMissing('project');

        $answerText = (string) ($probeResult['answer_text'] ?? '');
        $evidence = is_array($probeResult['evidence'] ?? null) ? $probeResult['evidence'] : [];
        $meta = is_array($probeResult['meta'] ?? null) ? $probeResult['meta'] : [];

        DB::transaction(function () use ($observation, $probeResult, $answerText, $evidence, $meta, $account, $bundle): void {
            $observation->fill([
                'account_id' => $account?->id,
                'status' => (string) ($probeResult['status'] ?? 'failed'),
                'login_status' => (string) ($probeResult['login_status'] ?? 'unknown'),
                'answer_text' => $answerText !== '' ? $answerText : null,
                'answer_hash' => $answerText !== '' ? hash('sha256', $answerText) : null,
                'error_message' => (string) ($probeResult['error_message'] ?? '') ?: null,
                'duration_ms' => max(0, (int) ($probeResult['duration_ms'] ?? 0)),
                'screenshot_path' => (string) ($evidence['screenshot_path'] ?? '') ?: null,
                'html_path' => (string) ($evidence['html_path'] ?? '') ?: null,
                'raw_text_path' => (string) ($evidence['raw_text_path'] ?? '') ?: null,
                'markdown_path' => (string) ($evidence['markdown_path'] ?? '') ?: null,
                'meta' => $meta,
                'probed_at' => now(),
            ]);
            $observation->save();

            $observation->citations()->delete();
            $this->persistCitations($observation, $probeResult);

            $observation->mentions()->delete();
            $this->persistMentions($observation, $answerText);

            $this->persistResourceAssignment($observation, $bundle, $account, $meta);
        });

        $observation = $observation->fresh(['citations', 'mentions', 'resourceAssignment', 'platform', 'prompt']);
        $this->scoreObservationIfPossible($observation);

        return $observation;
    }

    /**
     * 将 sidecar 异常写入观测（无 ProbeResult 正文）。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     * @param  string  $status  业务状态
     * @param  string  $message  错误说明
     * @param  GeoMonitorResourceBundle|null  $bundle  调度资源包
     */
    public function persistFailure(
        GeoMonitorObservation $observation,
        string $status,
        string $message,
        ?GeoMonitorResourceBundle $bundle = null,
    ): GeoMonitorObservation {
        $observation->update([
            'status' => $status,
            'error_message' => $message,
            'probed_at' => now(),
            'account_id' => $bundle?->account->id ?? $observation->account_id,
        ]);

        if ($bundle !== null) {
            $this->persistResourceAssignment($observation, $bundle, $bundle->account, []);
        }

        return $observation->fresh();
    }

    /**
     * @param  GeoMonitorObservation  $observation  观测
     * @param  array<string, mixed>  $probeResult  sidecar 结果
     */
    private function persistCitations(GeoMonitorObservation $observation, array $probeResult): void
    {
        $normalizer = GeoMonitorCitationNormalizer::forProject($observation->project);
        $citations = $probeResult['citations'] ?? [];

        if (! is_array($citations)) {
            return;
        }

        $position = 0;

        foreach ($citations as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $normalizer->normalizeUrl((string) ($item['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $position++;
            $domain = $normalizer->normalizeDomain($url);

            GeoMonitorCitation::query()->create([
                'observation_id' => $observation->id,
                'url' => $url,
                'domain' => mb_substr($domain, 0, 255),
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 500) ?: null,
                'snippet' => (string) ($item['snippet'] ?? '') ?: null,
                'source_type' => (string) ($item['source_type'] ?? 'link'),
                'position' => (int) ($item['position'] ?? $position),
                'is_own_domain' => $normalizer->isOwnDomain($domain),
                'is_competitor_domain' => $normalizer->isCompetitorDomain($domain),
            ]);
        }
    }

    /**
     * 从回答正文中抽取项目品牌、竞品品牌和关键词命中。
     *
     * @param  GeoMonitorObservation  $observation  观测
     * @param  string  $answerText  回答正文
     */
    private function persistMentions(GeoMonitorObservation $observation, string $answerText): void
    {
        $answerText = trim($answerText);

        if ($answerText === '') {
            return;
        }

        $position = 0;

        foreach ($this->mentionCandidates($observation) as $candidate) {
            $index = mb_stripos($answerText, $candidate['name']);

            if ($index === false) {
                continue;
            }

            $position++;

            GeoMonitorMention::query()->create([
                'observation_id' => $observation->id,
                'entity_name' => $candidate['name'],
                'entity_type' => $candidate['type'],
                'mention_text' => $candidate['name'],
                'context_snippet' => $this->contextSnippet($answerText, $index),
                'position' => $position,
                'is_recommendation' => $this->looksLikeRecommendation($answerText, $index),
            ]);
        }
    }

    /**
     * 生成需要在回答里匹配的实体清单。
     *
     * @param  GeoMonitorObservation  $observation  观测
     * @return list<array{name: string, type: string}>
     */
    private function mentionCandidates(GeoMonitorObservation $observation): array
    {
        $observation->loadMissing(['project', 'prompt']);

        $candidates = [];
        $seen = [];

        $this->pushMentionCandidate($candidates, $seen, (string) $observation->project->brand_name, 'own_brand');
        $this->pushListCandidates($candidates, $seen, $observation->project->competitor_brands, 'competitor_brand');
        $this->pushListCandidates($candidates, $seen, $observation->project->product_keywords, 'product_keyword');

        return $candidates;
    }

    /**
     * 批量加入候选实体。
     *
     * @param  list<array{name: string, type: string}>  $candidates  候选实体
     * @param  array<string, true>  $seen  去重字典
     * @param  mixed  $items  原始列表
     * @param  string  $type  实体类型
     */
    private function pushListCandidates(array &$candidates, array &$seen, mixed $items, string $type): void
    {
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            $this->pushMentionCandidate($candidates, $seen, $item, $type);
        }
    }

    /**
     * 加入单个候选实体并去重。
     *
     * @param  list<array{name: string, type: string}>  $candidates  候选实体
     * @param  array<string, true>  $seen  去重字典
     * @param  string  $name  实体名称
     * @param  string  $type  实体类型
     */
    private function pushMentionCandidate(array &$candidates, array &$seen, string $name, string $type): void
    {
        $name = trim($name);

        if ($name === '') {
            return;
        }

        $key = mb_strtolower($type.':'.$name);

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $candidates[] = ['name' => $name, 'type' => $type];
    }

    /**
     * 截取命中词附近上下文，便于后台报表展示。
     *
     * @param  string  $answerText  回答正文
     * @param  int  $index  命中位置
     */
    private function contextSnippet(string $answerText, int $index): string
    {
        $start = max(0, $index - 40);

        return mb_substr($answerText, $start, 120);
    }

    /**
     * 判断命中上下文是否像推荐语境。
     *
     * @param  string  $answerText  回答正文
     * @param  int  $index  命中位置
     */
    private function looksLikeRecommendation(string $answerText, int $index): bool
    {
        $snippet = $this->contextSnippet($answerText, $index);

        foreach (['推荐', '首选', '优先', '靠谱', '适合', '建议', '优势'] as $signal) {
            if (mb_stripos($snippet, $signal) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 探测成功后写入观测级评分快照。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     */
    private function scoreObservationIfPossible(GeoMonitorObservation $observation): void
    {
        if ($observation->probed_at === null) {
            return;
        }

        $scorer = $this->scorer ?? GeoMonitorAttributionScorer::fromConfig();
        $scorer->scoreObservation($observation);
    }

    /**
     * @param  GeoMonitorObservation  $observation  观测
     * @param  GeoMonitorResourceBundle|null  $bundle  调度资源包
     * @param  GeoMonitorAccount|null  $account  账号
     * @param  array<string, mixed>  $meta  sidecar meta
     */
    private function persistResourceAssignment(
        GeoMonitorObservation $observation,
        ?GeoMonitorResourceBundle $bundle,
        ?GeoMonitorAccount $account,
        array $meta,
    ): void {
        $resource = is_array($meta['resource'] ?? null) ? $meta['resource'] : [];

        if ($bundle !== null) {
            $resource = $bundle->toSidecarResource();
        }

        $browserProfileId = $bundle?->profile?->id;
        $proxyEndpointId = $bundle?->proxy?->id ?? $account?->proxy_endpoint_id;

        if ($browserProfileId === null && $account !== null) {
            $profile = GeoMonitorBrowserProfile::query()
                ->where('account_id', $account->id)
                ->first();
            $browserProfileId = $profile?->id;
        }

        GeoMonitorResourceAssignment::query()->updateOrCreate(
            ['observation_id' => $observation->id],
            [
                'account_id' => $account?->id,
                'browser_profile_id' => $browserProfileId,
                'proxy_endpoint_id' => $proxyEndpointId,
                'scheduler_strategy' => $bundle?->schedulerStrategy ?? 'default_active_account',
                'assigned_at' => now(),
                'meta' => $resource !== [] ? $resource : null,
            ],
        );
    }
}
