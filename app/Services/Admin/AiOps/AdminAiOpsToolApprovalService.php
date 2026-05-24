<?php

namespace App\Services\Admin\AiOps;

use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsToolApproval;
use App\Services\Admin\AiOps\Exceptions\AdminAiOpsToolApprovalPendingException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AI 运维高风险工具审批：落库 pending，HTTP 仅记录审批决定；真实执行发生在原始 Laravel AI tool call 内。
 */
class AdminAiOpsToolApprovalService
{
    public function __construct(
        private readonly AdminAiOpsApprovedToolExecutor $approvedToolExecutor,
        private readonly AdminAiOpsRunService $runService,
    ) {}

    /**
     * 创建 pending 记录但不中断 Agent 流（同轮可累积多条，轮次结束后统一弹出首条审批）。
     *
     * @param  array<string, mixed>  $normalizedArguments
     * @return array{approval_id: string, tool_name: string, summary: string, fingerprint: string, expires_at: string}
     */
    public function createPendingWithoutThrow(string $toolName, array $normalizedArguments, string $riskLabel): array
    {
        if (! app()->bound(AdminAiOpsStreamContext::class)) {
            throw new RuntimeException('缺少 AI 运维流式上下文，无法挂起工具审批。');
        }

        /** @var AdminAiOpsStreamContext $ctx */
        $ctx = app(AdminAiOpsStreamContext::class);

        $meta = $this->insertPendingRecord(
            runId: $ctx->runId,
            adminId: $ctx->adminId,
            toolName: $toolName,
            normalizedArguments: $normalizedArguments,
            riskLabel: $riskLabel,
            partialAssistantText: (string) ($ctx->partialAssistantText ?? ''),
            toolCallId: trim((string) $ctx->lastToolCallId),
        );

        Log::info('admin_ai_ops_tool_approval_pending', [
            'approval_id' => $meta['approval_id'],
            'run_id' => $ctx->runId,
            'admin_id' => $ctx->adminId,
            'tool_name' => $toolName,
            'fingerprint' => $meta['fingerprint'],
            'risk_label' => $riskLabel,
        ]);

        return $meta;
    }

    /**
     * 创建一条待审批记录、更新 run 快照与状态，并抛出 {@see AdminAiOpsToolApprovalPendingException} 以中断首轮模型流。
     *
     * @param  array<string, mixed>  $normalizedArguments
     *
     * @throws AdminAiOpsToolApprovalPendingException
     */
    public function createPendingAndThrow(string $toolName, array $normalizedArguments, string $riskLabel): void
    {
        $meta = $this->createPendingWithoutThrow($toolName, $normalizedArguments, $riskLabel);

        throw new AdminAiOpsToolApprovalPendingException(
            approvalId: $meta['approval_id'],
            toolName: $toolName,
            summary: $meta['summary'],
            fingerprint: $meta['fingerprint'],
            expiresAtIso8601: $meta['expires_at'],
        );
    }

    /**
     * 按创建顺序返回 run 下第一条 pending 审批。
     */
    public function firstPendingForRun(int $runId): ?AdminAiOpsToolApproval
    {
        return $this->pendingQueryForRun($runId)->first();
    }

    /**
     * 将 pending 审批与 SSE/时间线中的 tool_call_id 对齐（并行 tool_call 时 lastToolCallId 可能不准确）。
     */
    public function syncToolCallId(string $approvalId, string $toolCallId): void
    {
        $approvalId = trim($approvalId);
        $toolCallId = trim($toolCallId);
        if ($approvalId === '' || $toolCallId === '') {
            return;
        }

        AdminAiOpsToolApproval::query()
            ->whereKey($approvalId)
            ->where('status', 'pending')
            ->where(function ($query) use ($toolCallId): void {
                $query->whereNull('tool_call_id')
                    ->orWhere('tool_call_id', '')
                    ->orWhere('tool_call_id', '!=', $toolCallId);
            })
            ->update(['tool_call_id' => $toolCallId]);
    }

    /**
     * 统计 run 下仍待处理的审批数量。
     */
    public function pendingCountForRun(int $runId): int
    {
        return (int) AdminAiOpsToolApproval::query()
            ->where('run_id', $runId)
            ->where('status', 'pending')
            ->count();
    }

