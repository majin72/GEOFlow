<?php

namespace Tests\Unit;

use App\Ai\Tools\AdminOpsSiteInfoTool;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AdminOpsSiteInfoToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_site_name_from_database(): void
    {
        SiteSetting::query()->create([
            'setting_key' => 'site_name',
            'setting_value' => '单元测试站点',
        ]);

        $tool = app(AdminOpsSiteInfoTool::class);
        $out = (string) $tool->handle(new Request([]));
        $decoded = json_decode($out, true);

        $this->assertIsArray($decoded);
        $this->assertTrue((bool) ($decoded['ok'] ?? false));
        $this->assertSame('单元测试站点', $decoded['settings']['site_name'] ?? null);
    }

    public function test_scope_full_includes_analytics_length_hint_or_truncation(): void
    {
        SiteSetting::query()->create([
            'setting_key' => 'analytics_code',
            'setting_value' => str_repeat('a', 1200),
        ]);

        $tool = app(AdminOpsSiteInfoTool::class);
        $out = (string) $tool->handle(new Request(['scope' => 'full']));
        $decoded = json_decode($out, true);

        $this->assertIsArray($decoded);
        $this->assertTrue((bool) ($decoded['ok'] ?? false));
        $this->assertLessThanOrEqual(800, mb_strlen((string) ($decoded['settings']['analytics_code'] ?? '')));
    }

    /**
     * 校验 integrations 汇总含脱敏后的联网搜索密钥，且不泄露原文。
     */
    public function test_integrations_masks_article_search_api_key(): void
    {
        SiteSetting::query()->create([
            'setting_key' => 'article_search_enabled',
            'setting_value' => '1',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'article_search_api_key',
            'setting_value' => 'tvly-secret-key-very-long',
        ]);

        $tool = app(AdminOpsSiteInfoTool::class);
        $out = (string) $tool->handle(new Request([]));
        $decoded = json_decode($out, true);

        $this->assertIsArray($decoded);
        $masked = (string) (($decoded['integrations']['article_search']['api_key'] ?? ''));
        $this->assertNotSame('tvly-secret-key-very-long', $masked);
        $this->assertStringNotContainsString('tvly-secret', $masked);
        $this->assertTrue((bool) ($decoded['integrations']['article_search']['enabled'] ?? false));
    }
}
