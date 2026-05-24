<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Models\AdminAiOpsToolApproval;
use App\Services\Admin\AiOps\AdminAiOpsRunService;
use App\Services\Admin\AiOps\AdminAiOpsStreamContext;
use App\Services\Admin\AiOps\AdminAiOpsToolApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 同轮多写工具：tool_call_id 对齐与 run payload 在终态下仍暴露 pending。
 */
class AdminAiOpsToolApprovalSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 校验 syncToolCallId 会修正 pending 行的 tool_call_id。
     */
    public function test_sync_tool_call_id_updates_pending_row(): void
    {
        [$run, $admin] = $this->seedRunWithAdmin();
        $approvalId = (string) Str::uuid();

        AdminAiOpsToolApproval::query()->create([
            'id' => $approvalId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsTasksTool',
            'tool_call_id' => 'wrong-id',
            'arguments_json' => '{"op":"task_update","payload":{"task_id":1,"payload":{"ai_model_id":4}}}',
            'args_fingerprint' => hash('sha256', 'x'),
            'risk_label' => 'tasks:update',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        /** @var AdminAiOpsToolApprovalService $svc */
        $svc = app(AdminAiOpsToolApprovalService::class);
        $svc->syncToolCallId($approvalId, 'tc-correct');

        $this->assertSame('tc-correct', AdminAiOpsToolApproval::query()->find($approvalId)?->tool_call_id);
    }

    /**
     * 批准后应将工具卡片持久化为 done，刷新后不回退为 awaiting_approval。
     */
    public function test_approve_persists_tool_done_on_timeline(): void
    {
        [$run, $admin] = $this->seedRunWithAdmin();
        $approvalId = (string) Str::uuid();

        $run->forceFill([
            'plan_stream_snapshot' => [
                'assistant_timeline' => [
                    'completedRounds' => [],
                    'segments' => [[
                        'kind' => 'tools',
                        'tools' => [[
                            'toolCallId' => 'tc-1',
                            'name' => 'AdminOpsTasksTool',
                            'phase' => 'awaiting_approval',
                            'successful' => false,
                            'argumentsPreview' => '{"task_id":1}',
                        ]],
                    ]],
                ],
            ],
        ])->save();

        AdminAiOpsToolApproval::query()->create([
            'id' => $approvalId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsTasksTool',
            'tool_call_id' => 'tc-1',
            'arguments_json' => '{"op":"task_update","payload":{"task_id":1,"payload":{"ai_model_id":4}}}',
            'args_fingerprint' => hash('sha256', 'z'),
            'risk_label' => 'tasks:update',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        app()->instance(
            AdminAiOpsStreamContext::class,
            AdminAiOpsStreamContext::forRun((int) $run->id, (int) $admin->id, is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : []),
        );

        /** @var AdminAiOpsToolApprovalService $svc */
        $svc = app(AdminAiOpsToolApprovalService::class);
        $approval = AdminAiOpsToolApproval::query()->findOrFail($approvalId);
        $svc->approveDecision($approval, (int) $admin->id, (int) $run->id);
        $svc->markToolCallExecuted($approval->fresh() ?? $approval, '{"ok":true}');

        $fresh = AdminAiOpsRun::query()->findOrFail($run->id);
        $timeline = is_array($fresh->plan_stream_snapshot) ? ($fresh->plan_stream_snapshot['assistant_timeline'] ?? []) : [];
        $tools = $timeline['segments'][0]['tools'] ?? [];

        $this->assertSame('done', $tools[0]['phase'] ?? null);
    }

    /**
     * run 已 completed 但仍有 pending 时，payload 仍应返回 approval_pending。
     */
    public function test_run_payload_exposes_pending_even_when_run_completed(): void
    {
        [$run, $admin] = $this->seedRunWithAdmin();
        $run->forceFill([
            'status' => 'completed',
            'result_summary' => 'done',
            'finished_at' => now(),
        ])->save();

        AdminAiOpsToolApproval::query()->create([
            'id' => (string) Str::uuid(),
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsTasksTool',
            'tool_call_id' => 'tc-1',
            'arguments_json' => '{"op":"task_update","payload":{"task_id":2,"payload":{"ai_model_id":4}}}',
            'args_fingerprint' => hash('sha256', 'y'),
            'risk_label' => 'tasks:update',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinute(),
        ]);

        /** @var AdminAiOpsRunService $runs */
        $runs = app(AdminAiOpsRunService::class);
        $payload = $runs->payload($run->fresh() ?? $run);

        $this->assertIsArray($payload['approval_pending']);
        $this->assertSame(1, $payload['approval_pending']['queue_remaining']);
        $this->assertSame('tc-1', $payload['approval_pending']['tool_call_id']);
    }

    /**
     * @return array{0: AdminAiOpsRun, 1: Admin}
     */
    private function seedRunWithAdmin(): array
    {
        $admin = Admin::query()->create([
            'username' => 'sync_admin',
            'password' => 'secret',
            'email' => 'sync@example.com',
            'display_name' => 'Sync Admin',
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
