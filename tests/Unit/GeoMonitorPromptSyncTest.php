<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorRun;
use App\Services\GeoFlow\GeoMonitoring\Admin\GeoMonitorPromptSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoMonitorPromptSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 多行文本应创建对应数量的启用问题。
     */
    public function test_sync_creates_prompts_from_lines(): void
    {
        $project = GeoMonitorProject::query()->create([
            'name' => '同步测试',
            'slug' => 'sync-test',
            'status' => 'active',
        ]);

        app(GeoMonitorPromptSync::class)->syncFromTextarea(
            $project,
            "北京租车推荐\n神州租车怎么样",
        );

        $this->assertSame(2, GeoMonitorPrompt::query()->where('project_id', $project->id)->where('is_enabled', true)->count());
        $this->assertTrue(GeoMonitorPrompt::query()
            ->where('project_id', $project->id)
            ->where('code', 'q_001')
            ->where('prompt_text', '北京租车推荐')
            ->exists());
    }

    /**
     * 修改中文问题正文应更新同一行 code，不应产生重复启用记录。
     */
    public function test_sync_updates_same_line_when_chinese_text_changes(): void
    {
        $project = GeoMonitorProject::query()->create([
            'name' => '编辑测试',
            'slug' => 'edit-test',
            'status' => 'active',
        ]);

        $sync = app(GeoMonitorPromptSync::class);
        $sync->syncFromTextarea($project, "北京租车推荐\n神州租车怎么样");
        $sync->syncFromTextarea($project, "上海租车推荐\n神州租车怎么样");

        $this->assertSame(2, GeoMonitorPrompt::query()->where('project_id', $project->id)->where('is_enabled', true)->count());
        $this->assertSame(
            '上海租车推荐',
            GeoMonitorPrompt::query()->where('project_id', $project->id)->where('code', 'q_001')->value('prompt_text'),
        );
    }

    /**
     * 已有观测的旧问题在表单删除后应停用而非继续参与运行。
     */
    public function test_sync_disables_stale_prompt_with_observations(): void
    {
        $project = GeoMonitorProject::query()->create([
            'name' => '历史测试',
            'slug' => 'history-test',
            'status' => 'active',
        ]);

        $platform = GeoMonitorPlatform::query()->where('code', 'deepseek')->firstOrFail();
        $sync = app(GeoMonitorPromptSync::class);
        $sync->syncFromTextarea($project, "问题一\n问题二");

        $legacy = GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'q_abc123legacy0001',
            'prompt_text' => '旧版 md5 问题',
            'intent' => 'generic',
            'is_enabled' => true,
        ]);

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'succeeded',
            'prompt_count' => 1,
            'observation_count' => 1,
            'success_count' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $legacy->id,
            'platform_id' => $platform->id,
            'prompt_text_snapshot' => $legacy->prompt_text,
            'status' => 'success',
        ]);

        $sync->syncFromTextarea($project, "问题一\n问题二");

        $legacy->refresh();

        $this->assertFalse($legacy->is_enabled);
        $this->assertSame(2, GeoMonitorPrompt::query()->where('project_id', $project->id)->where('is_enabled', true)->count());
    }

    /**
     * 行序 code 应按 q_001 格式生成。
     */
    public function test_prompt_code_from_index_is_stable(): void
    {
        $this->assertSame('q_001', GeoMonitorPromptSync::promptCodeFromIndex(0));
        $this->assertSame('q_010', GeoMonitorPromptSync::promptCodeFromIndex(9));
    }

    public function test_prompt_code_uses_md5_for_long_text(): void
    {
        $long = str_repeat('租', 60);

        $code = GeoMonitorPromptSync::promptCodeFromText($long);

        $this->assertSame('q_'.substr(md5($long), 0, 16), $code);
    }

    public function test_prompt_code_uses_slug_for_short_ascii_text(): void
    {
        $this->assertSame('beijing_car_rental', GeoMonitorPromptSync::promptCodeFromText('Beijing car rental'));
    }
}
