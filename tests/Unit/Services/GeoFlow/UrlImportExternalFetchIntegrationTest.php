<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GeoFlow;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchResult;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchService;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * 验证 URL 导入 fetch/page_json 两步对外部浏览器抓取的接入行为。
 */
class UrlImportExternalFetchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_step_uses_external_primary_when_domain_matches(): void
    {
        $url = 'https://zhuanlan.zhihu.com/p/665715823';
        $externalFetch = Mockery::mock(ExternalFetchService::class);
        $externalFetch->shouldReceive('shouldUseExternal')->once()->with($url)->andReturnTrue();
        $externalFetch->shouldReceive('fetch')->once()->with($url)->andReturn(
            new ExternalFetchResult("# 向量数据库 Chroma 极简教程\n\n这是通过本地浏览器抓取的 Markdown 正文。", 'markdown', 'mac-local', 1714896000000)
        );

        $service = new UrlImportProcessingService(new ApiKeyCrypto, $externalFetch);
        $job = $this->createJob($url);

        $job = $service->processFetchStep($job);

        $this->assertSame('external_primary', $job->fetch_source);
        $this->assertStringContainsString('Markdown 正文', (string) $job->fetched_markdown);
        $this->assertSame('external_primary', data_get($service->decodeResult($job), 'source.fetch_source'));

        $job = $service->processPageJsonStep($job);
        $result = $service->decodeResult($job);

        $this->assertSame('向量数据库 Chroma 极简教程', data_get($result, 'page.title'));
        $this->assertStringContainsString('本地浏览器抓取', (string) data_get($result, 'page.text'));
    }

    public function test_fetch_step_falls_back_to_external_when_direct_fetch_returns_retry_status(): void
    {
        $url = 'https://example.test/blocked';
        Http::fake([
            $url => Http::response('forbidden', 403),
        ]);

        $externalFetch = Mockery::mock(ExternalFetchService::class);
        $externalFetch->shouldReceive('shouldUseExternal')->once()->with($url)->andReturnFalse();
        $externalFetch->shouldReceive('isFallbackStatus')->once()->with(403)->andReturnTrue();
        $externalFetch->shouldReceive('fetch')->once()->with($url)->andReturn(
            new ExternalFetchResult("# 回退抓取标题\n\n403 后由 opencli 回退抓取成功。", 'markdown', 'mac-local', 1714896000000)
        );

        $service = new UrlImportProcessingService(new ApiKeyCrypto, $externalFetch);
        $job = $this->createJob($url);

        $job = $service->processFetchStep($job);

        $this->assertSame('external_fallback', $job->fetch_source);
        $this->assertStringContainsString('opencli 回退抓取成功', (string) $job->fetched_markdown);
        $this->assertSame('external_fallback', data_get($service->decodeResult($job), 'source.fetch_source'));
    }

    public function test_direct_fetch_behavior_remains_unchanged_for_non_external_url(): void
    {
        $url = 'https://example.test/report';
        Http::fake([
            $url => Http::response(
                '<!doctype html><html><head><title>普通页面标题</title><meta name="description" content="普通页面摘要"></head><body><main><p>普通 HTML 正文。</p></main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
        ]);

        $externalFetch = Mockery::mock(ExternalFetchService::class);
        $externalFetch->shouldReceive('shouldUseExternal')->once()->with($url)->andReturnFalse();
        $externalFetch->shouldNotReceive('fetch');

        $service = new UrlImportProcessingService(new ApiKeyCrypto, $externalFetch);
        $job = $this->createJob($url);

        $job = $service->processFetchStep($job);

        $this->assertSame('direct', $job->fetch_source);
        $this->assertNull($job->fetched_markdown);
        $this->assertSame('direct', data_get($service->decodeResult($job), 'source.fetch_source'));

        $job = $service->processPageJsonStep($job);
        $result = $service->decodeResult($job);

        $this->assertSame('普通页面标题', data_get($result, 'page.title'));
        $this->assertStringContainsString('普通 HTML 正文', (string) data_get($result, 'page.text'));
    }

    private function createJob(string $url): UrlImportJob
    {
        return UrlImportJob::query()->create([
            'url' => $url,
            'normalized_url' => $url,
            'source_domain' => (string) parse_url($url, PHP_URL_HOST),
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
            'options_json' => '{}',
            'result_json' => '{}',
            'error_message' => '',
            'created_by' => 'test-admin',
        ]);
    }
}
