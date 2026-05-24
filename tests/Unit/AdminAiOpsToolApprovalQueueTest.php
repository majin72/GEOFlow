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
 * 同轮多写工具：HTTP 只记录当前审批决定，后续队列由原始 tool loop 顺序产生。
 */
class AdminAiOpsToolApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 校验 approve 后只将当前行标记 approved，不返回 next_approval 或续流地址。
     */
    public function test_approve_decision_does_not_issue_resume_or_next_approval(): void
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

        $out = $svc->approveDecision($first, (int) $admin->id, (int) $run->id);

        $this->assertTrue($out['waiting_for_tool_result']);
        $this->assertSame(1, $out['queue_remaining']);
        $this->assertSame('processing', AdminAiOpsRun::query()->find($run->id)?->status);
        $this->assertSame('approved', AdminAiOpsToolApproval::query()->find($firstId)?->status);
        $this->assertSame('pending', AdminAiOpsToolApproval::query()->find($secondId)?->status);
    }

    /**
     * 对已 approved 的审批重复 POST 不应改变队列或执行工具。
     */
    public function test_reapprove_approved_returns_idempotent_decision(): void
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
            'status' => 'approved',
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

        $out = $svc->approveDecision($first, (int) $admin->id, (int) $run->id);

        $this->assertTrue($out['already_decided']);
        $this->assertSame(1, $out['queue_remaining']);
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
