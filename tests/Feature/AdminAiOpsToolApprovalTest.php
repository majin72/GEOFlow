<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Models\AdminAiOpsToolApproval;
use App\Models\AiModel;
use App\Models\SiteSetting;
use App\Services\Admin\AiOps\AdminAiOpsChatService;
use App\Support\AdminWeb;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAiOpsToolApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_approve_is_idempotent_and_does_not_call_execute_twice(): void
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
            'tool_name' => 'AdminOpsAdminActionTool',
            'arguments_json' => json_encode([
                'kind' => 'write',
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
            ->assertJsonPath('executed_this_request', true);

        $firstOut = (string) AdminAiOpsToolApproval::query()->find($approvalId)?->executed_output;
        $this->assertNotSame('', $firstOut);

        $this->actingAs($admin, 'admin')
            ->postJson($approveUrl)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('already_executed', true)
            ->assertJsonPath('executed_this_request', false);

        $secondOut = (string) AdminAiOpsToolApproval::query()->find($approvalId)?->executed_output;
        $this->assertSame($firstOut, $secondOut);
    }

    public function test_approve_executes_site_patch_basics_from_stored_arguments(): void
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
            ->assertJsonPath('executed_this_request', true);

        $this->assertSame(
            'AfterAiOpsApproval',
            SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value')
        );
        $out = (string) AdminAiOpsToolApproval::query()->find($approvalId)?->executed_output;
        $this->assertStringContainsString('"ok"', $out);
    }

    public function test_approve_site_patch_with_site_title_alias_writes_site_name(): void
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
            ->assertJsonPath('executed_this_request', true);

        $this->assertSame(
            '床车旅行记',
            SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value')
        );
    }

    public function test_reject_then_resume_stream_completes_run_with_summary(): void
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
            'tool_name' => 'AdminOpsAdminActionTool',
            'arguments_json' => json_encode([
                'kind' => 'write',
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
            ->assertJsonPath('ok', true);

        $resumeUrl = (string) $reject->json('reject_resume_stream_url');
        $this->assertNotSame('', $resumeUrl);

        $this->partialMock(AdminAiOpsChatService::class, function ($mock): void {
            $mock->shouldReceive('streamAssistantResumeAfterReject')
                ->once()
                ->andReturn('已尊重拒绝并完成收尾说明。');
        });

        $response = $this->actingAs($admin, 'admin')
            ->get($resumeUrl);
        $response->assertOk();
        $response->streamedContent();

        $this->assertDatabaseHas('admin_ai_ops_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'result_summary' => "partial\n\n已尊重拒绝并完成收尾说明。",
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
