<?php

namespace Tests\Unit;

use App\Services\Admin\AiOps\AdminAiOpsAssistantTimelineRecorder;
use Tests\TestCase;

class AdminAiOpsAssistantTimelineRecorderTest extends TestCase
{
    public function test_timeline_records_text_and_tool_segments_in_order(): void
    {
        $recorder = new AdminAiOpsAssistantTimelineRecorder;
        $recorder->applyDelta('先说明一下。');
        $recorder->recordToolCalling('tc-1', 'AdminOpsSiteInfoTool', '{"scope":"full"}');
        $recorder->recordToolDone('tc-1', 'AdminOpsSiteInfoTool', true, '', '{"ok":true}');
        $recorder->applyDelta("先说明一下。\n\n根据查询结果，站点运行正常。");

        $timeline = $recorder->toArray();

        $this->assertCount(3, $timeline['segments']);
        $this->assertSame('text', $timeline['segments'][0]['kind']);
        $this->assertSame('先说明一下。', $timeline['segments'][0]['text']);
        $this->assertSame('tools', $timeline['segments'][1]['kind']);
        $this->assertSame('AdminOpsSiteInfoTool', $timeline['segments'][1]['tools'][0]['name']);
        $this->assertSame('done', $timeline['segments'][1]['tools'][0]['phase']);
        $this->assertSame('text', $timeline['segments'][2]['kind']);
        $this->assertStringContainsString('站点运行正常', (string) $timeline['segments'][2]['text']);
    }

    public function test_stale_deltas_during_tool_calling_do_not_mutate_pre_tool_text(): void
    {
        $intro = '我先读取当前站点的栏目列表。';
        $partial = $intro."当前栏目如下：\n1. 床车入门\n2. 改装教程";
        $full = $partial."\n3. 旅行路线\n4. 驻车露营";

        $recorder = new AdminAiOpsAssistantTimelineRecorder;
        $recorder->applyDelta($intro);
        $recorder->recordToolCalling('tc-cat', 'AdminOpsListCategoriesTool', '{}');
        $recorder->applyDelta($partial);
        $recorder->recordToolDone('tc-cat', 'AdminOpsListCategoriesTool', true, '', '[{"name":"床车入门"}]');
        $recorder->applyDelta($full);

        $timeline = $recorder->toArray();

        $this->assertSame($intro, $timeline['segments'][0]['text']);
        $this->assertStringContainsString('3. 旅行路线', (string) $timeline['segments'][2]['text']);
        $this->assertStringNotContainsString($intro, (string) $timeline['segments'][2]['text']);
    }

    public function test_tools_follow_all_pre_tool_text_when_list_arrived_before_tool_event(): void
    {
        $beforeTool = "我先查询当前站点栏目列表。当前栏目如下：\n1. 床车入门\n2. 改装教程";

        $recorder = new AdminAiOpsAssistantTimelineRecorder;
        $recorder->applyDelta($beforeTool);
        $recorder->recordToolCalling('tc-cat', 'AdminOpsListCategoriesTool', '{}');

        $timeline = $recorder->toArray();

        $this->assertCount(2, $timeline['segments']);
        $this->assertSame($beforeTool, $timeline['segments'][0]['text']);
        $this->assertSame('tools', $timeline['segments'][1]['kind']);
        $this->assertSame('calling', $timeline['segments'][1]['tools'][0]['phase']);
    }

    public function test_timeline_round_trips_through_snapshot_array(): void
    {
        $recorder = new AdminAiOpsAssistantTimelineRecorder;
        $recorder->applyDelta('hello');
        $recorder->recordToolCalling('tc-2', 'TavilyWebSearchTool', '{"query":"test"}');
        $recorder->recordToolDone('tc-2', 'TavilyWebSearchTool', true, '', 'results');

        $snapshot = ['assistant_timeline' => $recorder->toArray()];
        $restored = AdminAiOpsAssistantTimelineRecorder::fromSnapshot($snapshot);

        $this->assertSame($recorder->toArray(), $restored->toArray());
    }

    public function test_multi_tool_rounds_do_not_create_completed_rounds_fragments(): void
    {
        $recorder = new AdminAiOpsAssistantTimelineRecorder;
        $recorder->applyDelta('好的，我先查一下当前站点的配置信息。');
        $recorder->recordToolCalling('tc-info', 'AdminOpsSiteInfoTool', '{}');
        $recorder->recordToolDone('tc-info', 'AdminOpsSiteInfoTool', true, '', '{"ok":true}');
        $recorder->applyDelta("好的，我先查一下当前站点的配置信息。当前站点名称为「旧名」。");
        $recorder->recordToolCalling('tc-patch', 'AdminOpsSitePatchBasicsTool', '{"site_name":"新名"}');
        $recorder->markCallingToolsAwaitingApproval('pending');
        $recorder->applyDelta('好的，根据工具返回结果，站点配置已经成功更新。');

        $timeline = $recorder->toArray();

        $this->assertSame([], $timeline['completedRounds']);
        $this->assertGreaterThanOrEqual(3, count($timeline['segments']));
        $plain = '';
        foreach ($timeline['segments'] as $segment) {
            if (($segment['kind'] ?? '') === 'text') {
                $plain .= (string) ($segment['text'] ?? '');
            }
        }
        $this->assertStringContainsString('已经成功更新', $plain);
        $this->assertStringNotContainsString("当前\n\n好的，", $plain);
    }

    public function test_legacy_waves_format_hydrates_to_segments(): void
    {
        $legacy = [
            'text' => "说明。\n\n结果",
            'waves' => [
                [
                    'end' => 3,
                    'tools' => [
                        ['toolCallId' => 'x', 'name' => 'T', 'phase' => 'done'],
                    ],
                ],
            ],
        ];

        $recorder = new AdminAiOpsAssistantTimelineRecorder;
        $recorder->hydrateFromArray($legacy);
        $timeline = $recorder->toArray();

        $this->assertArrayHasKey('segments', $timeline);
        $this->assertGreaterThanOrEqual(2, count($timeline['segments']));
    }
}
