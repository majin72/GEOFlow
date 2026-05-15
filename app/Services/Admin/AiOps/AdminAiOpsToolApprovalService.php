<?php

namespace App\Services\Admin\AiOps;

use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsToolApproval;
use App\Services\Admin\AdminOps\AdminOpsAdminActionService;
use App\Services\Admin\AdminOps\AdminOpsSiteWriteService;
use App\Services\Admin\AiOps\Exceptions\AdminAiOpsToolApprovalPendingException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * AI 运维高风险工具挂起审批：落库 pending、批准时执行已存参数、拒绝时仅标记并交由续跑合成 tool_result 语义。
 */
class AdminAiOpsToolApprovalService
{
    public function __construct(
        private readonly AdminOpsAdminActionService $adminOpsAdminAction,
        private readonly AdminOpsSiteWriteService $siteWrite,
        private readonly AdminAiOpsRunService $runService,
    ) {}

    /**
     * 创建一条待审批记录、更新 run 快照与状态，并抛出 {@see AdminAiOpsToolApprovalPendingException} 以中断首轮模型流。
     *
     * @param  array<string, mixed>  $normalizedArguments  已解析的工具参数（不含敏感原始 JSON 字符串）
     *
     * @throws AdminAiOpsToolApprovalPendingException
     */
    public function createPendingAndThrow(string $toolName, array $normalizedArguments, string $riskLabel): void
    {
        if (! app()->bound(AdminAiOpsStreamContext::class)) {
            throw new RuntimeException('缺少 AI 运维流式上下文，无法挂起工具审批。');
        }

        /** @var AdminAiOpsStreamContext $ctx */
        $ctx = app(AdminAiOpsStreamContext::class);

        $ttl = max(60, (int) config('geoflow.admin_ai_ops_tool_approval.ttl_seconds', 900));

        $encoded = json_encode($normalizedArguments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', $encoded);

        $approvalId = (string) Str::uuid();
        $expiresAt = now()->addSeconds($ttl);

        DB::transaction(function () use ($approvalId, $ctx, $toolName, $encoded, $fingerprint, $riskLabel, $expiresAt): void {
            $run = AdminAiOpsRun::query()->whereKey($ctx->runId)->lockForUpdate()->first();
            if (! $run instanceof AdminAiOpsRun) {
                throw new RuntimeException('执行轮次不存在。');
            }

            $partial = (string) ($ctx->partialAssistantText ?? '');
            $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
            $snapshot['partial_assistant_text'] = $partial;
            $snapshot['pending_approval_id'] = $approvalId;
            $snapshot['original_user_message'] = trim((string) ($run->input_text ?? ''));

            AdminAiOpsToolApproval::query()->create([
                'id' => $approvalId,
                'run_id' => (int) $run->id,
                'admin_id' => $ctx->adminId,
                'tool_name' => $toolName,
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

        $summary = '需确认的写操作：'.Str::limit($riskLabel, 120, '…');

        Log::info('admin_ai_ops_tool_approval_pending', [
            'approval_id' => $approvalId,
            'run_id' => $ctx->runId,
            'admin_id' => $ctx->adminId,
            'tool_name' => $toolName,
            'fingerprint' => $fingerprint,
            'risk_label' => $riskLabel,
        ]);

        throw new AdminAiOpsToolApprovalPendingException(
            approvalId: $approvalId,
            toolName: $toolName,
            summary: $summary,
            fingerprint: $fingerprint,
            expiresAtIso8601: $expiresAt->toIso8601String(),
        );
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
            ->orderByDesc('id')
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
                return ['resume_stream_url' => null, 'already_executed' => true, 'executed_this_request' => false];
            }

            return [
                'resume_stream_url' => $this->issueResumeUrl($approval, $adminId, 'approve'),
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
                $this->runService->updateRun($run, [
                    'status' => 'processing',
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
        }

        $url = $this->issueResumeUrl($approval, $adminId, 'approve');

        return [
            'resume_stream_url' => $url,
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

            return ['reject_resume_stream_url' => $this->issueResumeUrl($approval, $adminId, 'reject')];
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
                $this->runService->updateRun($run, [
                    'status' => 'processing',
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

        return ['reject_resume_stream_url' => $this->issueResumeUrl($approval, $adminId, 'reject')];
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

        if ($approval->tool_name === 'AdminOpsAdminActionTool') {
            $kind = strtolower(trim((string) ($decoded['kind'] ?? '')));
            $op = strtolower(trim((string) ($decoded['op'] ?? '')));
            $payload = $decoded['payload'] ?? [];
            if (! is_array($payload)) {
                $payload = [];
            }

            try {
                $result = $this->adminOpsAdminAction->execute($kind, $op, $payload);
            } catch (Throwable $e) {
                return json_encode([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
            }

            return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        }

        if ($approval->tool_name === 'AdminOpsSitePatchBasicsTool') {
            $patch = $decoded['patch'] ?? [];
            if (! is_array($patch)) {
                return json_encode(['ok' => false, 'error' => 'patch 参数损坏。'], JSON_UNESCAPED_UNICODE) ?: '{}';
            }

            try {
                $result = $this->siteWrite->patchBasics($patch);
            } catch (Throwable $e) {
                return json_encode([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
            }

            return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        }

        throw new RuntimeException('暂不支持的审批工具：'.$approval->tool_name);
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
     * 校验审批属于指定 run。
     */
    private function assertSameRun(AdminAiOpsToolApproval $approval, int $runId): void
    {
        if ((int) $approval->run_id !== $runId) {
            abort(404, '审批与执行轮次不匹配。');
        }
    }
}
