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
        $recorder->markCallingToolsAwaitingApproval('pending preview');

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
}
