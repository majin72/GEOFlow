<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Site\SiteStaticSitemapBuilder;
use Illuminate\Console\Command;

/**
 * 生成静态 sitemap.xml（默认 storage/app/public，与生产 Docker storage 卷共享）；生产环境由调度每日刷新。
 */
class GeoFlowGenerateStaticSitemapCommand extends Command
{
    protected $signature = 'geoflow:generate-static-sitemap {--path= : 输出绝对或相对路径，默认 storage/app/public/sitemap.xml}';

    protected $description = 'Write a static sitemap.xml for the public site (home, archive, categories, articles)';

    public function __construct(
        private readonly SiteStaticSitemapBuilder $builder,
    ) {
        parent::__construct();
    }

    /**
     * 执行生成并输出路径信息。
     */
    public function handle(): int
    {
        $raw = trim((string) $this->option('path'));
        $target = $raw !== '' ? $this->normalizePath($raw) : SiteStaticSitemapBuilder::defaultOutputPath();

        $written = $this->builder->writeToPath($target);
        $this->info('Sitemap written: '.$written);

        return self::SUCCESS;
    }

    /**
     * 将用户传入路径解析为绝对路径（相对路径按项目根解析）。
     */
    private function normalizePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }
}
