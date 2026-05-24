<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Models\AdminAiOpsToolApproval;
use App\Models\AiModel;
use App\Models\SiteSetting;
use App\Support\AdminWeb;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAiOpsToolApprovalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * HTTP 批准只记录 approved 决定，重复批准不会执行工具或写入输出。
     */
    public function test_second_approve_is_idempotent_and_does_not_execute_tool(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = $this->createAdmin();
        $model = $this->createAiModel();
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => '审批',
        ]);

        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $model->id,
            'status' => 'awaiting_confirmation',
            'input_text' => 'x',
            'plan_stream_snapshot' => [
                'partial_assistant_text' => 'p',
            ],
        ]);

        $approvalId = (string) Str::uuid();
        AdminAiOpsToolApproval::query()->create([
            'id' => $approvalId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsTasksTool',
            'arguments_json' => json_encode([
                'op' => 'task_toggle',
                'payload' => ['task_id' => 0, 'current_status' => 'active'],
            ], JSON_THROW_ON_ERROR),
            'args_fingerprint' => hash('sha256', 'x'),
            'risk_label' => 'test',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $approveUrl = route('admin.ai-ops.runs.tool-approvals.approve', [
            'runId' => $run->id,
            'approvalId' => $approvalId,
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson($approveUrl)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('waiting_for_tool_result', true)
            ->assertJsonPath('executed_this_request', false);

        $firstOut = (string) AdminAiOpsToolApproval::query()->find($approvalId)?->executed_output;
        $this->assertSame('', $firstOut);
        $this->assertSame('approved', AdminAiOpsToolApproval::query()->find($approvalId)?->status);

        $this->actingAs($admin, 'admin')
            ->postJson($approveUrl)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('already_decided', true)
            ->assertJsonPath('executed_this_request', false);

        $secondOut = (string) AdminAiOpsToolApproval::query()->find($approvalId)?->executed_output;
        $this->assertSame($firstOut, $secondOut);
    }

    /**
     * HTTP 批准站点写操作不会直接改库，真实写入只能由原始 tool call 执行。
     */
    public function test_approve_site_patch_only_records_decision(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $base = AdminWeb::basePath();
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_name'],
            ['setting_value' => 'BeforeAiOpsPatch']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'admin_base_path'],
            ['setting_value' => $base]
        );

        $admin = $this->createAdmin();
        $model = $this->createAiModel();
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => '站点 patch 审批',
        ]);

        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $model->id,
            'status' => 'awaiting_confirmation',
            'input_text' => 'x',
            'plan_stream_snapshot' => [
                'partial_assistant_text' => 'p',
            ],
        ]);

        $approvalId = (string) Str::uuid();
        $args = ['patch' => ['site_name' => 'AfterAiOpsApproval']];
        $encoded = json_encode($args, JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', $encoded);

        AdminAiOpsToolApproval::query()->create([
            'id' => $approvalId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsSitePatchBasicsTool',
            'arguments_json' => $encoded,
            'args_fingerprint' => $fingerprint,
            'risk_label' => 'site_patch_basics:site_name',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $approveUrl = route('admin.ai-ops.runs.tool-approvals.approve', [
            'runId' => $run->id,
            'approvalId' => $approvalId,
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson($approveUrl)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('waiting_for_tool_result', true)
            ->assertJsonPath('executed_this_request', false);

        $this->assertSame(
            'BeforeAiOpsPatch',
            SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value')
        );
        $out = (string) AdminAiOpsToolApproval::query()->find($approvalId)?->executed_output;
        $this->assertSame('', $out);
    }

    /**
     * 别名参数的站点写操作同样不会在 approve HTTP 中执行。
     */
    public function test_approve_site_patch_title_alias_only_records_decision(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $base = AdminWeb::basePath();
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_name'],
            ['setting_value' => 'BeforeTitleAlias']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'admin_base_path'],
            ['setting_value' => $base]
        );

        $admin = $this->createAdmin();
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => 'site_title 别名',
        ]);

        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $this->createAiModel()->id,
            'status' => 'awaiting_confirmation',
            'input_text' => '改标题',
            'plan_stream_snapshot' => ['partial_assistant_text' => ''],
        ]);

        $approvalId = (string) Str::uuid();
        $args = ['patch' => ['site_title' => '床车旅行记']];
        $encoded = json_encode($args, JSON_THROW_ON_ERROR);

        AdminAiOpsToolApproval::query()->create([
            'id' => $approvalId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsSitePatchBasicsTool',
            'arguments_json' => $encoded,
            'args_fingerprint' => hash('sha256', $encoded),
            'risk_label' => 'site_patch_basics:site_title',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-ops.runs.tool-approvals.approve', [
                'runId' => $run->id,
                'approvalId' => $approvalId,
            ]))
            ->assertOk()
            ->assertJsonPath('waiting_for_tool_result', true)
            ->assertJsonPath('executed_this_request', false);

        $this->assertSame(
            'BeforeTitleAlias',
            SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value')
        );
    }

    /**
     * approve HTTP 不再签发 resume URL，后续由原 SSE 内的 Laravel AI tool loop 继续。
     */
    public function test_approve_returns_waiting_without_resume_stream_url(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $base = AdminWeb::basePath();
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_name'],
            ['setting_value' => 'BeforeResume']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'admin_base_path'],
            ['setting_value' => $base]
        );

        $admin = $this->createAdmin();
        $model = $this->createAiModel();
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => '批准续流',
        ]);

        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $model->id,
            'status' => 'awaiting_confirmation',
            'input_text' => '改站点名',
            'plan_stream_snapshot' => [
                'partial_assistant_text' => '我先说明一下。',
                'assistant_timeline' => [
                    'completedRounds' => [],
                    'segments' => [
                        ['kind' => 'text', 'text' => '我先说明一下。'],
                        [
                            'kind' => 'tools',
                            'tools' => [[
                                'toolCallId' => 'tc-patch-1',
                                'name' => 'AdminOpsSitePatchBasicsTool',
                                'phase' => 'awaiting_approval',
                            ]],
                        ],
                    ],
                ],
            ],
        ]);

        $approvalId = (string) Str::uuid();
        AdminAiOpsToolApproval::query()->create([
            'id' => $approvalId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsSitePatchBasicsTool',
            'tool_call_id' => 'tc-patch-1',
            'arguments_json' => json_encode(['patch' => ['site_name' => 'AfterResume']], JSON_THROW_ON_ERROR),
            'args_fingerprint' => hash('sha256', 'z'),
            'risk_label' => 'test',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $approve = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-ops.runs.tool-approvals.approve', [
                'runId' => $run->id,
                'approvalId' => $approvalId,
            ]))
            ->assertOk()
            ->assertJsonPath('waiting_for_tool_result', true)
            ->assertJsonPath('executed_this_request', false);

        $this->assertSame('tc-patch-1', (string) AdminAiOpsToolApproval::query()->find($approvalId)?->tool_call_id);

        $resumeUrl = (string) $approve->json('resume_stream_url');
        $this->assertSame('', $resumeUrl);
        $this->assertDatabaseHas('admin_ai_ops_tool_approvals', [
            'id' => $approvalId,
            'status' => 'approved',
        ]);
    }

    /**
     * reject HTTP 只记录 rejected 决定并等待原 SSE tool call 返回标准错误。
     */
    public function test_reject_returns_waiting_without_resume_stream_url(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = $this->createAdmin();
        $model = $this->createAiModel();
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => '拒',
        ]);

        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $model->id,
            'status' => 'awaiting_confirmation',
            'input_text' => 'x',
            'plan_stream_snapshot' => [
                'partial_assistant_text' => 'partial',
            ],
        ]);

        $approvalId = (string) Str::uuid();
        AdminAiOpsToolApproval::query()->create([
            'id' => $approvalId,
            'run_id' => (int) $run->id,
            'admin_id' => (int) $admin->id,
            'tool_name' => 'AdminOpsTasksTool',
            'arguments_json' => json_encode([
                'op' => 'noop',
                'payload' => [],
            ], JSON_THROW_ON_ERROR),
            'args_fingerprint' => hash('sha256', 'y'),
            'risk_label' => 'test',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $rejectUrl = route('admin.ai-ops.runs.tool-approvals.reject', [
            'runId' => $run->id,
            'approvalId' => $approvalId,
        ]);

        $reject = $this->actingAs($admin, 'admin')
            ->postJson($rejectUrl, ['reason' => 'user said no'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('waiting_for_tool_result', true);

        $resumeUrl = (string) $reject->json('reject_resume_stream_url');
        $this->assertSame('', $resumeUrl);

        $this->assertDatabaseHas('admin_ai_ops_runs', [
            'id' => $run->id,
            'status' => 'processing',
        ]);
        $this->assertDatabaseHas('admin_ai_ops_tool_approvals', [
            'id' => $approvalId,
            'status' => 'rejected',
            'rejection_reason' => 'user said no',
        ]);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'tool_appr_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => 'tool-appr-admin@example.com',
            'display_name' => 'Tool Appr Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createAiModel(): AiModel
    {
        return AiModel::query()->create([
            'name' => 'Tool Appr Model',
            'version' => 'test',
            'api_key' => 'test-key',
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://example.com/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'status' => 'active',
        ]);
    }
}
