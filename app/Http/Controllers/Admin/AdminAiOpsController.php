<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Models\AdminAiOpsToolApproval;
use App\Models\AiModel;
use App\Services\Admin\AiOps\AdminAiOpsChatService;
use App\Services\Admin\AiOps\AdminAiOpsLlmTranscriptRecorder;
use App\Services\Admin\AiOps\AdminAiOpsRunService;
use App\Services\Admin\AiOps\AdminAiOpsStreamContext;
use App\Services\Admin\AiOps\AdminAiOpsToolApprovalService;
use App\Services\Admin\AiOps\Exceptions\AdminAiOpsToolApprovalPendingException;
use App\Services\GeoFlow\ArticleSearch\ArticleSearchConfig;
use App\Support\AdminAiOpsUtf8;
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
 * 后台 AI 运维 HTTP 入口：会话 CRUD、首轮 SSE 流式对话、工具审批 HTTP、审批后续流 SSE。
 *
 * ## 整体状态机（单条 run）
 *
 * ```
 * chat(POST) → status=queued
 *     ↓ EventSource stream()
 * status=processing → 模型流式输出 + 工具调用
 *     ├─ 无写工具 pending → status=completed
 *     └─ 写工具在原始 tool call 内 pending → status=awaiting_confirmation（HTTP 只记录决定）
 *            ↓ approveToolApproval / rejectToolApproval（POST）
 *              status=processing → Prism 自动追加 role=tool 并继续后续 API → completed / 再次 awaiting_confirmation
 * ```
 *
 * ## 依赖服务职责
 *
 * - {@see AdminAiOpsRunService}：run 状态更新、{@see AdminAiOpsRunService::payload()} 前端快照、{@see AdminAiOpsRunService::persistAssistantTimeline()} 持久化工具卡片时间线
 * - {@see AdminAiOpsChatService}：{@see AdminAiOpsChatService::priorMessagesBeforeRun()} 历史消息、{@see AdminAiOpsChatService::streamAssistantReply()} 首轮流式、{@see AdminAiOpsChatService::streamAssistantResumeAfterApproval()} / {@see AdminAiOpsChatService::streamAssistantResumeAfterReject()} 审批后续流
 * - {@see AdminAiOpsToolApprovalService}：pending 落库、批准/拒绝执行、{@see AdminAiOpsToolApprovalService::consumeResumeNonce()} 消费续流 nonce、{@see AdminAiOpsToolApprovalService::syncToolCallId()} 对齐并行 tool_call_id
 * - {@see AdminAiOpsStreamContext}：单次 SSE 连接内的 partial 文本、工具时间线、lastToolCallId
 *
 * ## SSE 事件（前端 EventSource 监听）
 *
 * delta | tool | approval_required | stream_status | run | done | stream_error
 */
class AdminAiOpsController extends Controller
{
    /**
     * 显示 AI 运维独立会话页。
     *
     * 调用：{@see AdminWeb::siteName()} 站点名；{@see availableChatModels()} 可选模型；{@see ArticleSearchConfig::fromSettings()} 联网搜索 Key 是否已配置。
     */
    public function index(): View
    {
        $articleSearchConfig = ArticleSearchConfig::fromSettings();

        return view('admin.ai-ops.index', [
            'pageTitle' => __('admin.ai_ops.page_title'),
            'activeMenu' => 'ai_ops',
            'adminSiteName' => AdminWeb::siteName(),
            'models' => $this->availableChatModels(),
            'webSearchKeyConfigured' => $articleSearchConfig->hasApiKeyConfigured(),
            'articleSearchSettingsUrl' => route('admin.site-settings.article-search'),
        ]);
    }

