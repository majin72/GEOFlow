<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminArticleSearchSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_article_search_settings_page(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.article-search'))
            ->assertOk()
            ->assertSee(__('admin.article_search.page_title'))
            ->assertSee(__('admin.article_search.field_api_key'));
    }

    public function test_admin_can_save_article_search_settings(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.article-search.update'), [
                'enabled' => '1',
                'endpoint' => 'https://api.tavily.test/search',
                'api_key' => 'tvly-test-key',
                'timeout' => 15,
                'max_results' => 6,
                'search_depth' => 'advanced',
                'include_domains' => 'example.com, docs.example.com',
                'cache_ttl' => 7200,
            ])
            ->assertRedirect(route('admin.site-settings.article-search'));

        $this->assertDatabaseHas('site_settings', [
            'setting_key' => 'article_search_enabled',
            'setting_value' => '1',
        ]);
        $this->assertSame('tvly-test-key', SiteSetting::query()->where('setting_key', 'article_search_api_key')->value('setting_value'));
        $this->assertSame('example.com,docs.example.com', SiteSetting::query()->where('setting_key', 'article_search_include_domains')->value('setting_value'));
        $this->assertSame('advanced', SiteSetting::query()->where('setting_key', 'article_search_depth')->value('setting_value'));
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'article_search_admin',
            'password' => 'secret-123',
            'email' => 'article-search-admin@example.com',
            'display_name' => 'Article Search Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
