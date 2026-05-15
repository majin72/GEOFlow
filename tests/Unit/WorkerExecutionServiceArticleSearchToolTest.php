<?php

namespace Tests\Unit;

use App\Ai\Tools\TavilyWebSearchTool;
use App\Models\SiteSetting;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServiceArticleSearchToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_does_not_attach_search_tool_when_disabled(): void
    {
        $tools = $this->buildWriterTools();

        $this->assertSame([], $tools);
    }

    public function test_worker_attaches_tavily_search_tool_when_enabled_and_configured(): void
    {
        foreach ([
            'article_search_enabled' => '1',
            'article_search_endpoint' => 'https://api.tavily.test/search',
            'article_search_api_key' => 'tvly-test-key',
        ] as $key => $value) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value]
            );
        }
        SiteSettingsBag::forget();

        $tools = $this->buildWriterTools();

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(TavilyWebSearchTool::class, $tools[0]);
    }

    /**
     * @return list<object>
     */
    private function buildWriterTools(): array
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildWriterTools');
        $method->setAccessible(true);

        return $method->invoke($service);
    }
}
