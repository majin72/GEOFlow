<?php

declare(strict_types=1);

namespace App\Services\Admin\AiOps;

/**
 * 对需审批的写操作/Shell 统一执行「风险评估 → 挂起或继续」。
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
            $this->approvals->createPendingAndThrow($toolName, $normalizedArguments, $risk);
        }

        $result = $execute();

        if (is_string($result)) {
            return $result;
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
