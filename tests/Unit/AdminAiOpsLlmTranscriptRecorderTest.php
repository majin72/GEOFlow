<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Admin\AiOps\AdminAiOpsLlmTranscriptCodec;
use App\Services\Admin\AiOps\AdminAiOpsLlmTranscriptRecorder;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as AiStreamToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as AiStreamToolResult;
use Tests\TestCase;

/**
 * 验证标准 transcript 保留 assistant.tool_calls 与 tool_result 的原生配对。
 */
class AdminAiOpsLlmTranscriptRecorderTest extends TestCase
{
    /**
     * 流事件会被记录为 user → assistant(tool_calls) → tool_result → assistant。
     */
    public function test_recorder_preserves_native_tool_call_transcript_shape(): void
    {
        $codec = new AdminAiOpsLlmTranscriptCodec;
        $recorder = new AdminAiOpsLlmTranscriptRecorder($codec);
        $recorder->start('请修改任务');

        $recorder->record(new TextDelta('e1', 'm1', '我先调用工具。', time()));
        $recorder->record(new AiStreamToolCall('e2', new ToolCall('tc-1', 'AdminOpsTasksTool', ['task_id' => 1]), time()));
        $recorder->record(new AiStreamToolResult('e3', new ToolResult('tc-1', 'AdminOpsTasksTool', ['task_id' => 1], '{"ok":true}'), true, null, time()));
        $recorder->record(new TextDelta('e4', 'm2', '已完成。', time()));

        $rows = $recorder->toArray();

        $this->assertSame('user', $rows[0]['type']);
        $this->assertSame('assistant', $rows[1]['type']);
        $this->assertSame('tc-1', $rows[1]['tool_calls'][0]['id']);
        $this->assertSame('tool_result', $rows[2]['type']);
        $this->assertSame('tc-1', $rows[2]['tool_results'][0]['id']);
        $this->assertSame('assistant', $rows[3]['type']);
        $this->assertSame($rows, $codec->toArray($codec->fromArray($rows)));
    }
}
