<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Models\AdminAiOpsToolApproval;
use App\Services\Admin\AiOps\AdminAiOpsStreamContext;
use App\Services\Admin\AiOps\AdminAiOpsToolApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 同轮多写工具：逐条审批队列（对齐 claw-code 顺序处理，不取消 sibling）。
 */
class AdminAiOpsToolApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 校验 approve 后若仍有 pending，返回 next_approval 且不续流。
     */
    public function test_approve_returns_next_approval_when_queue_remain(): void
    {
        [$run, $admin] = $this->seedRunWithAdmin();
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();

        AdminAiOpsToolApproval::query()->create([
            'id' => $firstId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsTasksTool',
            'tool_call_id' => 'tc-1',
            'arguments_json' => '{"op":"task_update","payload":{"task_id":1,"payload":{"ai_model_id":4}}}',
            'args_fingerprint' => hash('sha256', 'a'),
            'risk_label' => 'tasks:update',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);
        AdminAiOpsToolApproval::query()->create([
            'id' => $secondId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsTasksTool',
            'tool_call_id' => 'tc-2',
            'arguments_json' => '{"op":"task_update","payload":{"task_id":2,"payload":{"ai_model_id":4}}}',
            'args_fingerprint' => hash('sha256', 'b'),
            'risk_label' => 'tasks:update',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        app()->instance(
            AdminAiOpsStreamContext::class,
            AdminAiOpsStreamContext::forRun((int) $run->id, (int) $admin->id, []),
        );

        /** @var AdminAiOpsToolApprovalService $svc */
        $svc = app(AdminAiOpsToolApprovalService::class);
        $first = AdminAiOpsToolApproval::query()->findOrFail($firstId);

        $out = $svc->approveAndPrepareResume($first, (int) $admin->id, (int) $run->id);

        $this->assertNull($out['resume_stream_url']);
        $this->assertIsArray($out['next_approval']);
        $this->assertSame($secondId, $out['next_approval']['id']);
        $this->assertSame(1, $out['queue_remaining']);
        $this->assertSame('awaiting_confirmation', AdminAiOpsRun::query()->find($run->id)?->status);
    }

    /**
     * 对已 executed 的审批重复 POST 只会返回 next_approval，不应再次执行。
     */
    public function test_reapprove_executed_returns_next_without_reexecute(): void
    {
        [$run, $admin] = $this->seedRunWithAdmin();
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();

        AdminAiOpsToolApproval::query()->create([
            'id' => $firstId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsTasksTool',
            'tool_call_id' => 'tc-1',
            'arguments_json' => '{"op":"task_update","payload":{"task_id":1,"payload":{"ai_model_id":4}}}',
            'args_fingerprint' => hash('sha256', 'a'),
            'risk_label' => 'tasks:update',
            'status' => 'executed',
            'executed_output' => '{"ok":true}',
            'decided_at' => now(),
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinutes(2),
        ]);
        AdminAiOpsToolApproval::query()->create([
            'id' => $secondId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsTasksTool',
            'tool_call_id' => 'tc-2',
            'arguments_json' => '{"op":"task_update","payload":{"task_id":2,"payload":{"ai_model_id":4}}}',
            'args_fingerprint' => hash('sha256', 'b'),
            'risk_label' => 'tasks:update',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinute(),
        ]);

        /** @var AdminAiOpsToolApprovalService $svc */
        $svc = app(AdminAiOpsToolApprovalService::class);
        $first = AdminAiOpsToolApproval::query()->findOrFail($firstId);

        $out = $svc->approveAndPrepareResume($first, (int) $admin->id, (int) $run->id);

        $this->assertNull($out['resume_stream_url']);
        $this->assertTrue($out['already_executed']);
        $this->assertFalse($out['executed_this_request']);
        $this->assertSame($secondId, $out['next_approval']['id'] ?? null);
        $this->assertSame('pending', AdminAiOpsToolApproval::query()->find($secondId)?->status);
    }

    /**
     * @return array{0: AdminAiOpsRun, 1: Admin}
     */
    private function seedRunWithAdmin(): array
    {
        $admin = Admin::query()->create([
            'username' => 'queue_admin',
            'password' => 'secret',
            'email' => 'queue@example.com',
            'display_name' => 'Queue Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => 'test',
        ]);
        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'status' => 'awaiting_confirmation',
            'input_text' => '改模型',
        ]);

        return [$run, $admin];
    }
}
