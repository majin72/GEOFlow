<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\ArticleSearch;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Tavily 搜索服务：负责缓存、请求外部 API，并把结果压缩成适合 Tool 返回的文本。
 */
class TavilyArticleSearchService
{
    public function __construct(
        private readonly ArticleSearchConfig $config,
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * 当前配置是否可用于文章生成等场景（需站点总开关 + API Key）。
     */
    public function isEnabled(): bool
    {
        return $this->config->isUsable();
    }

    /**
     * AI 运维等场景：仅要求已配置 Tavily API Key，不校验站点 article_search_enabled。
     */
    public function isEnabledForAiOps(): bool
    {
        return $this->config->hasApiKeyConfigured();
    }

    /**
     * 执行搜索并返回给模型可读的资料块。
     *
     * @param  bool  $forAiOps  为 true 时不校验站点「文章联网搜索」总开关，仅要求 API Key 已配置
     */
    public function search(string $query, bool $forAiOps = false): string
    {
        $query = $this->normalizeQuery($query);
        if ($query === '') {
            return '搜索查询为空，未执行联网搜索。';
        }

        if ($forAiOps) {
            if (! $this->isEnabledForAiOps()) {
                return 'Tavily API Key 未配置。请在后台「网站设置 → 文章联网搜索」中填写 API Key 后，再在 AI 运维中勾选联网模式。';
            }
        } elseif (! $this->isEnabled()) {
            return '联网搜索未启用或 Tavily API Key 未配置。';
        }

        $cacheKey = $this->cacheKey($query);
        if ($this->config->cacheTtl > 0) {
            return (string) $this->cache->remember(
                $cacheKey,
                $this->config->cacheTtl,
                fn (): string => $this->requestTavily($query)
            );
        }

        return $this->requestTavily($query);
    }

    /**
     * 生成缓存 key，包含影响结果的配置项。
     */
    public function cacheKey(string $query): string
    {
        $payload = [
            'provider' => $this->config->provider,
            'query' => $this->normalizeQuery($query),
            'endpoint' => $this->config->endpoint,
            'max_results' => $this->config->maxResults,
            'search_depth' => $this->config->searchDepth,
            'include_domains' => $this->config->includeDomains,
        ];

        return 'geoflow:article_search:'.sha1((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function requestTavily(string $query): string
    {
        $payload = [
            'query' => $query,
            'search_depth' => $this->config->searchDepth,
            'max_results' => $this->config->maxResults,
            'include_answer' => false,
            'include_raw_content' => false,
        ];

        if ($this->config->includeDomains !== []) {
            $payload['include_domains'] = $this->config->includeDomains;
        }

        $response = $this->http
            ->timeout($this->config->timeout)
            ->acceptJson()
            ->asJson()
            ->withToken($this->config->apiKey)
            ->post($this->config->endpoint, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Tavily 搜索失败，HTTP '.$response->status());
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Tavily 搜索返回格式不正确');
        }

        return $this->formatResults($query, $data);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function formatResults(string $query, array $data): string
    {
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];
        if ($results === []) {
            return "联网搜索未找到与「{$query}」相关的结果。";
        }

        $lines = ["联网搜索结果（查询：{$query}）："];
        foreach (array_slice($results, 0, $this->config->maxResults) as $index => $result) {
            if (! is_array($result)) {
                continue;
            }

            $title = trim((string) ($result['title'] ?? 'Untitled'));
            $url = trim((string) ($result['url'] ?? ''));
            $content = trim((string) ($result['content'] ?? $result['snippet'] ?? ''));
            $content = $this->limitText($content, 420);

            $lines[] = sprintf('%d. %s', $index + 1, $title);
            if ($url !== '') {
                $lines[] = '   来源：'.$url;
            }
            if ($content !== '') {
                $lines[] = '   摘要：'.$content;
            }
        }

        return implode("\n", $lines);
    }

    private function normalizeQuery(string $query): string
    {
        $query = preg_replace('/\s+/u', ' ', trim($query)) ?: trim($query);

        return mb_strtolower($query, 'UTF-8');
    }

    private function limitText(string $text, int $maxLength): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?: trim($text);
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength, 'UTF-8').'...';
    }
}
