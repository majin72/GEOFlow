<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Services\Admin\AiOps\AdminAiOpsChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Tests\TestCase;

class AdminAiOpsChatServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 校验 priorMessagesBeforeRun 将已完成轮次展开为 user/assistant 交替消息，且不包含当前 run。
     */
    public function test_prior_messages_before_run_maps_completed_rounds_to_sdk_messages(): void
    {
        $admin = Admin::query()->create([
            'username' => 'prior_msg_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => 'prior-msg-admin@example.com',
            'display_name' => 'Prior Msg Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => 'ctx',
        ]);

        AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'status' => 'completed',
            'input_text' => '第一轮问',
            'result_summary' => '第一轮答',
        ]);

        AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'status' => 'completed',
            'input_text' => '第二轮问',
            'result_summary' => '第二轮答',
        ]);

        $current = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'status' => 'queued',
            'input_text' => '本轮不应出现在 history',
        ]);

        /** @var AdminAiOpsChatService $service */
        $service = app(AdminAiOpsChatService::class);
        $messages = $service->priorMessagesBeforeRun((int) $session->id, (int) $current->id);

        $this->assertCount(4, $messages);
        $this->assertSame(MessageRole::User, $messages[0]->role);
        $this->assertSame('第一轮问', $messages[0]->content);
        $this->assertSame(MessageRole::Assistant, $messages[1]->role);
        $this->assertSame('第一轮答', $messages[1]->content);
        $this->assertSame(MessageRole::User, $messages[2]->role);
        $this->assertSame('第二轮问', $messages[2]->content);
        $this->assertSame(MessageRole::Assistant, $messages[3]->role);
        $this->assertSame('第二轮答', $messages[3]->content);
    }

    /**
     * 校验已有 llm_messages 时优先回放标准 transcript，而不是自然语言 summary fallback。
     */
    public function test_prior_messages_before_run_replays_standard_tool_transcript(): void
    {
        $admin = Admin::query()->create([
            'username' => 'prior_tool_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => 'prior-tool-admin@example.com',
            'display_name' => 'Prior Tool Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => 'tool ctx',
        ]);

        AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'status' => 'completed',
            'input_text' => '旧 user 不应使用',
            'result_summary' => '旧 summary 不应使用',
            'plan_stream_snapshot' => [
                'llm_messages' => [
                    ['type' => 'user', 'content' => '原生用户输入'],
                    [
                        'type' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'tc-history',
                            'name' => 'AdminOpsTasksTool',
                            'arguments' => ['task_id' => 9],
                            'result_id' => null,
                            'reasoning_id' => null,
                            'reasoning_summary' => null,
                        ]],
                    ],
                    [
                        'type' => 'tool_result',
                        'tool_results' => [[
                            'id' => 'tc-history',
                            'name' => 'AdminOpsTasksTool',
                            'arguments' => ['task_id' => 9],
                            'result' => '{"ok":true}',
                            'result_id' => null,
                        ]],
                    ],
                    ['type' => 'assistant', 'content' => '原生最终回复', 'tool_calls' => []],
                ],
            ],
        ]);

        $current = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'status' => 'queued',
            'input_text' => '当前轮',
        ]);

        /** @var AdminAiOpsChatService $service */
        $service = app(AdminAiOpsChatService::class);
        $messages = $service->priorMessagesBeforeRun((int) $session->id, (int) $current->id);

        $this->assertCount(4, $messages);
        $this->assertSame('原生用户输入', $messages[0]->content);
        $assistantToolCallMessage = $messages[1];
        $this->assertInstanceOf(AssistantMessage::class, $assistantToolCallMessage);
        $this->assertSame('tc-history', $assistantToolCallMessage->toolCalls->first()->id);
        $toolResultMessage = $messages[2];
        $this->assertInstanceOf(ToolResultMessage::class, $toolResultMessage);
        $this->assertSame('tc-history', $toolResultMessage->toolResults->first()->id);
        $this->assertSame('原生最终回复', $messages[3]->content);
    }

    /**
     * 校验 awaiting_confirmation 轮次会进入后续 run 的模型上下文（避免追问时失忆）。
     */
    public function test_prior_messages_before_run_includes_awaiting_confirmation_round(): void
    {
        $admin = Admin::query()->create([
            'username' => 'prior_await_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => 'prior-await-admin@example.com',
            'display_name' => 'Prior Await Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => 'await ctx',
        ]);

        AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'status' => 'awaiting_confirmation',
            'input_text' => '帮我把所有任务模型换成 deepseek',
            'plan_stream_snapshot' => [
                'partial_assistant_text' => '已提交 13 条任务更新，等待批准。',
            ],
        ]);

        $current = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'status' => 'queued',
            'input_text' => '执行了吗',
        ]);

        /** @var AdminAiOpsChatService $service */
        $service = app(AdminAiOpsChatService::class);
        $messages = $service->priorMessagesBeforeRun((int) $session->id, (int) $current->id);

        $this->assertCount(2, $messages);
        $this->assertSame(MessageRole::User, $messages[0]->role);
        $this->assertStringContainsString('deepseek', (string) $messages[0]->content);
        $this->assertSame(MessageRole::Assistant, $messages[1]->role);
        $this->assertStringContainsString('等待批准', (string) $messages[1]->content);
        $this->assertStringContainsString('批准执行', (string) $messages[1]->content);
    }
}
