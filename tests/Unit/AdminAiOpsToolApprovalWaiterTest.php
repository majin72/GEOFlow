<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Models\AdminAiOpsToolApproval;
use App\Services\Admin\AiOps\AdminAiOpsToolApprovalWaiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 验证审批等待器在原始 tool call 内返回真实结果或标准工具错误。
 */
class AdminAiOpsToolApprovalWaiterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * approved 后等待器执行原始回调，并将输出落库为 executed。
     */
    public function test_waiter_executes_callback_for_approved_approval(): void
    {
        [$run, $admin] = $this->seedRunWithAdmin();
        $approval = $this->createApproval($run, $admin, 'approved');
        $called = 0;

        /** @var AdminAiOpsToolApprovalWaiter $waiter */
        $waiter = app(AdminAiOpsToolApprovalWaiter::class);
        $output = $waiter->waitForDecisionAndRun((string) $approval->id, function () use (&$called): array {
            $called++;

            return ['ok' => true, 'value' => 42];
        });

        $this->assertSame(1, $called);
        $this->assertStringContainsString('"value":42', $output);
        $this->assertSame('executed', AdminAiOpsToolApproval::query()->find($approval->id)?->status);
        $this->assertSame($output, AdminAiOpsToolApproval::query()->find($approval->id)?->executed_output);
    }

    /**
     * rejected 后等待器不执行原始回调，直接返回标准 tool error JSON。
     */
    public function test_waiter_returns_standard_error_for_rejected_approval(): void
    {
        [$run, $admin] = $this->seedRunWithAdmin();
        $approval = $this->createApproval($run, $admin, 'rejected', '不要执行');
        $called = false;

        /** @var AdminAiOpsToolApprovalWaiter $waiter */
        $waiter = app(AdminAiOpsToolApprovalWaiter::class);
        $output = $waiter->waitForDecisionAndRun((string) $approval->id, function () use (&$called): array {
            $called = true;

            return ['ok' => true];
        });

        $decoded = json_decode($output, true);

        $this->assertFalse($called);
        $this->assertIsArray($decoded);
        $this->assertFalse((bool) $decoded['ok']);
        $this->assertSame('user_rejected', $decoded['error']);
        $this->assertSame('不要执行', $decoded['message']);
    }

    /**
     * @return array{0: AdminAiOpsRun, 1: Admin}
     */
    private function seedRunWithAdmin(): array
    {
        $admin = Admin::query()->create([
            'username' => 'waiter_admin',
            'password' => 'secret',
            'email' => 'waiter@example.com',
            'display_name' => 'Waiter Admin',
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
            'status' => 'processing',
            'input_text' => '改配置',
        ]);

        return [$run, $admin];
    }

    /**
     * 创建指定状态的审批行。
     */
    private function createApproval(AdminAiOpsRun $run, Admin $admin, string $status, ?string $reason = null): AdminAiOpsToolApproval
    {
        return AdminAiOpsToolApproval::query()->create([
            'id' => (string) Str::uuid(),
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsTasksTool',
            'tool_call_id' => 'tc-waiter',
            'arguments_json' => '{"op":"noop","payload":[]}',
            'args_fingerprint' => hash('sha256', $status),
            'risk_label' => 'tasks:update',
            'status' => $status,
            'rejection_reason' => $reason,
            'decided_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
    }
}
