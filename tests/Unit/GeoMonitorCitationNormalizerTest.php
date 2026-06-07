<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GeoMonitorProject;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorCitationNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoMonitorCitationNormalizerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * URL 应去掉常见追踪参数并规范域名。
     */
    public function test_normalizes_url_and_classifies_domains(): void
    {
        $project = GeoMonitorProject::query()->create([
            'name' => 'Normalizer',
            'slug' => 'normalizer',
            'brand_name' => '神州租车',
            'primary_domain' => 'zuche.com',
            'competitor_domains' => ['1hai.cn'],
            'status' => 'active',
        ]);

        $normalizer = GeoMonitorCitationNormalizer::forProject($project);

        $url = $normalizer->normalizeUrl('https://www.zuche.com/page?utm_source=test&gclid=abc&id=1');
        $domain = $normalizer->normalizeDomain($url);

        $this->assertSame('https://www.zuche.com/page?id=1', $url);
        $this->assertSame('zuche.com', $domain);
        $this->assertTrue($normalizer->isOwnDomain($domain));
        $this->assertTrue($normalizer->isCompetitorDomain('api.1hai.cn'));
    }
}
