<?php

declare(strict_types=1);

namespace App\Services\Admin\AiOps;

/**
 * 对需审批的写操作/Shell 统一执行「风险评估 → 挂起或继续」。
 *
 * 同轮多个写工具：逐条落库 pending 并返回待审批 JSON，不中断 Agent 流（对齐 claw-code 顺序处理语义）。
 */
final class AdminAiOpsPendingWriteGuard
{
    public function __construct(
        private readonly AdminAiOpsToolRiskEvaluator $riskEvaluator,
        private readonly AdminAiOpsToolApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $normalizedArguments
     * @param  callable(): array<string, mixed>|string  $execute
     */
    public function runJson(string $toolName, array $normalizedArguments, callable $execute): string
    {
        $risk = $this->riskEvaluator->evaluate($toolName, $normalizedArguments);
        if ($risk !== null) {
            $pending = $this->approvals->createPendingWithoutThrow($toolName, $normalizedArguments, $risk);

            return json_encode([
                'ok' => false,
                'pending_user_approval' => true,
                'approval_id' => $pending['approval_id'],
                'message' => (string) __('admin.ai_ops.tool_pending_approval_result_preview'),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        }

        $result = $execute();

        if (is_string($result)) {
            return $result;
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
