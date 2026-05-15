<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Models\AiModel;
use App\Services\Admin\AiOps\AdminAiOpsChatService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiOpsRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_ops_page_is_accessible(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-ops.index'))
            ->assertOk()
            ->assertSee('AI 运维');
    }

    public function test_admin_can_list_and_show_only_owned_sessions(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $admin = $this->createAdmin();
        $otherAdmin = $this->createAdmin();
        $ownedSession = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => '我的会话',
        ]);
        $otherSession = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $otherAdmin->id,
            'title' => '别人会话',
        ]);
        $this->createRunInSession($ownedSession, $admin, 'completed', $this->createAiModel());
        $this->createRunInSession($otherSession, $otherAdmin, 'completed', $this->createAiModel());

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-ops.sessions.index'))
            ->assertOk()
            ->assertJsonPath('items.0.id', $ownedSession->id)
            ->assertJsonMissing(['title' => '别人会话']);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-ops.sessions.show', ['sessionId' => $ownedSession->id]))
            ->assertOk()
            ->assertJsonPath('id', $ownedSession->id)
            ->assertJsonCount(1, 'runs');

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-ops.sessions.show', ['sessionId' => $otherSession->id]))
            ->assertNotFound();
    }

    public function test_post_chat_creates_queued_run(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $admin = $this->createAdmin();
        $model = $this->createAiModel();
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => '测试会话',
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-ops.chat'), [
                'session_id' => $session->id,
                'message' => '你好',
                'ai_model_id' => $model->id,
            ])
            ->assertCreated()
            ->assertJsonPath('run.status', 'queued')
            ->assertJsonPath('run.input_text', '你好');

        $this->assertDatabaseHas('admin_ai_ops_runs', [
            'session_id' => $session->id,
            'admin_id' => $admin->id,
            'ai_model_id' => $model->id,
            'status' => 'queued',
        ]);
    }

    public function test_post_chat_without_session_creates_session(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $admin = $this->createAdmin();
        $model = $this->createAiModel();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-ops.chat'), [
                'message' => '第一条',
                'ai_model_id' => $model->id,
            ])
            ->assertCreated()
            ->assertJsonPath('run.status', 'queued');

        $this->assertDatabaseHas('admin_ai_ops_sessions', ['admin_id' => $admin->id]);
    }

    public function test_stream_completes_queued_run(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $admin = $this->createAdmin();
        $model = $this->createAiModel();
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => '流式',
        ]);
        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $model->id,
            'status' => 'queued',
            'input_text' => 'hi',
        ]);

        $this->partialMock(AdminAiOpsChatService::class, function ($mock): void {
            $mock->shouldReceive('streamAssistantReply')
                ->once()
                ->andReturnUsing(function (string $currentUserMessage, array $priorConversationMessages, AiModel $model, callable $onTextAccumulated): string {
                    $onTextAccumulated('hel');
                    $onTextAccumulated('hello');

                    return 'hello';
                });
        });

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-ops.runs.stream', ['runId' => $run->id]));

        $response->assertOk();
        $response->streamedContent();

        $this->assertDatabaseHas('admin_ai_ops_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'result_summary' => 'hello',
        ]);
    }

    public function test_stream_marks_failed_on_empty_model_text(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $admin = $this->createAdmin();
        $model = $this->createAiModel();
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => '空',
        ]);
        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $model->id,
            'status' => 'queued',
            'input_text' => 'x',
        ]);

        $this->partialMock(AdminAiOpsChatService::class, function ($mock): void {
            $mock->shouldReceive('streamAssistantReply')
                ->once()
                ->andReturn('   ');
        });

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-ops.runs.stream', ['runId' => $run->id]));

        $response->assertOk();
        $response->streamedContent();

        $this->assertDatabaseHas('admin_ai_ops_runs', [
            'id' => $run->id,
            'status' => 'failed',
        ]);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'ai_ops_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => 'ai-ops-admin@example.com',
            'display_name' => 'AI Ops Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createRunInSession(AdminAiOpsSession $session, Admin $admin, string $status, ?AiModel $aiModel = null): AdminAiOpsRun
    {
        return AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $aiModel?->id,
            'status' => $status,
            'input_text' => '测试 AI 运维',
        ]);
    }

    private function createAiModel(): AiModel
    {
        return AiModel::query()->create([
            'name' => 'AI Ops Test Model',
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
