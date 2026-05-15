<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Models\AdminAiOpsToolApproval;
use App\Models\AiModel;
use App\Services\Admin\AiOps\AdminAiOpsChatService;
use App\Services\Admin\AiOps\AdminAiOpsRunService;
use App\Services\Admin\AiOps\AdminAiOpsStreamContext;
use App\Services\Admin\AiOps\AdminAiOpsToolApprovalService;
use App\Services\Admin\AiOps\Exceptions\AdminAiOpsToolApprovalPendingException;
use App\Support\AdminWeb;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolCall as AiStreamToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as AiStreamToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * 后台 AI 运维：POST 创建排队 run；GET（EventSource）流式拉取模型输出并完成落库。
 */
class AdminAiOpsController extends Controller
{
    /**
     * 显示 AI 运维独立会话页。
     */
    public function index(): View
    {
        return view('admin.ai-ops.index', [
            'pageTitle' => __('admin.ai_ops.page_title'),
            'activeMenu' => 'ai_ops',
            'adminSiteName' => AdminWeb::siteName(),
            'models' => $this->availableChatModels(),
        ]);
    }

    /**
     * 返回当前管理员的历史会话列表。
     */
    public function sessions(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $sessions = AdminAiOpsSession::query()
            ->where('admin_id', (int) $admin->id)
            ->with(['runs' => fn ($query) => $query->latest('id')])
            ->latest('updated_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (AdminAiOpsSession $session): array => $this->sessionListItem($session))
            ->values()
            ->all();

        return response()->json(['items' => $sessions]);
    }

    /**
     * 创建一个空的 AI 运维会话。
     */
    public function createSession(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
        ]);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => trim((string) ($payload['title'] ?? '')) ?: __('admin.ai_ops.new_session_title'),
        ]);

        return response()->json($this->sessionPayload($session), 201);
    }

    /**
     * 返回指定会话的完整历史（runs 按时间正序）。
     */
    public function showSession(Request $request, int $sessionId, AdminAiOpsRunService $runs): JsonResponse
    {
        $session = $this->findOwnedSession($request, $sessionId);

        $approvalService = app(AdminAiOpsToolApprovalService::class);
        $awaiting = AdminAiOpsRun::query()
            ->where('session_id', (int) $session->id)
            ->where('status', 'awaiting_confirmation')
            ->get(['id']);
        foreach ($awaiting as $row) {
            $runRow = AdminAiOpsRun::query()->find((int) $row->id);
            if ($runRow instanceof AdminAiOpsRun) {
                $approvalService->expirePendingIfStale($runRow);
            }
        }

        return response()->json($this->sessionPayload($session, $runs));
    }

    /**
     * 创建一条待流式补全的 run（status=queued）；客户端随后用 EventSource 打开 {@see stream}。
     */
    public function chat(Request $request, AdminAiOpsRunService $runs): JsonResponse
    {
        $payload = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'ai_model_id' => ['required', 'integer', 'exists:ai_models,id'],
            'session_id' => ['nullable', 'integer', 'exists:admin_ai_ops_sessions,id'],
        ]);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $message = trim((string) $payload['message']);

        $session = ! empty($payload['session_id'])
            ? $this->findOwnedSession($request, (int) $payload['session_id'])
            : AdminAiOpsSession::query()->create([
                'admin_id' => (int) $admin->id,
                'title' => Str::limit($message, 60, ''),
            ]);

        if (trim((string) $session->title) === '' || (string) $session->title === __('admin.ai_ops.new_session_title')) {
            $session->update(['title' => Str::limit($message, 60, '')]);
        }

        $modelId = $this->resolveAiModelId((int) $payload['ai_model_id']);

        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $modelId,
            'status' => 'queued',
            'input_text' => $message,
        ]);

        $session->touch();

        $run = $run->fresh(['steps', 'attachments', 'aiModel']) ?? $run;

        return response()->json([
            'session' => [
                'id' => (int) $session->id,
                'title' => (string) ($session->title ?: __('admin.ai_ops.new_session_title')),
            ],
            'run' => $runs->payload($run),
        ], 201);
    }

    /**
     * SSE（EventSource）：对 queued run 在本 HTTP 连接内持锁流式调用模型，推送 event: delta / stream_status / tool / run，终态发送 event: done。
     */
    public function stream(Request $request, int $runId, AdminAiOpsRunService $runs, AdminAiOpsChatService $chat): StreamedResponse
    {
        set_time_limit(300);
        $this->findOwnedRun($request, $runId);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $adminId = (int) $admin->id;

        $maxSeconds = (int) config('geoflow.admin_ai_ops_chat_stream_max_seconds', 900);

        return response()->stream(function () use ($runId, $adminId, $runs, $chat, $maxSeconds): void {
            $lock = Cache::lock('geoflow:admin_ai_ops_chat_stream:'.(int) $runId, $maxSeconds + 120);

            try {
                $lock->block($maxSeconds + 120);

                $run = AdminAiOpsRun::query()
                    ->where('admin_id', $adminId)
                    ->whereKey($runId)
                    ->with(['steps', 'attachments', 'aiModel'])
                    ->first();

                if (! $run instanceof AdminAiOpsRun) {
                    $this->writeAdminAiOpsSseJsonEvent('stream_error', ['message' => 'not_found']);
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $status = (string) $run->status;

                if ($status === 'awaiting_confirmation') {
                    app(AdminAiOpsToolApprovalService::class)->expirePendingIfStale($run);
                    $run = AdminAiOpsRun::query()
                        ->where('admin_id', $adminId)
                        ->whereKey($runId)
                        ->with(['steps', 'attachments', 'aiModel'])
                        ->first() ?? $run;
                    $status = (string) $run->status;
                }

                if (in_array($status, ['completed', 'failed'], true)) {
                    $this->writeAdminAiOpsSseRunEvent($runs->payload($run->fresh(['steps', 'attachments', 'aiModel']) ?? $run));
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                if ($status !== 'queued') {
                    $this->writeAdminAiOpsSseRunEvent($runs->payload($run->fresh(['steps', 'attachments', 'aiModel']) ?? $run));
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $run = $runs->updateRun($run, [
                    'status' => 'processing',
                    'started_at' => now(),
                ]);

                $this->writeAdminAiOpsSseRunEvent($runs->payload($run));

                $aiModel = $run->aiModel;
                if (! $aiModel instanceof AiModel) {
                    $run = $runs->updateRun($run->fresh() ?? $run, [
                        'status' => 'failed',
                        'error_message' => 'AI 模型不存在或已被删除。',
                        'finished_at' => now(),
                    ]);
                    $this->writeAdminAiOpsSseRunEvent($runs->payload($run));
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $priorMessages = $chat->priorMessagesBeforeRun((int) $run->session_id, (int) $run->id);
                $currentUserMessage = trim((string) ($run->input_text ?? ''));
                $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));

                $deadline = microtime(true) + $maxSeconds;

                $haltedForApproval = false;

                app()->instance(AdminAiOpsStreamContext::class, new AdminAiOpsStreamContext((int) $run->id, $adminId));
                try {
                    try {
                        $assistantText = $chat->streamAssistantReply(
                            $currentUserMessage,
                            $priorMessages,
                            $aiModel,
                            function (string $accumulated) use ($deadline): void {
                                if (microtime(true) > $deadline) {
                                    throw new \RuntimeException('模型输出超过单连接时间上限，已中止。');
                                }
                                if (app()->bound(AdminAiOpsStreamContext::class)) {
                                    app(AdminAiOpsStreamContext::class)->setPartialAssistantText($accumulated);
                                }
                                $this->writeAdminAiOpsSseJsonEvent('delta', ['text' => $accumulated]);
                            },
                            function (object $event): void {
                                $this->emitAdminAiOpsSseFromAiStreamEvent($event);
                            },
                            null,
                        );

                        $assistantText = trim((string) $assistantText);

                        if ($assistantText === '') {
                            throw new \RuntimeException('模型返回为空');
                        }

                        $run = $runs->updateRun($run->fresh() ?? $run, [
                            'status' => 'completed',
                            'result_summary' => $assistantText,
                            'finished_at' => now(),
                        ]);
                    } catch (AdminAiOpsToolApprovalPendingException $e) {
                        $haltedForApproval = true;
                        $this->emitAdminAiOpsSseSyntheticToolDoneForPendingApproval($e);
                        $freshRun = AdminAiOpsRun::query()
                            ->whereKey($runId)
                            ->with(['steps', 'attachments', 'aiModel'])
                            ->first();

                        $this->writeAdminAiOpsSseJsonEvent('approval_required', [
                            'approval_id' => $e->approvalId,
                            'tool_name' => $e->toolName,
                            'summary' => $e->summary,
                            'expires_at' => $e->expiresAtIso8601,
                            'fingerprint' => $e->fingerprint,
                        ]);

                        $this->writeAdminAiOpsSseRunEvent($runs->payload($freshRun instanceof AdminAiOpsRun ? $freshRun : $run));
                    } catch (Throwable $e) {
                        $human = OpenAiRuntimeProvider::normalizeApiException($e, $providerUrl);
                        $run = $runs->updateRun($run->fresh() ?? $run, [
                            'status' => 'failed',
                            'error_message' => $human !== '' ? $human : trim($e->getMessage()),
                            'finished_at' => now(),
                        ]);
                    }
                } finally {
                    app()->forgetInstance(AdminAiOpsStreamContext::class);
                }

                if (! $haltedForApproval) {
                    $this->writeAdminAiOpsSseRunEvent($runs->payload($run->fresh(['steps', 'attachments', 'aiModel']) ?? $run));
                }
                $this->writeAdminAiOpsSseDoneEvent();
            } catch (Throwable $e) {
                $this->writeAdminAiOpsSseJsonEvent('stream_error', ['message' => trim($e->getMessage()) ?: 'stream_failed']);
                $this->writeAdminAiOpsSseDoneEvent();
            } finally {
                try {
                    $lock->release();
                } catch (Throwable) {
                    //
                }
            }
        }, 200, $this->adminAiOpsSseResponseHeaders());
    }

    /**
     * POST：批准挂起的工具调用（服务端执行已存参数），并返回一次性续流 URL。
     */
    public function approveToolApproval(
        Request $request,
        int $runId,
        string $approvalId,
        AdminAiOpsToolApprovalService $approvals,
        AdminAiOpsRunService $runs,
    ): JsonResponse {
        $this->findOwnedRun($request, $runId);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $approval = $approvals->assertCanDecide($approvalId, (int) $admin->id);
        $out = $approvals->approveAndPrepareResume($approval, (int) $admin->id, $runId);

        Log::info('admin_ai_ops_tool_approval_approve_http', [
            'run_id' => $runId,
            'approval_id' => $approvalId,
            'admin_id' => (int) $admin->id,
            'already_executed' => $out['already_executed'],
        ]);

        $run = AdminAiOpsRun::query()
            ->whereKey($runId)
            ->with(['steps', 'attachments', 'aiModel'])
            ->firstOrFail();

        return response()->json([
            'ok' => true,
            'resume_stream_url' => $out['resume_stream_url'],
            'already_executed' => $out['already_executed'],
            'executed_this_request' => $out['executed_this_request'],
            'run' => $runs->payload($run),
        ]);
    }

    /**
     * POST：拒绝挂起的工具调用（不执行写操作），并返回一次性拒绝续流 URL。
     */
    public function rejectToolApproval(
        Request $request,
        int $runId,
        string $approvalId,
        AdminAiOpsToolApprovalService $approvals,
        AdminAiOpsRunService $runs,
    ): JsonResponse {
        $this->findOwnedRun($request, $runId);

        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $approval = $approvals->assertCanDecide($approvalId, (int) $admin->id);
        $out = $approvals->rejectAndPrepareResume($approval, (int) $admin->id, $runId, $payload['reason'] ?? null);

        Log::info('admin_ai_ops_tool_approval_reject_http', [
            'run_id' => $runId,
            'approval_id' => $approvalId,
            'admin_id' => (int) $admin->id,
        ]);

        $run = AdminAiOpsRun::query()
            ->whereKey($runId)
            ->with(['steps', 'attachments', 'aiModel'])
            ->firstOrFail();

        return response()->json([
            'ok' => true,
            'reject_resume_stream_url' => $out['reject_resume_stream_url'],
            'run' => $runs->payload($run),
        ]);
    }

    /**
     * SSE：在批准/拒绝后消费一次性 nonce，续跑第二轮模型输出并完成 run 终态。
     */
    public function resumeStream(
        Request $request,
        int $runId,
        AdminAiOpsToolApprovalService $approvals,
        AdminAiOpsRunService $runs,
        AdminAiOpsChatService $chat,
    ): StreamedResponse {
        set_time_limit(300);
        $this->findOwnedRun($request, $runId);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $adminId = (int) $admin->id;

        $nonce = trim((string) $request->query('nonce', ''));
        $maxSeconds = (int) config('geoflow.admin_ai_ops_chat_stream_max_seconds', 900);

        return response()->stream(function () use ($runId, $adminId, $nonce, $approvals, $runs, $chat, $maxSeconds): void {
            $lock = Cache::lock('geoflow:admin_ai_ops_resume_stream:'.(int) $runId, $maxSeconds + 120);

            try {
                $lock->block($maxSeconds + 120);

                $payload = $approvals->consumeResumeNonce($nonce);
                if (! is_array($payload)
                    || (int) ($payload['admin_id'] ?? 0) !== $adminId
                    || (int) ($payload['run_id'] ?? 0) !== (int) $runId) {
                    $this->writeAdminAiOpsSseJsonEvent('stream_error', ['message' => 'invalid_or_expired_nonce']);
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $approvalId = (string) ($payload['approval_id'] ?? '');
                $decision = (string) ($payload['decision'] ?? '');

                $run = AdminAiOpsRun::query()
                    ->where('admin_id', $adminId)
                    ->whereKey($runId)
                    ->with(['steps', 'attachments', 'aiModel'])
                    ->first();

                if (! $run instanceof AdminAiOpsRun) {
                    $this->writeAdminAiOpsSseJsonEvent('stream_error', ['message' => 'not_found']);
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $status = (string) $run->status;
                if (in_array($status, ['completed', 'failed'], true)) {
                    $this->writeAdminAiOpsSseRunEvent($runs->payload($run->fresh(['steps', 'attachments', 'aiModel']) ?? $run));
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $approval = AdminAiOpsToolApproval::query()->whereKey($approvalId)->first();
                if (! $approval instanceof AdminAiOpsToolApproval || (int) $approval->run_id !== (int) $runId) {
                    $this->writeAdminAiOpsSseJsonEvent('stream_error', ['message' => 'approval_mismatch']);
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                if ($decision === 'approve' && $approval->status !== 'executed') {
                    $this->writeAdminAiOpsSseJsonEvent('stream_error', ['message' => 'approval_not_executed']);
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                if ($decision === 'reject' && $approval->status !== 'rejected') {
                    $this->writeAdminAiOpsSseJsonEvent('stream_error', ['message' => 'approval_not_rejected']);
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $aiModel = $run->aiModel;
                if (! $aiModel instanceof AiModel) {
                    $run = $runs->updateRun($run->fresh() ?? $run, [
                        'status' => 'failed',
                        'error_message' => 'AI 模型不存在或已被删除。',
                        'finished_at' => now(),
                    ]);
                    $this->writeAdminAiOpsSseRunEvent($runs->payload($run));
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $priorMessages = $chat->priorMessagesBeforeRun((int) $run->session_id, (int) $run->id);
                $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
                $deadline = microtime(true) + $maxSeconds;

                app()->instance(AdminAiOpsStreamContext::class, new AdminAiOpsStreamContext((int) $run->id, $adminId));
                try {
                    if ($decision === 'approve') {
                        $assistantText = $chat->streamAssistantResumeAfterApproval(
                            $run,
                            $priorMessages,
                            $aiModel,
                            function (string $accumulated) use ($deadline): void {
                                if (microtime(true) > $deadline) {
                                    throw new \RuntimeException('模型输出超过单连接时间上限，已中止。');
                                }
                                if (app()->bound(AdminAiOpsStreamContext::class)) {
                                    app(AdminAiOpsStreamContext::class)->setPartialAssistantText($accumulated);
                                }
                                $this->writeAdminAiOpsSseJsonEvent('delta', ['text' => $accumulated]);
                            },
                            function (object $event): void {
                                $this->emitAdminAiOpsSseFromAiStreamEvent($event);
                            },
                            null,
                        );
                    } else {
                        $assistantText = $chat->streamAssistantResumeAfterReject(
                            $run,
                            $priorMessages,
                            $aiModel,
                            (string) $approval->tool_name,
                            (string) ($approval->rejection_reason ?? ''),
                            (string) $approval->args_fingerprint,
                            function (string $accumulated) use ($deadline): void {
                                if (microtime(true) > $deadline) {
                                    throw new \RuntimeException('模型输出超过单连接时间上限，已中止。');
                                }
                                if (app()->bound(AdminAiOpsStreamContext::class)) {
                                    app(AdminAiOpsStreamContext::class)->setPartialAssistantText($accumulated);
                                }
                                $this->writeAdminAiOpsSseJsonEvent('delta', ['text' => $accumulated]);
                            },
                            function (object $event): void {
                                $this->emitAdminAiOpsSseFromAiStreamEvent($event);
                            },
                            null,
                        );
                    }

                    $assistantText = trim((string) $assistantText);
                    if ($assistantText === '') {
                        throw new \RuntimeException('模型返回为空');
                    }

                    $snapshotRun = $run->fresh() ?? $run;
                    $mergedSummary = $this->mergeAiOpsPartialAssistantWithResumeSummary($snapshotRun, $assistantText);

                    $run = $runs->updateRun($snapshotRun, [
                        'status' => 'completed',
                        'result_summary' => $mergedSummary,
                        'finished_at' => now(),
                    ]);
                } catch (Throwable $e) {
                    $human = OpenAiRuntimeProvider::normalizeApiException($e, $providerUrl);
                    $run = $runs->updateRun($run->fresh() ?? $run, [
                        'status' => 'failed',
                        'error_message' => $human !== '' ? $human : trim($e->getMessage()),
                        'finished_at' => now(),
                    ]);
                } finally {
                    app()->forgetInstance(AdminAiOpsStreamContext::class);
                }

                $this->writeAdminAiOpsSseRunEvent($runs->payload($run->fresh(['steps', 'attachments', 'aiModel']) ?? $run));
                $this->writeAdminAiOpsSseDoneEvent();
            } catch (Throwable $e) {
                $this->writeAdminAiOpsSseJsonEvent('stream_error', ['message' => trim($e->getMessage()) ?: 'stream_failed']);
                $this->writeAdminAiOpsSseDoneEvent();
            } finally {
                try {
                    $lock->release();
                } catch (Throwable) {
                    //
                }
            }
        }, 200, $this->adminAiOpsSseResponseHeaders());
    }

    /**
     * 将审批前已流式输出的助手片段与续跑正文合并为一条 result_summary，避免刷新后只剩后半段。
     */
    private function mergeAiOpsPartialAssistantWithResumeSummary(AdminAiOpsRun $run, string $resumeAssistantText): string
    {
        $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
        $partial = trim((string) ($snapshot['partial_assistant_text'] ?? ''));
        $resume = trim($resumeAssistantText);
        if ($partial === '') {
            return $resume;
        }
        if ($resume === '') {
            return $partial;
        }
        if (str_starts_with($resume, $partial)) {
            return $resume;
        }

        return $partial."\n\n".$resume;
    }

    /**
     * 查询当前管理员拥有的 run。
     */
    private function findOwnedRun(Request $request, int $runId): AdminAiOpsRun
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return AdminAiOpsRun::query()
            ->where('admin_id', (int) $admin->id)
            ->findOrFail($runId);
    }

    /**
     * 查询当前管理员拥有的会话。
     */
    private function findOwnedSession(Request $request, int $sessionId): AdminAiOpsSession
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return AdminAiOpsSession::query()
            ->where('admin_id', (int) $admin->id)
            ->findOrFail($sessionId);
    }

    /**
     * 构造会话列表项。
     *
     * @return array<string,mixed>
     */
    private function sessionListItem(AdminAiOpsSession $session): array
    {
        $latestRun = $session->runs->first();

        return [
            'id' => (int) $session->id,
            'title' => (string) ($session->title ?: __('admin.ai_ops.new_session_title')),
            'updated_at' => $session->updated_at?->toDateTimeString(),
            'latest_run' => $latestRun ? [
                'id' => (int) $latestRun->id,
                'status' => (string) $latestRun->status,
                'input_text' => Str::limit((string) $latestRun->input_text, 80, ''),
                'result_summary' => Str::limit((string) $latestRun->result_summary, 80, ''),
            ] : null,
        ];
    }

    /**
     * 构造会话详情响应。
     *
     * @return array<string,mixed>
     */
    private function sessionPayload(AdminAiOpsSession $session, ?AdminAiOpsRunService $runs = null): array
    {
        $session->load(['runs' => fn ($query) => $query->with(['steps', 'attachments', 'aiModel'])->oldest('id')]);
        $runs ??= app(AdminAiOpsRunService::class);

        return [
            'id' => (int) $session->id,
            'title' => (string) ($session->title ?: __('admin.ai_ops.new_session_title')),
            'created_at' => $session->created_at?->toDateTimeString(),
            'updated_at' => $session->updated_at?->toDateTimeString(),
            'runs' => $session->runs->map(fn (AdminAiOpsRun $run): array => $runs->payload($run))->values()->all(),
        ];
    }

    /**
     * 校验 AI 运维使用的模型必须是启用的聊天模型。
     */
    private function resolveAiModelId(int $modelId): int
    {
        $exists = AiModel::query()
            ->whereKey($modelId)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->exists();

        abort_if(! $exists, 422, '请选择可用的聊天模型。');

        return $modelId;
    }

    /**
     * 获取可用于 AI 运维的聊天模型。
     */
    private function availableChatModels()
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get(['id', 'name', 'model_id']);
    }

    /**
     * SSE 响应头：禁用代理缓冲以便及时下推。
     *
     * @return array<string, string>
     */
    private function adminAiOpsSseResponseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * 高风险工具在真正执行前挂起审批：Laravel AI 不会对该次调用再发 ToolResult，前端会一直处于「调用中」。
     * 这里按最近一次 ToolCall 的 id 补发一条 tool/done，与正常工具返回的 SSE 形态一致。
     */
    private function emitAdminAiOpsSseSyntheticToolDoneForPendingApproval(AdminAiOpsToolApprovalPendingException $e): void
    {
        if (! app()->bound(AdminAiOpsStreamContext::class)) {
            return;
        }

        $lastId = trim((string) app(AdminAiOpsStreamContext::class)->lastToolCallId);
        if ($lastId === '') {
            return;
        }

        $this->writeAdminAiOpsSseJsonEvent('tool', [
            'phase' => 'done',
            'tool_call_id' => $lastId,
            'tool_name' => $e->toolName,
            'successful' => true,
            'error' => '',
            'result_preview' => (string) __('admin.ai_ops.tool_pending_approval_result_preview'),
        ]);
    }

    /**
     * 将 Laravel AI 流中的非文本事件映射为前端可展示的 SSE（连接、工具调用与结果）。
     */
    private function emitAdminAiOpsSseFromAiStreamEvent(object $event): void
    {
        if ($event instanceof StreamStart) {
            $this->writeAdminAiOpsSseJsonEvent('stream_status', [
                'kind' => 'connected',
                'provider' => $event->provider,
                'model' => $event->model,
            ]);

            return;
        }

        if ($event instanceof AiStreamToolCall) {
            if (app()->bound(AdminAiOpsStreamContext::class)) {
                app(AdminAiOpsStreamContext::class)->lastToolCallId = (string) $event->toolCall->id;
            }
            $this->writeAdminAiOpsSseJsonEvent('tool', [
                'phase' => 'calling',
                'tool_call_id' => $event->toolCall->id,
                'tool_name' => $event->toolCall->name,
                'arguments_preview' => $this->adminAiOpsSseEncodeJsonPreview($event->toolCall->arguments, 1200),
            ]);

            return;
        }

        if ($event instanceof AiStreamToolResult) {
            $this->writeAdminAiOpsSseJsonEvent('tool', [
                'phase' => 'done',
                'tool_call_id' => $event->toolResult->id,
                'tool_name' => $event->toolResult->name,
                'successful' => $event->successful,
                'error' => $event->error,
                'result_preview' => $this->adminAiOpsSseToolResultDataPreview($event->toolResult->result),
            ]);
            $this->writeAdminAiOpsSseJsonEvent('stream_status', [
                'kind' => 'post_tool_model_round',
                'tool_name' => $event->toolResult->name,
                'successful' => $event->successful,
            ]);

            return;
        }
    }

    /**
     * 将工具返回体截断为适合 SSE 的纯文本预览（避免整页 JSON 撑爆前端）。
     */
    private function adminAiOpsSseToolResultDataPreview(mixed $result): ?string
    {
        if ($result === null) {
            return null;
        }

        if (is_string($result)) {
            $text = $result;
        } else {
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            $text = $encoded !== false ? $encoded : '';
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $maxBytes = 2400;
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return substr($text, 0, $maxBytes).'…';
    }

    /**
     * 将数组编码为 JSON 预览字符串并截断，避免 SSE 体积过大。
     *
     * @param  array<string, mixed>  $payload
     */
    private function adminAiOpsSseEncodeJsonPreview(array $payload, int $maxBytes): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return '';
        }
        if (strlen($json) <= $maxBytes) {
            return $json;
        }

        return substr($json, 0, $maxBytes).'…';
    }

    /**
     * 将当前 PHP 输出缓冲刷出，供 SSE 立即送达客户端。
     */
    private function flushAdminAiOpsSseOutput(): void
    {
        $levels = ob_get_level();
        for ($i = 0; $i < $levels; $i++) {
            ob_flush();
        }
        flush();
    }

    /**
     * 写入一条命名 SSE 事件（data 为 JSON 对象）。
     *
     * @param  array<string, mixed>  $data
     */
    private function writeAdminAiOpsSseJsonEvent(string $event, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        echo 'event: '.$event."\n";
        echo 'data: '.($json !== false ? $json : '{}')."\n\n";
        $this->flushAdminAiOpsSseOutput();
    }

    /**
     * 写入 event: run（payload 为 run 快照数组）。
     *
     * @param  array<string, mixed>  $runPayload
     */
    private function writeAdminAiOpsSseRunEvent(array $runPayload): void
    {
        $this->writeAdminAiOpsSseJsonEvent('run', ['run' => $runPayload]);
    }

    /**
     * 写入 event: done，通知浏览器可关闭 EventSource。
     */
    private function writeAdminAiOpsSseDoneEvent(): void
    {
        echo "event: done\n";
        echo "data: {}\n\n";
        $this->flushAdminAiOpsSseOutput();
    }
}
