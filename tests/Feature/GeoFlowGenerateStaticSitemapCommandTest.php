<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoFlowGenerateStaticSitemapCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 校验命令生成 public/sitemap.xml 且包含首页与文章地址。
     */
    public function test_command_writes_sitemap_with_article_url(): void
    {
        $author = Author::query()->create([
            'name' => 'Sitemap Author',
            'slug' => 'sitemap-author',
            'bio' => '',
            'avatar' => '',
        ]);
        $category = Category::query()->create([
            'name' => 'News',
            'slug' => 'news',
            'description' => '',
            'sort_order' => 0,
        ]);
        Article::query()->create([
            'title' => 'Hello Sitemap',
            'slug' => 'hello-sitemap',
            'excerpt' => '',
            'content' => '# Hello',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now()->subDay(),
        ]);

        $path = storage_path('app/testing-sitemap.xml');
        if (is_file($path)) {
            unlink($path);
        }

        $this->artisan('geoflow:generate-static-sitemap', ['--path' => $path])
            ->assertSuccessful();

        $this->assertFileExists($path);
        $xml = (string) file_get_contents($path);
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString(route('site.home'), $xml);
        $this->assertStringContainsString(route('site.article', 'hello-sitemap'), $xml);
        $this->assertStringContainsString(route('site.category', 'news'), $xml);

        unlink($path);
    }
}
