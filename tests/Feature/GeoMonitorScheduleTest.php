<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorRun;
use App\Models\GeoMonitorSchedule;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeoMonitorScheduleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 保存每日计划应计算下次运行时间。
     */
    public function test_upsert_daily_schedule_sets_next_run_at(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
            'geoflow.geo_monitor.lock_cache_store' => 'array',
        ]);

        $project = $this->createProject();
        $service = app(GeoMonitorScheduleService::class);

        $schedule = $service->upsertForProject($project, [
            'frequency' => 'daily',
            'run_time' => '09:30',
            'timezone' => 'Asia/Shanghai',
            'is_enabled' => '1',
        ]);

        $this->assertTrue($schedule->is_enabled);
        $this->assertSame('daily', $schedule->frequency);
        $this->assertNotNull($schedule->next_run_at);
    }

    /**
     * 后台可保存项目监测计划。
     */
    public function test_admin_can_save_project_schedule(): void
    {
        $project = $this->createProject();

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.geo-monitoring.schedules.store', ['projectId' => $project->id]), [
                'frequency' => 'weekly',
                'run_time' => '10:00',
                'timezone' => 'Asia/Shanghai',
                'weekday' => '3',
                'is_enabled' => '1',
                'platform_scope' => ['deepseek'],
            ])
            ->assertRedirect(route('admin.geo-monitoring.project', ['projectId' => $project->id]));

        $schedule = GeoMonitorSchedule::query()->where('project_id', $project->id)->first();
        $this->assertNotNull($schedule);
        $this->assertSame('weekly', $schedule->frequency);
        $this->assertSame(['deepseek'], $schedule->platform_scope);
    }

    /**
     * 到期计划应创建批次且同一窗口不重复触发。
     */
    public function test_due_schedule_dispatches_once_per_window(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
            'geoflow.geo_monitor.lock_cache_store' => 'array',
        ]);

        Queue::fake();

        $project = $this->createProject();
        GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'q1',
            'prompt_text' => '测试问题',
            'is_enabled' => true,
        ]);

        $schedule = GeoMonitorSchedule::query()->create([
            'project_id' => $project->id,
            'frequency' => 'daily',
            'timezone' => 'Asia/Shanghai',
            'run_time' => '09:00',
            'is_enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        $service = app(GeoMonitorScheduleService::class);
        $first = $service->dispatchSchedule($schedule);
        $second = $service->dispatchSchedule($schedule->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, GeoMonitorRun::query()->where('project_id', $project->id)->count());
    }

    /**
     * 关闭计划后调度命令不再派发。
     */
    public function test_disabled_schedule_is_not_dispatched_by_command(): void
    {
        config([
            'geoflow.geo_monitor.enabled' => true,
            'geoflow.geo_monitor.sidecar_url' => 'http://sidecar.test',
            'geoflow.geo_monitor.lock_cache_store' => 'array',
        ]);

        Queue::fake();

        $project = $this->createProject();
        GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'q1',
            'prompt_text' => '测试问题',
            'is_enabled' => true,
        ]);

        GeoMonitorSchedule::query()->create([
            'project_id' => $project->id,
            'frequency' => 'daily',
            'timezone' => 'Asia/Shanghai',
            'run_time' => '09:00',
            'is_enabled' => false,
            'next_run_at' => now()->subMinute(),
        ]);

        Artisan::call('geoflow:geo-monitor-schedule');

        $this->assertSame(0, GeoMonitorRun::query()->where('project_id', $project->id)->count());
    }

    private function createProject(): GeoMonitorProject
    {
        return GeoMonitorProject::query()->create([
            'name' => '计划测试',
            'slug' => 'schedule-test',
            'brand_name' => '测试品牌',
            'primary_domain' => 'example.com',
            'status' => 'active',
        ]);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'geo_schedule_admin',
            'password' => bcrypt('secret'),
            'role' => 'super_admin',
        ]);
    }
}