    /**
     * 返回当前管理员的历史会话列表（侧边栏，最多 100 条）。
     *
     * 调用：{@see sessionListItem()} 构造每条会话的摘要 JSON。
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
     * 创建一个空的 AI 运维会话（无 run，等待用户首条消息）。
     *
     * 调用：{@see sessionPayload()} 返回会话详情（空 runs）。
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
     * 返回指定会话的完整历史（runs 按 id 正序）。
     *
     * 调用：
     * - {@see findOwnedSession()} 校验会话归属
     * - {@see AdminAiOpsToolApprovalService::expirePendingIfStale()} 打开会话时清理过期 pending
     * - {@see sessionPayload()} → {@see AdminAiOpsRunService::payload()} 含 approval_pending、assistant_timeline
     */
    public function showSession(Request $request, int $sessionId, AdminAiOpsRunService $runs): JsonResponse
    {
        $session = $this->findOwnedSession($request, $sessionId);

        $approvalService = app(AdminAiOpsToolApprovalService::class);
        // 打开会话时顺带清理：awaiting_confirmation 且 pending 已过期的 run → failed
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
     * 删除指定会话及其关联 runs（数据库外键 cascadeOnDelete）。
     */
    public function destroySession(Request $request, int $sessionId): JsonResponse
    {
        $session = $this->findOwnedSession($request, $sessionId);
        $session->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * 创建 queued run；客户端随后 EventSource 连接 {@see stream}。
     *
     * 调用：{@see findOwnedSession()}、{@see resolveAiModelId()}、{@see AdminAiOpsRunService::payload()}。
     */
    public function chat(Request $request, AdminAiOpsRunService $runs): JsonResponse
    {
        $payload = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'ai_model_id' => ['required', 'integer', 'exists:ai_models,id'],
            'session_id' => ['nullable', 'integer', 'exists:admin_ai_ops_sessions,id'],
            'web_search_enabled' => ['sometimes', 'boolean'],
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

        $webSearchEnabled = (bool) ($payload['web_search_enabled'] ?? false);

        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $modelId,
            'status' => 'queued',
            'input_text' => $message,
            'plan_stream_snapshot' => ['web_search_enabled' => $webSearchEnabled],
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
     * 首轮 SSE：对 queued run 持锁流式调用模型，推送 delta / tool / approval_required / run，终态发送 done。
     *
     * ## 连接内主要步骤
     *
     * 1. Cache 锁防重复 stream
     * 2. 仅 status=queued 才进入模型调用；completed/failed/非 queued 只推送当前快照后 done
     * 3. {@see AdminAiOpsStreamContext::forRun()} 绑定流式上下文（工具时间线、partial 文本）
     * 4. {@see AdminAiOpsChatService::streamAssistantReply()} 流式对话；回调 {@see emitAdminAiOpsSseFromAiStreamEvent()} 映射 tool 事件
     * 5. 流结束后 {@see AdminAiOpsToolApprovalService::pendingCountForRun()}：
     *    - pending>0 → {@see persistAssistantTimeline()} + awaiting_confirmation + {@see emitAdminAiOpsFirstPendingApprovalRequired()}
     *    - 否则 → completed + {@see planStreamSnapshotWithTimeline()}
     * 6. 兼容旧路径：{@see AdminAiOpsToolApprovalPendingException} → 单条 approval_required SSE
     *
     * @see writeAdminAiOpsSseJsonEvent() 写入命名 SSE 事件
     * @see writeAdminAiOpsSseDoneEvent() 通知前端关闭 EventSource
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
            // 同一 run 同时只允许一条首轮 SSE，避免双连接重复调模型
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

                // 等待确认态：先尝试 expirePendingIfStale，避免过期 pending 一直占着 run
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

                // queued → processing：标记开始时间，推送 run 事件
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

                // priorMessagesBeforeRun：本会话内已完成/失败的历史轮次，拼入 Agent 上下文
                $priorMessages = $chat->priorMessagesBeforeRun((int) $run->session_id, (int) $run->id);
                $currentUserMessage = trim((string) ($run->input_text ?? ''));
                // resolveChatBaseUrl：归一化 OpenAI 兼容 API 根路径，供错误文案使用
                $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));

                $deadline = microtime(true) + $maxSeconds;

                $haltedForApproval = false;
                // runWebSearchEnabled：从 plan_stream_snapshot 读取是否挂载 TavilyWebSearchTool
                $webSearchEnabled = $chat->runWebSearchEnabled($run);

                // 绑定流式上下文：写工具在原始 tool call 内挂起审批，并通过 emitter 及时推送 Modal。
                $streamContext = AdminAiOpsStreamContext::forRun(
                    (int) $run->id,
                    $adminId,
                    is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : null,
                );
                $streamContext->approvalRequiredEmitter = function () use ($runId, $runs): void {
                    $this->emitAdminAiOpsFirstPendingApprovalRequired((int) $runId);
                    $freshRun = AdminAiOpsRun::query()
                        ->whereKey($runId)
                        ->with(['steps', 'attachments', 'aiModel'])
                        ->first();
                    if ($freshRun instanceof AdminAiOpsRun) {
                        $this->writeAdminAiOpsSseRunEvent($runs->payload($freshRun));
                    }
                };
                app()->instance(AdminAiOpsStreamContext::class, $streamContext);
                try {
                    try {
                        // streamAssistantReply：Laravel AI Agent 流式补全；delta 回调写 SSE，raw 事件回调映射 tool 卡片
                        $assistantText = $chat->streamAssistantReply(
                            $currentUserMessage,
                            $priorMessages,
                            $aiModel,
                            function (string $accumulated) use ($deadline): void {
                                if (microtime(true) > $deadline) {
                                    throw new \RuntimeException('模型输出超过单连接时间上限，已中止。');
                                }
                                // setPartialAssistantText：同步写入 StreamContext + 时间线文本段（审批续跑时合成用）
                                if (app()->bound(AdminAiOpsStreamContext::class)) {
                                    app(AdminAiOpsStreamContext::class)->setPartialAssistantText($accumulated);
                                }
                                $this->writeAdminAiOpsSseJsonEvent('delta', ['text' => $accumulated]);
                            },
                            function (object $event): void {
                                // 将 StreamStart / ToolCall / ToolResult 转为前端 tool 卡片 SSE
                                $this->emitAdminAiOpsSseFromAiStreamEvent($event);
                            },
                            null,
                            $webSearchEnabled,
                        );

                        $assistantText = trim((string) $assistantText);

                        // 同轮多个写工具：createPendingWithoutThrow 不中断流，此处统一检测 pending 队列
                        $pendingCount = app(AdminAiOpsToolApprovalService::class)->pendingCountForRun((int) $run->id);
                        if ($pendingCount > 0) {
                            $haltedForApproval = true;
                            $freshRun = AdminAiOpsRun::query()
                                ->whereKey($runId)
                                ->with(['steps', 'attachments', 'aiModel'])
                                ->first();

                            if ($freshRun instanceof AdminAiOpsRun && app()->bound(AdminAiOpsStreamContext::class)) {
                                $ctx = app(AdminAiOpsStreamContext::class);
                                $snapshot = $this->planStreamSnapshotWithTimeline($freshRun);
                                $partial = trim((string) $ctx->partialAssistantText);
                                if ($partial !== '') {
                                    $snapshot['partial_assistant_text'] = $partial;
                                }
                                $runs->updateRun($freshRun, [
                                    'status' => 'awaiting_confirmation',
                                    'plan_stream_snapshot' => $snapshot,
                                ]);
                                $freshRun = $freshRun->fresh() ?? $freshRun;
                            }

                            // 推送 approval_required：前端弹 Modal，HTTP 逐条 approve（非 SSE 内批准）
                            $this->emitAdminAiOpsFirstPendingApprovalRequired((int) $runId);
                            $this->writeAdminAiOpsSseRunEvent($runs->payload($freshRun instanceof AdminAiOpsRun ? $freshRun : $run));
                        } elseif ($assistantText === '') {
                            throw new \RuntimeException('模型返回为空');
                        } else {
                            // 无 pending：正常完成，合并时间线进 snapshot
                            $run = $runs->updateRun($run->fresh() ?? $run, [
                                'status' => 'completed',
                                'result_summary' => $assistantText,
                                'plan_stream_snapshot' => $this->planStreamSnapshotWithTimeline($run->fresh() ?? $run),
                                'finished_at' => now(),
                            ]);
                        }
                    } catch (AdminAiOpsToolApprovalPendingException $e) {
                        // 旧路径：createPendingAndThrow 中断流；现主路径为 pendingCount 检测，保留兼容
                        $haltedForApproval = true;
                        $this->emitAdminAiOpsSseSyntheticToolDoneForPendingApproval($e);
                        $freshRun = AdminAiOpsRun::query()
                            ->whereKey($runId)
                            ->with(['steps', 'attachments', 'aiModel'])
                            ->first();

                        if ($freshRun instanceof AdminAiOpsRun && app()->bound(AdminAiOpsStreamContext::class)) {
                            $snapshot = $this->planStreamSnapshotWithTimeline($freshRun);
                            $runs->updateRun($freshRun, [
                                'status' => 'awaiting_confirmation',
                                'plan_stream_snapshot' => $snapshot,
                            ]);
                            $freshRun = $freshRun->fresh() ?? $freshRun;
                        }

                        $toolCallId = app()->bound(AdminAiOpsStreamContext::class)
                            ? trim((string) app(AdminAiOpsStreamContext::class)->lastToolCallId)
                            : '';
                        $this->writeAdminAiOpsSseJsonEvent('approval_required', [
                            'approval_id' => $e->approvalId,
                            'tool_call_id' => $toolCallId,
                            'tool_name' => $e->toolName,
                            'summary' => $e->summary,
                            'expires_at' => $e->expiresAtIso8601,
                            'fingerprint' => $e->fingerprint,
                        ]);

                        $this->writeAdminAiOpsSseRunEvent($runs->payload($freshRun instanceof AdminAiOpsRun ? $freshRun : $run));
                    } catch (Throwable $e) {
                        // normalizeApiException：将 Provider HTTP 错误转为管理员可读中文
                        $human = OpenAiRuntimeProvider::normalizeApiException($e, $providerUrl);
                        $run = $runs->updateRun($run->fresh() ?? $run, [
                            'status' => 'failed',
                            'error_message' => $human !== '' ? $human : trim($e->getMessage()),
                            'plan_stream_snapshot' => $this->planStreamSnapshotWithTimeline($run->fresh() ?? $run),
                            'finished_at' => now(),
                        ]);
                    }
                } finally {
                    // 释放 StreamContext，避免污染下一次 HTTP 请求
                    app()->forgetInstance(AdminAiOpsStreamContext::class);
                }

                // 已挂起审批时上面已推送 run；否则推送 completed/failed 终态
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
     * POST 批准挂起工具：仅记录批准决定；原始 Laravel AI tool call 会继续执行并自动返回 role=tool。
     *
     * 调用：
     * - {@see findOwnedRun()} 校验 run 归属
     * - {@see AdminAiOpsToolApprovalService::assertCanDecide()} 校验审批可操作
     * - {@see AdminAiOpsToolApprovalService::approveDecision()} 标记 approved 并唤醒等待中的 tool call
     * - {@see AdminAiOpsRunService::payload()} 最新 run 快照（含 approval_pending、assistant_timeline）
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
        $out = $approvals->approveDecision($approval, (int) $admin->id, $runId);

        Log::info('admin_ai_ops_tool_approval_approve_http', [
            'run_id' => $runId,
            'approval_id' => $approvalId,
            'admin_id' => (int) $admin->id,
            'already_decided' => $out['already_decided'],
        ]);

        $run = AdminAiOpsRun::query()
            ->whereKey($runId)
            ->with(['steps', 'attachments', 'aiModel'])
            ->firstOrFail();

        return response()->json([
            'ok' => true,
            'waiting_for_tool_result' => $out['waiting_for_tool_result'],
            'next_approval' => null,
            'queue_remaining' => (int) ($out['queue_remaining'] ?? 0),
            'already_decided' => $out['already_decided'],
            'executed_this_request' => false,
            'run' => $runs->payload($run),
        ]);
    }