    /**
     * 构造前端 Modal / SSE 使用的审批摘要。
     *
     * @return array{id: string, tool_name: string, tool_call_id: string, summary: string, expires_at: string|null, fingerprint: string, queue_remaining: int}
     */
    public function formatApprovalPayload(AdminAiOpsToolApproval $approval): array
    {
        $runId = (int) $approval->run_id;
        $snapshot = [];
        $run = AdminAiOpsRun::query()->whereKey($runId)->first(['plan_stream_snapshot']);
        if ($run instanceof AdminAiOpsRun) {
            $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
        }

        return [
            'id' => (string) $approval->id,
            'tool_name' => (string) $approval->tool_name,
            'tool_call_id' => trim((string) ($approval->tool_call_id ?? $snapshot['last_tool_call_id'] ?? '')),
            'summary' => $this->approvalSummaryLine($approval),
            'expires_at' => $approval->expires_at?->toIso8601String(),
            'fingerprint' => (string) $approval->args_fingerprint,
            'queue_remaining' => $this->pendingCountForRun($runId),
        ];
    }

    /**
     * @return list<AdminAiOpsToolApproval>
     */
    public function decidedApprovalsForRun(int $runId): array
    {
        return AdminAiOpsToolApproval::query()
            ->where('run_id', $runId)
            ->whereIn('status', ['executed', 'rejected'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * 落库 pending 行并将 run 置为 awaiting_confirmation。
     *
     * @param  array<string, mixed>  $normalizedArguments
     * @return array{approval_id: string, tool_name: string, summary: string, fingerprint: string, expires_at: string}
     */
    private function insertPendingRecord(
        int $runId,
        int $adminId,
        string $toolName,
        array $normalizedArguments,
        string $riskLabel,
        string $partialAssistantText,
        string $toolCallId,
    ): array {
        $ttl = max(60, (int) config('geoflow.admin_ai_ops_tool_approval.ttl_seconds', 900));
        $encoded = json_encode($normalizedArguments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', $encoded);
        $approvalId = (string) Str::uuid();
        $expiresAt = now()->addSeconds($ttl);
        $summary = '需确认的写操作：'.Str::limit($riskLabel, 120, '…');

        DB::transaction(function () use ($approvalId, $runId, $adminId, $toolName, $encoded, $fingerprint, $riskLabel, $expiresAt, $partialAssistantText, $toolCallId): void {
            $run = AdminAiOpsRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run instanceof AdminAiOpsRun) {
                throw new RuntimeException('执行轮次不存在。');
            }

            $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
            $snapshot['partial_assistant_text'] = $partialAssistantText;
            if (! array_key_exists('pending_approval_id', $snapshot)) {
                $snapshot['pending_approval_id'] = $approvalId;
            }
            $snapshot['original_user_message'] = trim((string) ($run->input_text ?? ''));
            if ($toolCallId !== '') {
                $snapshot['last_tool_call_id'] = $toolCallId;
            }

            AdminAiOpsToolApproval::query()->create([
                'id' => $approvalId,
                'run_id' => $runId,
                'admin_id' => $adminId,
                'tool_name' => $toolName,
                'tool_call_id' => $toolCallId !== '' ? $toolCallId : null,
                'arguments_json' => $encoded,
                'args_fingerprint' => $fingerprint,
                'risk_label' => Str::limit($riskLabel, 160, ''),
                'status' => 'pending',
                'expires_at' => $expiresAt,
            ]);

            $this->runService->updateRun($run, [
                'status' => 'awaiting_confirmation',
                'plan_stream_snapshot' => $snapshot,
            ]);
        });

        return [
            'approval_id' => $approvalId,
            'tool_name' => $toolName,
            'summary' => $summary,
            'fingerprint' => $fingerprint,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * 供 Modal 展示的一行摘要。
     */
    private function approvalSummaryLine(AdminAiOpsToolApproval $row): string
    {
        $label = trim((string) ($row->risk_label ?? ''));
        if ($label !== '') {
            return '需确认的写操作：'.Str::limit($label, 120, '…');
        }

        return '需确认的写操作：'.Str::limit((string) $row->tool_name, 120, '…');
    }

    /**
     * 若 pending 已过期则标记 expired，并将仍处等待确认的 run 置为 failed。
     */
    public function expirePendingIfStale(AdminAiOpsRun $run): void
    {
        if ((string) $run->status !== 'awaiting_confirmation') {
            return;
        }

        $approval = AdminAiOpsToolApproval::query()
            ->where('run_id', (int) $run->id)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if (! $approval instanceof AdminAiOpsToolApproval) {
            return;
        }

        if ($approval->expires_at->isFuture()) {
            return;
        }

        DB::transaction(function () use ($run, $approval): void {
            $approval->forceFill([
                'status' => 'expired',
                'decided_at' => now(),
            ])->save();

            $fresh = AdminAiOpsRun::query()->whereKey((int) $run->id)->lockForUpdate()->first();
            if ($fresh instanceof AdminAiOpsRun && (string) $fresh->status === 'awaiting_confirmation') {
                $this->runService->updateRun($fresh, [
                    'status' => 'failed',
                    'error_message' => '工具审批已超时，请重新发起对话。',
                    'finished_at' => now(),
                ]);
            }
        });
    }

    /**
     * 校验审批归属与状态。
     */
    public function assertCanDecide(string $approvalId, int $adminId): AdminAiOpsToolApproval
    {
        $approval = AdminAiOpsToolApproval::query()->whereKey($approvalId)->first();
        if (! $approval instanceof AdminAiOpsToolApproval) {
            abort(404, '审批记录不存在。');
        }
        if ((int) $approval->admin_id !== $adminId) {
            abort(403, '无权处理该审批。');
        }

        return $approval;
    }

    /**
     * 记录批准决定，不执行工具；等待中的原始 tool call 会读取 approved 后继续执行。
     *
     * @return array{waiting_for_tool_result: bool, queue_remaining: int, already_decided: bool}
     */
    public function approveDecision(AdminAiOpsToolApproval $approval, int $adminId, int $runId): array
    {
        $this->assertSameRun($approval, $runId);

        $alreadyDecided = false;
        DB::transaction(function () use ($approval, &$alreadyDecided): void {
            $locked = AdminAiOpsToolApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
            if (! $locked instanceof AdminAiOpsToolApproval) {
                return;
            }

            if (in_array((string) $locked->status, ['approved', 'executed'], true)) {
                $alreadyDecided = true;

                return;
            }

            if ((string) $locked->status !== 'pending') {
                abort(409, '该审批已不可用（状态：'.$locked->status.'）。');
            }

            if ($locked->expires_at->isPast()) {
                $locked->forceFill(['status' => 'expired', 'decided_at' => now()])->save();
                abort(422, '审批已过期。');
            }

            $locked->forceFill([
                'status' => 'approved',
                'decided_at' => now(),
            ])->save();

            $this->markRunProcessing((int) $locked->run_id);
        });

        Log::info('admin_ai_ops_tool_approval_approved', [
            'approval_id' => $approval->id,
            'run_id' => $runId,
            'admin_id' => $adminId,
            'tool_name' => $approval->tool_name,
            'already_decided' => $alreadyDecided,
        ]);

        return [
            'waiting_for_tool_result' => true,
            'queue_remaining' => $this->pendingCountForRun($runId),
            'already_decided' => $alreadyDecided,
        ];
    }

    /**
     * 记录拒绝决定；等待中的原始 tool call 会以标准 tool error JSON 返回模型。
     *
     * @return array{waiting_for_tool_result: bool, queue_remaining: int, already_decided: bool}
     */
    public function rejectDecision(AdminAiOpsToolApproval $approval, int $adminId, int $runId, ?string $reason): array
    {
        $this->assertSameRun($approval, $runId);

        $reason = trim((string) $reason);
        if ($reason === '') {
            $reason = 'denied by user approval prompt';
        }

        $alreadyDecided = false;
        DB::transaction(function () use ($approval, $reason, &$alreadyDecided): void {
            $locked = AdminAiOpsToolApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
            if (! $locked instanceof AdminAiOpsToolApproval) {
                return;
            }

            if ((string) $locked->status === 'rejected') {
                $alreadyDecided = true;

                return;
            }

            if ((string) $locked->status !== 'pending') {
                abort(409, '该审批已不可用（状态：'.$locked->status.'）。');
            }

            if ($locked->expires_at->isPast()) {
                $locked->forceFill(['status' => 'expired', 'decided_at' => now()])->save();
                abort(422, '审批已过期。');
            }

            $locked->forceFill([
                'status' => 'rejected',
                'decided_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->markRunProcessing((int) $locked->run_id);
        });

        $fresh = $approval->fresh() ?? $approval;
        $this->persistApprovalDecisionOnTimeline($fresh, 'rejected');

        Log::info('admin_ai_ops_tool_approval_rejected', [
            'approval_id' => $approval->id,
            'run_id' => $runId,
            'admin_id' => $adminId,
            'tool_name' => $approval->tool_name,
            'fingerprint' => $approval->args_fingerprint,
            'already_decided' => $alreadyDecided,
        ]);

        return [
            'waiting_for_tool_result' => true,
            'queue_remaining' => $this->pendingCountForRun($runId),
            'already_decided' => $alreadyDecided,
        ];
    }

    /**
     * 原始 tool call 执行完成后写入真实输出，并持久化工具卡片 done 态。
     */
    public function markToolCallExecuted(AdminAiOpsToolApproval $approval, string $output): void
    {
        DB::transaction(function () use ($approval, $output): void {
            $locked = AdminAiOpsToolApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
            if (! $locked instanceof AdminAiOpsToolApproval) {
                return;
            }

            if ((string) $locked->status === 'executed') {
                return;
            }

            if (! in_array((string) $locked->status, ['approved', 'pending'], true)) {
                return;
            }

            $locked->forceFill([
                'status' => 'executed',
                'executed_output' => $output,
            ])->save();

            $this->markRunProcessing((int) $locked->run_id);
        });

        $fresh = $approval->fresh() ?? $approval;
        $this->persistApprovalDecisionOnTimeline($fresh, 'executed');

        Log::info('admin_ai_ops_tool_approval_executed_in_tool_call', [
            'approval_id' => $approval->id,
            'run_id' => $approval->run_id,
            'tool_name' => $approval->tool_name,
            'fingerprint' => $approval->args_fingerprint,
        ]);
    }

    /**
     * 原始 tool call 等待超时或审批过期时，仅标记审批过期，让模型收到标准 tool error 后继续收束。
     */
    public function expireApprovalForToolWait(AdminAiOpsToolApproval $approval): void
    {
        DB::transaction(function () use ($approval): void {
            $locked = AdminAiOpsToolApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
            if (! $locked instanceof AdminAiOpsToolApproval) {
                return;
            }

            if (! in_array((string) $locked->status, ['pending', 'approved'], true)) {
                return;
            }

            $locked->forceFill([
                'status' => 'expired',
                'decided_at' => $locked->decided_at ?? now(),
            ])->save();

            $this->markRunProcessing((int) $locked->run_id);
        });
    }

    /**
     * 批准：在事务内原子将 pending 标为可执行状态、执行工具、写入输出并将 run 置为 processing 等待续流。
     *
     * @return array{resume_stream_url: string|null, already_executed: bool, executed_this_request: bool}
     */
    public function approveAndPrepareResume(AdminAiOpsToolApproval $approval, int $adminId, int $runId): array
    {
        $this->assertSameRun($approval, $runId);

        if ($approval->status === 'executed') {
            $run = AdminAiOpsRun::query()->whereKey($runId)->first();
            if (! $run instanceof AdminAiOpsRun) {
                abort(404, '执行轮次不存在。');
            }
            if ((string) $run->status === 'completed') {
                return [
                    'resume_stream_url' => null,
                    'next_approval' => null,
                    'queue_remaining' => 0,
                    'already_executed' => true,
                    'executed_this_request' => false,
                ];
            }

            $next = $this->firstPendingForRun($runId);

            return [
                'resume_stream_url' => $next === null ? $this->issueResumeUrl($approval, $adminId, 'approve') : null,
                'next_approval' => $next instanceof AdminAiOpsToolApproval ? $this->formatApprovalPayload($next) : null,
                'queue_remaining' => $this->pendingCountForRun($runId),
                'already_executed' => true,
                'executed_this_request' => false,
            ];
        }

        if ($approval->status !== 'pending') {
            abort(409, '该审批已不可用（状态：'.$approval->status.'）。');
        }

        if ($approval->expires_at->isPast()) {
            $this->expirePendingRowAndFailRun($approval);

            abort(422, '审批已过期。');
        }

        $executedThisRequest = false;
        $output = '';

        DB::transaction(function () use ($approval, &$output, &$executedThisRequest): void {
            $locked = AdminAiOpsToolApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
            if (! $locked instanceof AdminAiOpsToolApproval || $locked->status !== 'pending') {
                return;
            }
            if ($locked->expires_at->isPast()) {
                $locked->forceFill(['status' => 'expired', 'decided_at' => now()])->save();
                $runExpired = AdminAiOpsRun::query()->whereKey((int) $locked->run_id)->lockForUpdate()->first();
                if ($runExpired instanceof AdminAiOpsRun && (string) $runExpired->status === 'awaiting_confirmation') {
                    $this->runService->updateRun($runExpired, [
                        'status' => 'failed',
                        'error_message' => '工具审批已超时，请重新发起对话。',
                        'finished_at' => now(),
                    ]);
                }

                return;
            }

            $output = $this->executeStoredToolUnsafe($locked);
            $locked->forceFill([
                'status' => 'executed',
                'decided_at' => now(),
                'executed_output' => $output,
            ])->save();

            $run = AdminAiOpsRun::query()->whereKey((int) $locked->run_id)->lockForUpdate()->first();
            if ($run instanceof AdminAiOpsRun) {
                $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
                $snapshot['resume_decision'] = 'approve';
                $snapshot['tool_output_text'] = $output;
                $nextPending = $this->firstPendingForRun((int) $run->id);
                $this->runService->updateRun($run, [
                    'status' => $nextPending instanceof AdminAiOpsToolApproval ? 'awaiting_confirmation' : 'processing',
                    'plan_stream_snapshot' => $snapshot,
                ]);
            }

            $executedThisRequest = true;
        });

        $approval->refresh();

        if ($approval->status === 'expired') {
            abort(422, '审批已过期。');
        }

        if ($approval->status !== 'executed') {
            abort(409, '审批处理冲突，请重试。');
        }

        if ($executedThisRequest) {
            Log::info('admin_ai_ops_tool_approval_executed', [
                'approval_id' => $approval->id,
                'run_id' => $runId,
                'admin_id' => $adminId,
                'tool_name' => $approval->tool_name,
                'fingerprint' => $approval->args_fingerprint,
            ]);
            $this->persistApprovalDecisionOnTimeline($approval->fresh() ?? $approval, 'executed');
        }

        $next = $this->firstPendingForRun($runId);

        return [
            'resume_stream_url' => $next === null ? $this->issueResumeUrl($approval, $adminId, 'approve') : null,
            'next_approval' => $next instanceof AdminAiOpsToolApproval ? $this->formatApprovalPayload($next) : null,
            'queue_remaining' => $this->pendingCountForRun($runId),
            'already_executed' => ! $executedThisRequest,
            'executed_this_request' => $executedThisRequest,
        ];
    }

    /**
     * 拒绝：不执行工具，仅标记 rejected 并准备拒绝续流。
     *
     * @return array{reject_resume_stream_url: string}
     */
    public function rejectAndPrepareResume(AdminAiOpsToolApproval $approval, int $adminId, int $runId, ?string $reason): array
    {
        $this->assertSameRun($approval, $runId);

        $reason = trim((string) $reason);
        if ($reason === '') {
            $reason = 'denied by user approval prompt';
        }

        if ($approval->status === 'rejected') {
            $run = AdminAiOpsRun::query()->whereKey($runId)->first();
            if ($run instanceof AdminAiOpsRun && in_array((string) $run->status, ['completed', 'failed'], true)) {
                abort(409, '该审批流程已结束。');
            }

            $next = $this->firstPendingForRun($runId);

            return [
                'reject_resume_stream_url' => $next === null ? $this->issueResumeUrl($approval, $adminId, 'reject') : null,
                'next_approval' => $next instanceof AdminAiOpsToolApproval ? $this->formatApprovalPayload($next) : null,
                'queue_remaining' => $this->pendingCountForRun($runId),
            ];
        }

        if ($approval->status !== 'pending') {
            abort(409, '该审批已不可用（状态：'.$approval->status.'）。');
        }

        if ($approval->expires_at->isPast()) {
            $this->expirePendingRowAndFailRun($approval);

            abort(422, '审批已过期。');
        }

        DB::transaction(function () use ($approval, $reason): void {
            $locked = AdminAiOpsToolApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
            if (! $locked instanceof AdminAiOpsToolApproval || $locked->status !== 'pending') {
                return;
            }
            if ($locked->expires_at->isPast()) {
                $locked->forceFill(['status' => 'expired', 'decided_at' => now()])->save();
                $runExpired = AdminAiOpsRun::query()->whereKey((int) $locked->run_id)->lockForUpdate()->first();
                if ($runExpired instanceof AdminAiOpsRun && (string) $runExpired->status === 'awaiting_confirmation') {
                    $this->runService->updateRun($runExpired, [
                        'status' => 'failed',
                        'error_message' => '工具审批已超时，请重新发起对话。',
                        'finished_at' => now(),
                    ]);
                }

                return;
            }

            $locked->forceFill([
                'status' => 'rejected',
                'decided_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $run = AdminAiOpsRun::query()->whereKey((int) $locked->run_id)->lockForUpdate()->first();
            if ($run instanceof AdminAiOpsRun) {
                $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
                $snapshot['resume_decision'] = 'reject';
                $snapshot['reject_reason'] = $reason;
                $nextPending = $this->firstPendingForRun((int) $run->id);
                $this->runService->updateRun($run, [
                    'status' => $nextPending instanceof AdminAiOpsToolApproval ? 'awaiting_confirmation' : 'processing',
                    'plan_stream_snapshot' => $snapshot,
                ]);
            }
        });

        $approval->refresh();

        if ($approval->status === 'expired') {
            abort(422, '审批已过期。');
        }

        if ($approval->status !== 'rejected') {
            abort(409, '审批处理冲突，请重试。');
        }

        Log::info('admin_ai_ops_tool_approval_rejected', [
            'approval_id' => $approval->id,
            'run_id' => $runId,
            'admin_id' => $adminId,
            'tool_name' => $approval->tool_name,
            'fingerprint' => $approval->args_fingerprint,
        ]);

        $this->persistApprovalDecisionOnTimeline($approval->fresh() ?? $approval, 'rejected');

        return [
            'reject_resume_stream_url' => $this->firstPendingForRun($runId) === null
                ? $this->issueResumeUrl($approval, $adminId, 'reject')
                : null,
            'next_approval' => ($next = $this->firstPendingForRun($runId)) instanceof AdminAiOpsToolApproval
                ? $this->formatApprovalPayload($next)
                : null,
            'queue_remaining' => $this->pendingCountForRun($runId),
        ];
    }

    /**
     * 消费一次性 nonce 并返回缓存负载（失败返回 null）。
     *
     * @return array{run_id:int, approval_id:string, admin_id:int, decision:string}|null
     */
    public function consumeResumeNonce(string $nonce): ?array
    {
        $nonce = trim($nonce);
        if ($nonce === '') {
            return null;
        }

        $key = $this->resumeNonceCacheKey($nonce);

        /** @var array{run_id:int, approval_id:string, admin_id:int, decision:string}|null $payload */
        $payload = Cache::pull($key);

        return is_array($payload) ? $payload : null;
    }

    /**
     * 按工具名执行已落库的参数（仅服务端调用）。
     */
    public function executeStoredToolUnsafe(AdminAiOpsToolApproval $approval): string
    {
        $decoded = json_decode((string) $approval->arguments_json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('审批参数 JSON 损坏。');
        }

        return $this->approvedToolExecutor->execute((string) $approval->tool_name, $decoded);
    }

    /**
     * 生成 resume-stream 使用的一次性 nonce 并写入缓存。
     */
    private function issueResumeUrl(AdminAiOpsToolApproval $approval, int $adminId, string $decision): string
    {
        $nonce = Str::random(48);
        $ttl = max(120, (int) config('geoflow.admin_ai_ops_chat_stream_max_seconds', 900) + 120);

        Cache::put($this->resumeNonceCacheKey($nonce), [
            'run_id' => (int) $approval->run_id,
            'approval_id' => (string) $approval->id,
            'admin_id' => $adminId,
            'decision' => $decision,
        ], now()->addSeconds($ttl));

        return route('admin.ai-ops.runs.resume-stream', [
            'runId' => (int) $approval->run_id,
            'nonce' => $nonce,
        ]);
    }

    /**
     * 缓存键：一次性续跑 nonce。
     */
    private function resumeNonceCacheKey(string $nonce): string
    {
        return 'geoflow:admin_ai_ops_resume_nonce:'.hash('sha256', $nonce);
    }

    /**
     * HTTP 批准/拒绝后，将工具卡片终态写入 plan_stream_snapshot，避免刷新后从「已完成」回退为「待确认」。
     */
    private function persistApprovalDecisionOnTimeline(AdminAiOpsToolApproval $approval, string $decision): void
    {
        $run = AdminAiOpsRun::query()->whereKey((int) $approval->run_id)->first();
        if (! $run instanceof AdminAiOpsRun) {
            return;
        }

        $toolCallId = trim((string) ($approval->tool_call_id ?? ''));
        if ($toolCallId === '') {
            return;
        }

        $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
        $recorder = AdminAiOpsAssistantTimelineRecorder::fromSnapshot($snapshot);

        if ($decision === 'executed') {
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
            $preview = $output !== '' ? mb_substr($output, 0, 2400) : null;
            $recorder->recordToolDone(
                $toolCallId,
                (string) $approval->tool_name,
                $successful,
                $error,
                $preview,
            );
        } elseif ($decision === 'rejected') {
            $reason = trim((string) ($approval->rejection_reason ?? ''));
            if ($reason === '') {
                $reason = 'denied by user approval prompt';
            }
            $recorder->markToolRejectedByCallId($toolCallId, $reason);
        } else {
            return;
        }

        $this->runService->persistAssistantTimeline($run, $recorder->toArray());
    }

    /**
     * 将单条 pending 标为 expired，并在 run 仍处于 awaiting_confirmation 时置为 failed。
     */
    private function expirePendingRowAndFailRun(AdminAiOpsToolApproval $approval): void
    {
        DB::transaction(function () use ($approval): void {
            $locked = AdminAiOpsToolApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
            if (! $locked instanceof AdminAiOpsToolApproval || $locked->status !== 'pending') {
                return;
            }
            if ($locked->expires_at->isFuture()) {
                return;
            }

            $locked->forceFill([
                'status' => 'expired',
                'decided_at' => now(),
            ])->save();

            $run = AdminAiOpsRun::query()->whereKey((int) $locked->run_id)->lockForUpdate()->first();
            if ($run instanceof AdminAiOpsRun && (string) $run->status === 'awaiting_confirmation') {
                $this->runService->updateRun($run, [
                    'status' => 'failed',
                    'error_message' => '工具审批已超时，请重新发起对话。',
                    'finished_at' => now(),
                ]);
            }
        });
    }

    /**
     * 将 run 从等待确认恢复为 processing，表示原始 tool call 已继续推进。
     */
    private function markRunProcessing(int $runId): void
    {
        $run = AdminAiOpsRun::query()->whereKey($runId)->lockForUpdate()->first();
        if (! $run instanceof AdminAiOpsRun || (string) $run->status !== 'awaiting_confirmation') {
            return;
        }

        $this->runService->updateRun($run, [
            'status' => 'processing',
        ]);
    }

    /**
     * 校验审批属于指定 run。
     */
    private function assertSameRun(AdminAiOpsToolApproval $approval, int $runId): void
    {
        if ((int) $approval->run_id !== $runId) {
            abort(404, '审批与执行轮次不匹配。');
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<AdminAiOpsToolApproval>
     */
    private function pendingQueryForRun(int $runId): \Illuminate\Database\Eloquent\Builder
    {
        return AdminAiOpsToolApproval::query()
            ->where('run_id', $runId)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
