<?php

namespace Tests\Unit;

use App\Services\Admin\AiOps\AdminAiOpsAssistantTimelineRecorder;
use Tests\TestCase;

class AdminAiOpsAssistantTimelineRecorderApprovalTest extends TestCase
{
    public function test_awaiting_approval_and_rejected_phases(): void
    {
        $recorder = new AdminAiOpsAssistantTimelineRecorder;
        $recorder->recordToolCalling('tc-write', 'AdminOpsSiteSetActiveThemeTool', '{"theme_id":"default"}');
        $recorder->markToolAwaitingApprovalByCallId('tc-write', 'pending preview');

        $timeline = $recorder->toArray();
        $this->assertSame('awaiting_approval', $timeline['segments'][0]['tools'][0]['phase']);

        $recorder->markToolRejectedByCallId('tc-write', '用户拒绝');
        $timeline = $recorder->toArray();
        $this->assertSame('rejected', $timeline['segments'][0]['tools'][0]['phase']);
        $this->assertFalse($timeline['segments'][0]['tools'][0]['successful']);

        $recorder2 = new AdminAiOpsAssistantTimelineRecorder;
        $recorder2->recordToolCalling('tc-patch', 'AdminOpsSitePatchBasicsTool', '{"site_name":"x"}');
        $recorder2->markCallingToolsAwaitingApproval('pending');
        $recorder2->recordToolDone('tc-patch', 'AdminOpsSitePatchBasicsTool', true, '', '{"ok":true}');
        $timeline2 = $recorder2->toArray();
        $this->assertCount(1, $timeline2['segments'][0]['tools']);
        $this->assertSame('done', $timeline2['segments'][0]['tools'][0]['phase']);
        $this->assertTrue($timeline2['segments'][0]['tools'][0]['successful']);
    }

    public function test_only_target_tool_becomes_awaiting_approval_when_multiple_calling(): void
    {
        $recorder = new AdminAiOpsAssistantTimelineRecorder;
        $recorder->recordToolCalling('tc-a', 'AdminOpsTasksTool', '{"task_id":12}');
        $recorder->recordToolCalling('tc-b', 'AdminOpsTasksTool', '{"task_id":13}');
        $recorder->markToolAwaitingApprovalByCallId('tc-b', 'pending');

        $timeline = $recorder->toArray();
        $tools = $timeline['segments'][0]['tools'];

        $this->assertSame('calling', $tools[0]['phase']);
        $this->assertSame('awaiting_approval', $tools[1]['phase']);
    }

    public function test_mark_sibling_pending_tools_rejected_is_noop_in_queue_mode(): void
    {
        $recorder = new AdminAiOpsAssistantTimelineRecorder;
        $recorder->recordToolCalling('tc-a', 'AdminOpsTasksTool', '{"task_id":12}');
        $recorder->recordToolCalling('tc-b', 'AdminOpsTasksTool', '{"task_id":13}');
        $recorder->markCallingToolsAwaitingApproval('pending');

        $affected = $recorder->markSiblingPendingToolsRejected('tc-b', '用户拒绝');
        $timeline = $recorder->toArray();
        $tools = $timeline['segments'][0]['tools'];

        $this->assertSame('awaiting_approval', $tools[0]['phase']);
        $this->assertSame('awaiting_approval', $tools[1]['phase']);
        $this->assertSame([], $affected);
    }
}
