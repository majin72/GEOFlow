<?php

declare(strict_types=1);

namespace App\Services\Admin\AiOps;

/**
 * 对需审批的写操作/Shell 统一执行「风险评估 → 挂起或继续」。
 *
 * 写工具在原始 Laravel AI tool call 内等待审批；批准后返回真实执行结果，拒绝/超时返回标准工具错误 JSON。
 */
final class AdminAiOpsPendingWriteGuard
{
    public function __construct(
        private readonly AdminAiOpsToolRiskEvaluator $riskEvaluator,
        private readonly AdminAiOpsToolApprovalService $approvals,
        private readonly AdminAiOpsToolApprovalWaiter $waiter,
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

            if (app()->bound(AdminAiOpsStreamContext::class)) {
                app(AdminAiOpsStreamContext::class)->emitApprovalRequired($pending);
            }

            return $this->waiter->waitForDecisionAndRun((string) $pending['approval_id'], $execute);
        }

        $result = $execute();

        if (is_string($result)) {
            return $result;
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
