<?php

declare(strict_types=1);

namespace App\Support\Site;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * 生成前台可抓取的静态 sitemap.xml（urlset 0.9），供搜索引擎与 CDN 直出。
 */
final class SiteStaticSitemapBuilder
{
    /**
     * 组装完整 XML 文档（含 urlset 与若干 url 节点）。
     */
    public function buildXml(): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        $now = Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');
        $lines = array_merge($lines, $this->urlLine(route('site.home'), $now, 'daily', '1.0'));
        $lines = array_merge($lines, $this->urlLine(route('site.archive'), $now, 'weekly', '0.5'));

        if (Schema::hasTable('categories')) {
            $categories = Category::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['slug']);
            foreach ($categories as $row) {
                $slug = trim((string) ($row->slug ?? ''));
                if ($slug === '') {
                    continue;
                }
                $lines = array_merge($lines, $this->urlLine(route('site.category', $slug), $now, 'weekly', '0.6'));
            }
        }

        $archiveMonths = [];
        if (Schema::hasTable('articles')) {
            Article::query()
                ->published()
                ->whereNotNull('published_at')
                ->orderByDesc('published_at')
                ->select(['id', 'slug', 'published_at', 'updated_at'])
                ->chunkById(500, function ($rows) use (&$lines, &$archiveMonths): void {
                    foreach ($rows as $article) {
                        if (! $article instanceof Article) {
                            continue;
                        }
                        $slug = trim((string) $article->slug);
                        if ($slug === '') {
                            continue;
                        }
                        $last = $article->updated_at instanceof Carbon
                            ? $article->updated_at->clone()->utc()->format('Y-m-d\TH:i:s\Z')
                            : ($article->published_at instanceof Carbon
                                ? $article->published_at->clone()->utc()->format('Y-m-d\TH:i:s\Z')
                                : Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'));
                        $lines = array_merge($lines, $this->urlLine(route('site.article', $slug), $last, 'weekly', '0.8'));

                        if ($article->published_at instanceof Carbon) {
                            $y = $article->published_at->format('Y');
                            $m = $article->published_at->format('m');
                            $archiveMonths[$y.'-'.$m] = true;
                        }
                    }
                });
        }

        foreach (array_keys($archiveMonths) as $ym) {
            [$y, $m] = explode('-', $ym, 2) + ['', ''];
            if ($y === '' || $m === '') {
                continue;
            }
            $lines = array_merge($lines, $this->urlLine(route('site.archive.month', ['year' => $y, 'month' => $m]), $now, 'monthly', '0.4'));
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    /**
     * 将 XML 写入磁盘（UTF-8），默认 public/sitemap.xml。
     *
     * @return string 实际写入的绝对路径
     */
    public function writeToPath(string $absolutePath): string
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $xml = $this->buildXml();
        file_put_contents($absolutePath, $xml, LOCK_EX);

        return $absolutePath;
    }

    /**
     * @return list<string>
     */
    private function urlLine(string $loc, string $lastmodW3c, string $changefreq, string $priority): array
    {
        return [
            '  <url>',
            '    <loc>'.$this->escapeXml($loc).'</loc>',
            '    <lastmod>'.$this->escapeXml($lastmodW3c).'</lastmod>',
            '    <changefreq>'.$this->escapeXml($changefreq).'</changefreq>',
            '    <priority>'.$this->escapeXml($priority).'</priority>',
            '  </url>',
        ];
    }

    /**
     * 将字符串安全嵌入 XML 文本节点。
     */
    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
