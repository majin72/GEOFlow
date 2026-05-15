<?php

declare(strict_types=1);

namespace App\Services\Admin\AdminOps\AdminAction;

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MaterialsController;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 仪表盘与素材总览只读：对齐 {@see DashboardController} 与 {@see MaterialsController} 核心计数。
 */
final class AdminOpsMirrorDashboardHandler
{
    /**
     * 返回仪表盘主统计块（与后台首页同源查询，失败时回退为 0）。
     *
     * @return array<string, int|float>
     */
    public function dashboardSummary(): array
    {
        $defaults = [
            'total_articles' => 0,
            'published_articles' => 0,
            'draft_articles' => 0,
            'ai_generated_articles' => 0,
            'total_tasks' => 0,
            'active_tasks' => 0,
            'completed_tasks' => 0,
            'running_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'total_keywords' => 0,
            'total_titles' => 0,
            'total_images' => 0,
            'total_categories' => 0,
            'active_ai_models' => 0,
            'total_prompts' => 0,
            'pending_review' => 0,
            'approved_articles' => 0,
            'total_views' => 0,
            'total_likes' => 0,
        ];

        try {
            $jobStatusCounts = TaskRun::query()
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all();
            $defaults['running_jobs'] = (int) ($jobStatusCounts['running'] ?? 0);
            $defaults['pending_jobs'] = (int) ($jobStatusCounts['pending'] ?? 0);
            $defaults['failed_jobs'] = (int) ($jobStatusCounts['failed'] ?? 0);
            $defaults['completed_tasks'] = (int) ($jobStatusCounts['completed'] ?? 0);

            $defaults['total_articles'] = (int) Article::query()->whereNull('deleted_at')->count();
            $defaults['published_articles'] = (int) Article::query()->where('status', 'published')->whereNull('deleted_at')->count();
            $defaults['draft_articles'] = (int) Article::query()->where('status', 'draft')->whereNull('deleted_at')->count();
            $defaults['ai_generated_articles'] = (int) Article::query()->where('is_ai_generated', 1)->whereNull('deleted_at')->count();
            $defaults['pending_review'] = (int) Article::query()->where('review_status', 'pending')->whereNull('deleted_at')->count();
            $defaults['approved_articles'] = (int) Article::query()->where('review_status', 'approved')->whereNull('deleted_at')->count();
            $defaults['total_views'] = (int) (Article::query()->whereNull('deleted_at')->sum('view_count') ?? 0);
            if (Schema::hasColumn('articles', 'like_count')) {
                $defaults['total_likes'] = (int) (Article::query()->whereNull('deleted_at')->sum('like_count') ?? 0);
            }

            $defaults['total_tasks'] = (int) Task::query()->count();
            $defaults['active_tasks'] = (int) Task::query()->where('status', 'active')->count();
            $defaults['total_keywords'] = (int) Keyword::query()->count();
            $defaults['total_titles'] = (int) Title::query()->count();
            $defaults['total_images'] = (int) Image::query()->count();
            $defaults['total_categories'] = (int) Category::query()->count();
            $defaults['active_ai_models'] = (int) AiModel::query()->where('status', 'active')->count();
            $defaults['total_prompts'] = (int) Prompt::query()->count();
        } catch (\Throwable) {
            return $defaults;
        }

        return $defaults;
    }

    /**
     * 今日与近 7 日增量统计。
     *
     * @return array{today: array<string, int>, week: array<string, int>}
     */
    public function dashboardTodayWeek(): array
    {
        $today = ['today_articles' => 0, 'today_tasks' => 0, 'today_views' => 0];
        $week = ['week_articles' => 0, 'week_tasks' => 0];
        try {
            $d = Carbon::today();
            $today['today_articles'] = (int) Article::query()->whereNull('deleted_at')->whereDate('created_at', $d)->count();
            $today['today_tasks'] = (int) Task::query()->whereDate('created_at', $d)->count();
            if (Schema::hasTable('view_logs')) {
                $today['today_views'] = (int) DB::table('view_logs')->whereDate('created_at', $d)->count();
            }
            $since = now()->subDays(7);
            $week['week_articles'] = (int) Article::query()->whereNull('deleted_at')->where('created_at', '>=', $since)->count();
            $week['week_tasks'] = (int) Task::query()->where('created_at', '>=', $since)->count();
        } catch (\Throwable) {
        }

        return ['today' => $today, 'week' => $week];
    }

    /**
     * 分类文章分布 Top10（名称与篇数）。
     *
     * @return list<array{name: string, count: int}>
     */
    public function dashboardCategoryDistributionTop10(): array
    {
        try {
            return DB::table('categories as c')
                ->leftJoin('articles as a', function ($join): void {
                    $join->on('c.id', '=', 'a.category_id')
                        ->whereNull('a.deleted_at');
                })
                ->select('c.name', DB::raw('COUNT(a.id) as count'))
                ->groupBy('c.id', 'c.name')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->map(fn ($r) => ['name' => (string) $r->name, 'count' => (int) $r->count])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 素材管理首页统计（与 {@see MaterialsController} 一致）。
     *
     * @return array<string, int>
     */
    public function materialsStats(): array
    {
        return [
            'keyword_libraries' => (int) KeywordLibrary::query()->count(),
            'total_keywords' => (int) Keyword::query()->count(),
            'title_libraries' => (int) TitleLibrary::query()->count(),
            'total_titles' => (int) Title::query()->count(),
            'image_libraries' => (int) ImageLibrary::query()->count(),
            'total_images' => (int) Image::query()->count(),
            'knowledge_bases' => (int) KnowledgeBase::query()->count(),
            'authors' => (int) Author::query()->count(),
        ];
    }

    /**
     * Legacy AI 配置器占位说明（路由仍存在，无独立数据表）。
     *
     * @return array<string, string>
     */
    public function legacyAiConfiguratorInfo(): array
    {
        return [
            'route' => 'admin.ai.configurator',
            'note' => '该入口为历史占位页，无结构化配置数据；请使用 ai_models / ai_prompts 等工具。',
        ];
    }
}
