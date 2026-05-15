<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GeoFlow;

use App\Services\GeoFlow\ArticleSearch\ArticleSearchConfig;
use App\Services\GeoFlow\ArticleSearch\TavilyArticleSearchService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TavilyArticleSearchServiceTest extends TestCase
{
    private const ENDPOINT = 'https://api.tavily.test/search';

    public function test_search_sends_expected_tavily_request(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'results' => [
                    [
                        'title' => '床车旅行指南',
                        'url' => 'https://example.com/van-trip',
                        'content' => '床车旅行需要关注停车、补能和安全。',
                    ],
                ],
            ], 200),
        ]);

        $service = $this->makeService();
        $result = $service->search('床车旅行');

        $this->assertStringContainsString('床车旅行指南', $result);
        $this->assertStringContainsString('https://example.com/van-trip', $result);

        Http::assertSent(static function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::ENDPOINT
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && ($body['query'] ?? null) === '床车旅行'
                && ($body['search_depth'] ?? null) === 'basic'
                && ($body['max_results'] ?? null) === 5;
        });
    }

    public function test_search_reuses_cache_for_same_query_and_config(): void
    {
        Cache::flush();
        Http::fake([
            self::ENDPOINT => Http::response([
                'results' => [
                    [
                        'title' => 'Cached Result',
                        'url' => 'https://example.com/cached',
                        'content' => 'cached content',
                    ],
                ],
            ], 200),
        ]);

        $service = $this->makeService(['cacheTtl' => 3600]);

        $first = $service->search('床车旅行');
        $second = $service->search(' 床车旅行 ');

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_search_returns_disabled_message_when_unusable(): void
    {
        $service = $this->makeService(['enabled' => false]);

        $this->assertStringContainsString('未启用', $service->search('床车旅行'));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeService(array $overrides = []): TavilyArticleSearchService
    {
        $config = new ArticleSearchConfig(
            enabled: $overrides['enabled'] ?? true,
            provider: ArticleSearchConfig::DEFAULT_PROVIDER,
            endpoint: $overrides['endpoint'] ?? self::ENDPOINT,
            apiKey: $overrides['apiKey'] ?? 'test-key',
            timeout: $overrides['timeout'] ?? 20,
            maxResults: $overrides['maxResults'] ?? 5,
            searchDepth: $overrides['searchDepth'] ?? 'basic',
            includeDomains: $overrides['includeDomains'] ?? [],
            cacheTtl: $overrides['cacheTtl'] ?? 0,
        );

        return new TavilyArticleSearchService(
            $config,
            app(HttpFactory::class),
            app(CacheRepository::class),
        );
    }
}
