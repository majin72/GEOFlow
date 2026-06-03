<?php

namespace App\View\Composers;

use App\Models\Category;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 为前台 Blade 布局注入站点名称、分类导航等公共变量。
 */
final class SiteLayoutComposer
{
    public function compose(View $view): void
    {
        $map = SiteSettingsBag::all();
        $siteName = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteLogo = (string) ($map['site_logo'] ?? '');
        $siteFavicon = (string) ($map['site_favicon'] ?? '');
        $copyright = (string) ($map['copyright_info'] ?? '');
        $analyticsCode = (string) ($map['analytics_code'] ?? '');

        // 备案信息：ICP 默认链接到工信部；公安备 record code 填了才拼出 mps 查询链接，否则按纯文本展示。
        $icpBeian = trim((string) ($map['site_icp_beian'] ?? ''));
        $policeBeian = trim((string) ($map['site_police_beian'] ?? ''));
        $policeBeianCode = trim((string) ($map['site_police_beian_code'] ?? ''));
        $icpBeianUrl = $icpBeian !== '' ? 'https://beian.miit.gov.cn/' : '';
        $policeBeianUrl = ($policeBeian !== '' && $policeBeianCode !== '')
            ? 'https://beian.mps.gov.cn/#/query/webSearch?code='.$policeBeianCode
            : '';

        $categories = collect();
        if (Schema::hasTable('categories')) {
            $categories = Category::query()
                ->whereHas('articles', function ($q): void {
                    $q->published();
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->withCount([
                    'articles as published_count' => function ($q): void {
                        $q->published();
                    },
                ])
                ->get();
        }

        $view->with([
            'siteName' => $siteName,
            'siteLogo' => $siteLogo,
            'siteFavicon' => $siteFavicon,
            'footerCopyright' => $copyright,
            'siteIcpBeian' => $icpBeian,
            'siteIcpBeianUrl' => $icpBeianUrl,
            'sitePoliceBeian' => $policeBeian,
            'sitePoliceBeianUrl' => $policeBeianUrl,
            'headAnalyticsCode' => $analyticsCode,
            'navCategories' => $categories,
        ]);
    }
}