    /**
     * POST 拒绝挂起工具：仅标记 rejected；原始 Laravel AI tool call 会收到标准工具错误结果。
     *
     * 调用：{@see AdminAiOpsToolApprovalService::rejectDecision()} 落库 rejected + 持久化时间线 rejected 态。
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
        $out = $approvals->rejectDecision($approval, (int) $admin->id, $runId, $payload['reason'] ?? null);

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
            'waiting_for_tool_result' => $out['waiting_for_tool_result'],
            'next_approval' => null,
            'queue_remaining' => (int) ($out['queue_remaining'] ?? 0),
            'run' => $runs->payload($run),
        ]);
    }

    /**
     * Legacy 审批后续流 SSE：仅兼容旧 nonce；新审批链路不再签发 resume URL。
     *
     * ## 与 {@see stream} 的区别
     *
     * - 入口需 query nonce（{@see AdminAiOpsToolApprovalService::consumeResumeNonce()} 一次性 Cache pull）
     * - 不再读用户 input_text，而是用 {@see AdminAiOpsChatService::streamAssistantResumeAfterApproval()} 或 {@see AdminAiOpsChatService::streamAssistantResumeAfterReject()} 合成续跑上下文
     * - 续跑前若 pendingCount>0：拒绝续流，回到 awaiting_confirmation + approval_required（防御性守卫）
     * - 续跑后若模型又触发写工具 pending：同样 awaiting_confirmation，不标 completed
     *
     * ## 连接内步骤
     *
     * 1. 校验 nonce / approval 状态（approve 需 executed，reject 需 rejected）
     * 2. {@see emitAdminAiOpsSseToolDoneFromExecutedApproval()} / {@see emitAdminAiOpsSseToolRejectedFromApproval()} 同步工具卡片 SSE
     * 3. 流式续跑 → pending 检测 → completed 或 awaiting_confirmation
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
            // 续流与首轮 stream 共用 run 级锁，防止并发续跑
            $lock = Cache::lock('geoflow:admin_ai_ops_resume_stream:'.(int) $runId, $maxSeconds + 120);

            try {
                $lock->block($maxSeconds + 120);

                // consumeResumeNonce：Cache::pull 一次性 nonce，防重放
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

                // 队列未清空时不应续流（正常 approve HTTP 不会签发 nonce；此处防御误用/竞态）
                if ($approvals->pendingCountForRun((int) $runId) > 0) {
                    $run = $runs->updateRun($run->fresh() ?? $run, [
                        'status' => 'awaiting_confirmation',
                        'plan_stream_snapshot' => $this->planStreamSnapshotWithTimeline($run->fresh() ?? $run),
                    ]);
                    $this->emitAdminAiOpsFirstPendingApprovalRequired((int) $runId);
                    $this->writeAdminAiOpsSseRunEvent($runs->payload($run->fresh(['steps', 'attachments', 'aiModel']) ?? $run));
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
                $webSearchEnabled = $chat->runWebSearchEnabled($run);

                $streamContext = AdminAiOpsStreamContext::forRun(
                    (int) $run->id,
                    $adminId,
                    is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : null,
                );
                $streamContext->approvalRequiredEmitter = function () use ($runId, $runs): void {
                    $this->emitAdminAiOpsFirstPendingApprovalRequired((int) $runId);
                    $freshRun = AdminAiOpsRun::query()
                        ->whereKey($runId)
                        ->with(['steps', 'attachments', 'aiModel'])
                        ->first();
                    if ($freshRun instanceof AdminAiOpsRun) {
                        $this->writeAdminAiOpsSseRunEvent($runs->payload($freshRun));
                    }
                };
                app()->instance(AdminAiOpsStreamContext::class, $streamContext);
                try {
                    if ($decision === 'reject') {
                        // 续流开始前补发 rejected 工具卡片 SSE（HTTP reject 时可能未开 SSE）
                        $this->emitAdminAiOpsSseToolRejectedFromApproval($approval);
                    }

                    if ($decision === 'approve') {
                        // 补发 done 工具卡片；streamAssistantResumeAfterApproval 合成「partial + 全部已决定 tool 输出」续跑
                        $this->emitAdminAiOpsSseToolDoneFromExecutedApproval($approval);
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
                            $webSearchEnabled,
                        );
                    } else {
                        // streamAssistantResumeAfterReject：合成 is_error=true 语义，禁止模型假装写成功
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
                            $webSearchEnabled,
                        );
                    }

                    $assistantText = trim((string) $assistantText);
                    // 续跑轮若又产生写工具 pending，不得标 completed（同首轮 stream 逻辑）
                    $pendingCount = $approvals->pendingCountForRun((int) $run->id);
                    if ($pendingCount > 0) {
                        $snapshotRun = $run->fresh() ?? $run;
                        if (app()->bound(AdminAiOpsStreamContext::class)) {
                            $snapshot = $this->planStreamSnapshotWithTimeline($snapshotRun);
                            $partial = trim((string) app(AdminAiOpsStreamContext::class)->partialAssistantText);
                            if ($partial !== '') {
                                $snapshot['partial_assistant_text'] = $partial;
                            }
                            $snapshotRun = $runs->updateRun($snapshotRun, [
                                'status' => 'awaiting_confirmation',
                                'plan_stream_snapshot' => $snapshot,
                            ]);
                        }
                        $this->emitAdminAiOpsFirstPendingApprovalRequired((int) $run->id);
                        $run = $snapshotRun;
                    } elseif ($assistantText === '') {
                        throw new \RuntimeException('模型返回为空');
                    } else {
                        // mergeAiOpsPartialAssistantWithResumeSummary：合并中断前 partial 与续跑正文，避免刷新丢前半段
                        $snapshotRun = $run->fresh() ?? $run;
                        $mergedSummary = $this->mergeAiOpsPartialAssistantWithResumeSummary($snapshotRun, $assistantText);

                        $run = $runs->updateRun($snapshotRun, [
                            'status' => 'completed',
                            'result_summary' => $mergedSummary,
                            'plan_stream_snapshot' => $this->planStreamSnapshotWithTimeline($snapshotRun),
                            'finished_at' => now(),
                        ]);
                    }
                } catch (Throwable $e) {
                    $human = OpenAiRuntimeProvider::normalizeApiException($e, $providerUrl);
                    $run = $runs->updateRun($run->fresh() ?? $run, [
                        'status' => 'failed',
                        'error_message' => $human !== '' ? $human : trim($e->getMessage()),
                        'plan_stream_snapshot' => $this->planStreamSnapshotWithTimeline($run->fresh() ?? $run),
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
     * 合并「审批前 partial_assistant_text」与续跑正文为一条 result_summary。
     *
     * 用途：用户批准工具后续流时，模型只输出后半段；若不合并，刷新会话会丢失中断前的表格/说明。
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
     * 查询当前管理员拥有的 run（404 若非本人）。
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
     * 构造会话列表项（侧边栏一行：标题、更新时间、最新 run 摘要）。
     *
     * @return array<string, mixed>
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
     * 构造会话详情 JSON（含全部 runs 的 {@see AdminAiOpsRunService::payload()}）。
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
     * 校验 AI 运维 chat 请求中的 ai_model_id 必须为 active 的 chat 类型模型。
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
     * 页面与 chat 接口共用：拉取 active chat 模型下拉列表。
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
     * 旧路径兼容：createPendingAndThrow 中断流时，Laravel AI 不发 ToolResult，需补发 awaiting_approval SSE。
     *
     * 调用：{@see AdminAiOpsAssistantTimelineRecorder::markToolAwaitingApprovalByCallId()} 更新内存时间线。
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

        $preview = (string) __('admin.ai_ops.tool_pending_approval_result_preview');
        app(AdminAiOpsStreamContext::class)->timeline->markToolAwaitingApprovalByCallId($lastId, $preview);

        $this->writeAdminAiOpsSseJsonEvent('tool', [
            'phase' => 'awaiting_approval',
            'tool_call_id' => $lastId,
            'tool_name' => $e->toolName,
            'successful' => false,
            'error' => '',
            'result_preview' => $preview,
        ]);
    }

    /**
     * 续流 SSE 路径：将已 executed 的审批对应工具卡片标为 done 并推送 tool SSE。
     *
     * 调用：
     * - {@see resolveAdminAiOpsApprovalToolCallId()} 解析 tool_call_id
     * - {@see AdminAiOpsAssistantTimelineRecorder::recordToolDone()} 写内存时间线
     * - {@see adminAiOpsSseToolResultDataPreview()} / {@see adminAiOpsSseToolRawOutput()} 构造预览
     */
    private function emitAdminAiOpsSseToolDoneFromExecutedApproval(AdminAiOpsToolApproval $approval): void
    {
        if (! app()->bound(AdminAiOpsStreamContext::class)) {
            return;
        }

        $toolCallId = $this->resolveAdminAiOpsApprovalToolCallId($approval);
        if ($toolCallId === '') {
            return;
        }

        $output = trim((string) ($approval->executed_output ?? ''));
        $successful = true;
        $error = '';
        if ($output !== '') {
            $decoded = json_decode($output, true);
            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                $successful = (bool) $decoded['ok'];
                if (! $successful) {
                    $error = trim((string) ($decoded['error'] ?? '工具执行失败。'));
                }
            }
        }

