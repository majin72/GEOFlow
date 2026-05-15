<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Services\Admin\AiOps\AdminAiOpsChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Messages\MessageRole;
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
}
