<?php

declare(strict_types=1);

namespace App\Services\Admin\AiOps;

use App\Models\AdminAiOpsToolApproval;
use RuntimeException;

/**
 * 在原始 Laravel AI tool call 内等待管理员审批，并把最终真实执行结果作为该 tool call 的返回值交还给 Prism。
 */
final class AdminAiOpsToolApprovalWaiter
{
    public function __construct(
        private readonly AdminAiOpsToolApprovalService $approvals,
    ) {}

    /**
     * 等待审批决定；批准后执行原始写操作，拒绝或超时则返回标准工具错误 JSON。
     *
     * @param  callable(): array<string, mixed>|string  $execute  原始工具写操作回调，必须只在 approved 后执行
     */
    public function waitForDecisionAndRun(string $approvalId, callable $execute): string
    {
        $deadline = microtime(true) + $this->waitTimeoutSeconds();
        $sleepMicros = 250_000;

        while (microtime(true) <= $deadline) {
            $approval = AdminAiOpsToolApproval::query()->whereKey($approvalId)->first();
            if (! $approval instanceof AdminAiOpsToolApproval) {
                return $this->encodeToolError('approval_not_found', '工具审批记录不存在。');
            }

            if ($approval->expires_at !== null && $approval->expires_at->isPast()) {
                $this->approvals->expireApprovalForToolWait($approval);

                return $this->encodeToolError('approval_expired', '工具审批已超时。');
            }

            $status = (string) $approval->status;
            if ($status === 'approved') {
                return $this->executeApprovedTool($approval, $execute);
            }

            if ($status === 'rejected') {
                $reason = trim((string) ($approval->rejection_reason ?? ''));

                return $this->encodeToolError(
                    'user_rejected',
                    $reason !== '' ? $reason : '管理员已拒绝该工具调用。'
                );
            }

            if (in_array($status, ['executed', 'expired'], true)) {
                $output = trim((string) ($approval->executed_output ?? ''));
                if ($status === 'executed' && $output !== '') {
                    return $output;
                }

                return $this->encodeToolError('approval_'.$status, '工具审批状态已变更：'.$status);
            }

            usleep($sleepMicros);
        }

        $approval = AdminAiOpsToolApproval::query()->whereKey($approvalId)->first();
        if ($approval instanceof AdminAiOpsToolApproval) {
            $this->approvals->expireApprovalForToolWait($approval);
        }

        return $this->encodeToolError('approval_timeout', '等待管理员审批超时。');
    }

    /**
     * 执行已批准的工具，并将输出写回审批行与时间线。
     *
     * @param  callable(): array<string, mixed>|string  $execute
     */
    private function executeApprovedTool(AdminAiOpsToolApproval $approval, callable $execute): string
    {
        try {
            $result = $execute();
            $output = is_string($result)
                ? $result
                : (json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
        } catch (\Throwable $e) {
            $output = $this->encodeToolError('tool_execution_failed', trim($e->getMessage()) ?: '工具执行失败。');
        }

        $this->approvals->markToolCallExecuted($approval, $output);

        return $output;
    }

    /**
     * 等待审批的最大秒数，不超过当前 SSE 连接可用时间。
     */
    private function waitTimeoutSeconds(): int
    {
        $streamMax = max(30, (int) config('geoflow.admin_ai_ops_chat_stream_max_seconds', 900) - 15);
        $ttl = max(30, (int) config('geoflow.admin_ai_ops_tool_approval.ttl_seconds', 900));

        return max(30, min($streamMax, $ttl));
    }

    /**
     * 构造标准 JSON 工具错误结果；仍由 Prism 按 role=tool 返回给模型。
     */
    private function encodeToolError(string $error, string $message): string
    {
        return json_encode([
            'ok' => false,
            'error' => $error,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"ok":false}';
    }
}