        $resultPreview = $this->adminAiOpsSseToolResultDataPreview($output);
        $rawOutput = $this->adminAiOpsSseToolRawOutput($output);

        app(AdminAiOpsStreamContext::class)->timeline->recordToolDone(
            $toolCallId,
            (string) $approval->tool_name,
            $successful,
            $error,
            $resultPreview,
            null,
            $rawOutput,
        );

        $payload = [
            'phase' => 'done',
            'tool_call_id' => $toolCallId,
            'tool_name' => (string) $approval->tool_name,
            'successful' => $successful,
            'error' => $error,
            'result_preview' => $resultPreview,
        ];
        if ($rawOutput !== null && $rawOutput !== '') {
            $payload['raw_output'] = $rawOutput;
        }
        $this->writeAdminAiOpsSseJsonEvent('tool', $payload);

        $this->writeAdminAiOpsSseJsonEvent('stream_status', [
            'kind' => 'post_tool_model_round',
            'tool_name' => (string) $approval->tool_name,
            'successful' => $successful,
        ]);
    }

    /**
     * 推送 approval_required SSE：取队列首条 pending，供前端弹审批 Modal。
     *
     * 调用：
     * - {@see AdminAiOpsToolApprovalService::firstPendingForRun()} 按 created_at FIFO
     * - {@see AdminAiOpsToolApprovalService::formatApprovalPayload()} 含 queue_remaining
     */
    private function emitAdminAiOpsFirstPendingApprovalRequired(int $runId): void
    {
        $approvals = app(AdminAiOpsToolApprovalService::class);
        $pending = $approvals->firstPendingForRun($runId);
        if (! $pending instanceof AdminAiOpsToolApproval) {
            return;
        }

        $payload = $approvals->formatApprovalPayload($pending);
        $this->writeAdminAiOpsSseJsonEvent('approval_required', [
            'approval_id' => $payload['id'],
            'tool_call_id' => $payload['tool_call_id'],
            'tool_name' => $payload['tool_name'],
            'summary' => $payload['summary'],
            'expires_at' => $payload['expires_at'],
            'fingerprint' => $payload['fingerprint'],
            'queue_remaining' => $payload['queue_remaining'],
        ]);
    }

    /**
     * 从工具 JSON 返回体提取 approval_id（PendingWriteGuard 返回 pending_user_approval 时携带）。
     */
    private function adminAiOpsApprovalIdFromToolResult(mixed $result): string
    {
        if (is_string($result)) {
            $decoded = json_decode($result, true);
        } elseif (is_array($result)) {
            $decoded = $result;
        } else {
            return '';
        }

        if (! is_array($decoded)) {
            return '';
        }

        return trim((string) ($decoded['approval_id'] ?? ''));
    }

    /**
     * 判断 ToolResult 是否为「已挂起待审批」JSON（含 pending_user_approval: true）。
     */
    private function adminAiOpsToolResultIsPendingApproval(mixed $result): bool
    {
        if (is_string($result)) {
            $decoded = json_decode($result, true);

            return is_array($decoded) && ! empty($decoded['pending_user_approval']);
        }

        if (is_array($result)) {
            return ! empty($result['pending_user_approval']);
        }

        return false;
    }

    /**
     * 解析工具返回的标准失败 JSON，让 UI 与时间线展示真实失败态而不是误判为成功。
     *
     * @return array{error: string, message: string}|null
     */
    private function adminAiOpsToolResultFailure(mixed $result): ?array
    {
        if (is_string($result)) {
            $decoded = json_decode($result, true);
        } elseif (is_array($result)) {
            $decoded = $result;
        } else {
            return null;
        }

        if (! is_array($decoded) || ! array_key_exists('ok', $decoded) || (bool) $decoded['ok'] !== false) {
            return null;
        }

        return [
            'error' => trim((string) ($decoded['error'] ?? 'tool_failed')),
            'message' => trim((string) ($decoded['message'] ?? $decoded['error'] ?? '工具执行失败。')),
        ];
    }

    /**
     * 解析审批行对应的 tool_call_id（优先 approval 表，其次 StreamContext，最后 snapshot）。
     *
     * 并行 tool_call 时落库可能短暂不准，ToolResult 阶段会 {@see AdminAiOpsToolApprovalService::syncToolCallId()} 修正。
     */
    private function resolveAdminAiOpsApprovalToolCallId(AdminAiOpsToolApproval $approval): string
    {
        $fromApproval = trim((string) ($approval->tool_call_id ?? ''));
        if ($fromApproval !== '') {
            return $fromApproval;
        }

        if (app()->bound(AdminAiOpsStreamContext::class)) {
            $fromCtx = trim((string) app(AdminAiOpsStreamContext::class)->lastToolCallId);
            if ($fromCtx !== '') {
                return $fromCtx;
            }
        }

        $run = AdminAiOpsRun::query()->whereKey((int) $approval->run_id)->first();
        if (! $run instanceof AdminAiOpsRun) {
            return '';
        }

        $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];

        return trim((string) ($snapshot['last_tool_call_id'] ?? ''));
    }

    /**
     * 续流 reject 路径：将工具卡片标 rejected 并推送 tool SSE。
     *
     * 调用：{@see AdminAiOpsAssistantTimelineRecorder::markToolRejectedByCallId()}。
     */
    private function emitAdminAiOpsSseToolRejectedFromApproval(AdminAiOpsToolApproval $approval): void
    {
        if (! app()->bound(AdminAiOpsStreamContext::class)) {
            return;
        }

        $toolCallId = $this->resolveAdminAiOpsApprovalToolCallId($approval);
        if ($toolCallId === '') {
            return;
        }

        $ctx = app(AdminAiOpsStreamContext::class);
        $reason = trim((string) ($approval->rejection_reason ?? ''));
        if ($reason === '') {
            $reason = (string) __('admin.ai_ops.tool_rejected_default_reason');
        }

        $ctx->timeline->markToolRejectedByCallId($toolCallId, $reason);

        $this->writeAdminAiOpsSseJsonEvent('tool', [
            'phase' => 'rejected',
            'tool_call_id' => $toolCallId,
            'tool_name' => (string) $approval->tool_name,
            'successful' => false,
            'error' => $reason,
            'result_preview' => $reason,
        ]);
    }

    /**
     * Laravel AI 流事件 → 前端 SSE：StreamStart / ToolCall / ToolResult。
     *
     * ToolCall：{@see AdminAiOpsAssistantTimelineRecorder::recordToolCalling()} + phase=calling
     * ToolResult pending：{@see syncToolCallId()} + {@see markToolAwaitingApprovalByCallId()} + phase=awaiting_approval
     * ToolResult 正常：{@see recordToolDone()} + phase=done
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
                $ctx = app(AdminAiOpsStreamContext::class);
                $ctx->lastToolCallId = (string) $event->toolCall->id;
                $ctx->timeline->recordToolCalling(
                    (string) $event->toolCall->id,
                    (string) $event->toolCall->name,
                    $this->adminAiOpsSseEncodeJsonPreview($event->toolCall->arguments, 1200) ?? '',
                );
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
            $pendingApproval = $this->adminAiOpsToolResultIsPendingApproval($event->toolResult->result);
            $toolFailure = $this->adminAiOpsToolResultFailure($event->toolResult->result);
            $rejectedByUser = is_array($toolFailure) && ($toolFailure['error'] ?? '') === 'user_rejected';
            $previewMessage = (string) __('admin.ai_ops.tool_pending_approval_result_preview');
            $resultPreview = $pendingApproval
                ? $previewMessage
                : $this->adminAiOpsSseToolResultDataPreview($event->toolResult->result);
            $rawOutput = $pendingApproval ? null : $this->adminAiOpsSseToolRawOutput($event->toolResult->result);
            $successful = $toolFailure === null ? (bool) $event->successful : false;
            $errorText = $toolFailure['message'] ?? (string) ($event->error ?? '');
            if (app()->bound(AdminAiOpsStreamContext::class)) {
                $ctx = app(AdminAiOpsStreamContext::class);
                if ($pendingApproval) {
                    $approvalIdFromResult = $this->adminAiOpsApprovalIdFromToolResult($event->toolResult->result);
                    if ($approvalIdFromResult !== '') {
                        app(AdminAiOpsToolApprovalService::class)->syncToolCallId(
                            $approvalIdFromResult,
                            (string) $event->toolResult->id,
                        );
                    }
                    $ctx->timeline->markToolAwaitingApprovalByCallId(
                        (string) $event->toolResult->id,
                        $previewMessage,
                    );
                } elseif ($rejectedByUser) {
                    $ctx->timeline->markToolRejectedByCallId(
                        (string) $event->toolResult->id,
                        $errorText,
                    );
                } else {
                    $ctx->timeline->recordToolDone(
                        (string) $event->toolResult->id,
                        (string) $event->toolResult->name,
                        $successful,
                        $errorText,
                        $resultPreview,
                        null,
                        $rawOutput,
                    );
                }
            }
            $payload = [
                'phase' => $pendingApproval ? 'awaiting_approval' : ($rejectedByUser ? 'rejected' : 'done'),
                'tool_call_id' => $event->toolResult->id,
                'tool_name' => $event->toolResult->name,
                'successful' => $pendingApproval ? false : $successful,
                'error' => $pendingApproval ? '' : $errorText,
                'result_preview' => $resultPreview,
            ];
            if ($rawOutput !== null && $rawOutput !== '') {
                $payload['raw_output'] = $rawOutput;
            }
            $this->writeAdminAiOpsSseJsonEvent('tool', $payload);
            if (! $pendingApproval) {
                $this->writeAdminAiOpsSseJsonEvent('stream_status', [
                    'kind' => 'post_tool_model_round',
                    'tool_name' => $event->toolResult->name,
                    'successful' => $event->successful,
                ]);
            }

            return;
        }
    }

    /**
     * 工具返回体 → SSE result_preview 字符串（截断至约 2400 字节，防撑爆前端）。
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

        $text = AdminAiOpsUtf8::sanitizeString(trim($text));
        if ($text === '') {
            return null;
        }

        $maxBytes = 2400;
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return AdminAiOpsUtf8::sanitizeString(substr($text, 0, $maxBytes).'…');
    }

    /**
     * 从工具 JSON 提取 stdout/stderr 或 raw_output，供前端「原始输出」折叠面板（Shell 类工具）。
     */
    private function adminAiOpsSseToolRawOutput(mixed $result): ?string
    {
        $decoded = $this->adminAiOpsSseNormalizeToolResult($result);
        if ($decoded === null) {
            return null;
        }

        if (isset($decoded['raw_output']) && is_string($decoded['raw_output'])) {
            $raw = trim($decoded['raw_output']);
        } else {
            $stdout = isset($decoded['stdout']) && is_string($decoded['stdout']) ? $decoded['stdout'] : '';
            $stderr = isset($decoded['stderr']) && is_string($decoded['stderr']) ? $decoded['stderr'] : '';
            $raw = trim($stdout."\n".$stderr);
        }

        if ($raw === '') {
            return null;
        }

        $raw = AdminAiOpsUtf8::sanitizeString($raw);
        $maxBytes = max(8192, (int) config('geoflow.admin_ai_ops_sse_raw_output_max_bytes', 65536));
        if (strlen($raw) <= $maxBytes) {
            return $raw;
        }

        return AdminAiOpsUtf8::sanitizeString(substr($raw, 0, $maxBytes).'…');
    }

    /**
     * 将工具返回规范化为关联数组（仅 JSON 对象字符串）。
     *
     * @return array<string, mixed>|null
     */
    private function adminAiOpsSseNormalizeToolResult(mixed $result): ?array
    {
        if (is_array($result)) {
            return $result;
        }
        if (! is_string($result)) {
            return null;
        }
        $trim = trim($result);
        if ($trim === '' || $trim[0] !== '{') {
            return null;
        }
        $decoded = json_decode($trim, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * 工具参数/结果 JSON 编码并截断，用于 SSE arguments_preview / result_preview。
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
     * 刷出 PHP 输出缓冲，使 SSE  chunk 立即到达浏览器（配合 X-Accel-Buffering: no）。
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
     * 写入命名 SSE 事件（event: xxx + data: JSON），并 {@see flushAdminAiOpsSseOutput()}。
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
     * 推送 run 快照（{@see AdminAiOpsRunService::payload()} 结果包装为 event: run）。
     *
     * @param  array<string, mixed>  $runPayload
     */
    private function writeAdminAiOpsSseRunEvent(array $runPayload): void
    {
        $this->writeAdminAiOpsSseJsonEvent('run', ['run' => $runPayload]);
    }

    /**
     * 写入 event: done，通知前端 onAiOpsSseFinished / 关闭 EventSource。
     */
    private function writeAdminAiOpsSseDoneEvent(): void
    {
        echo "event: done\n";
        echo "data: {}\n\n";
        $this->flushAdminAiOpsSseOutput();
    }

    /**
     * 将 StreamContext 内 assistant_timeline 合并进 plan_stream_snapshot（保留 web_search_enabled 等字段）。
     *
     * 用于 run 终态落库、续流挂起时持久化工具卡片，刷新页面可还原。
     *
     * @return array<string, mixed>
     */
    private function planStreamSnapshotWithTimeline(AdminAiOpsRun $run): array
    {
        $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
        if (app()->bound(AdminAiOpsStreamContext::class)) {
            $ctx = app(AdminAiOpsStreamContext::class);
            $snapshot['assistant_timeline'] = $ctx->timeline->toArray();
            if ($ctx->llmTranscript instanceof AdminAiOpsLlmTranscriptRecorder) {
                $snapshot['llm_messages'] = $ctx->llmTranscript->toArray();
            }
        }

        return $snapshot;
    }
}
