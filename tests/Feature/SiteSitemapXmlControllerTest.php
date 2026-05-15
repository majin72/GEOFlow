<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Site\SiteStaticSitemapBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSitemapXmlControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 主 sitemap 文件存在时可访问。
     */
    public function test_main_sitemap_returns_file_when_present(): void
    {
        $path = SiteStaticSitemapBuilder::defaultOutputPath();
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "<?xml version=\"1.0\" encoding=\"UTF-8\"?><urlset></urlset>\n");

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        @unlink($path);
    }

    /**
     * 主 sitemap 不存在时 404。
     */
    public function test_main_sitemap_returns_404_when_missing(): void
    {
        $path = SiteStaticSitemapBuilder::defaultOutputPath();
        if (is_file($path)) {
            unlink($path);
        }

        $this->get('/sitemap.xml')->assertNotFound();
    }

    /**
     * 分片 URL 与 storage/app/public/sitemaps/{segment}.xml 对应。
     */
    public function test_chunk_sitemap_returns_file_when_present(): void
    {
        $path = SiteStaticSitemapBuilder::chunkOutputPath('posts-1');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "<?xml version=\"1.0\" encoding=\"UTF-8\"?><urlset></urlset>\n");

        $response = $this->get('/sitemaps/posts-1.xml');

        $response->assertOk();
        @unlink($path);
    }

    /**
     * 非法分片名片段不匹配路由，应 404。
     */
    public function test_invalid_chunk_segment_returns_404(): void
    {
        $this->get('/sitemaps/../../etc.xml')->assertNotFound();
    }
}
