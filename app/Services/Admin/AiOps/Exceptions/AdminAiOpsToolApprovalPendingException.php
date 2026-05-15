<?php

namespace App\Services\Admin\AiOps\Exceptions;

use RuntimeException;

/**
 * 高风险工具需在后台 UI 确认后方可执行；抛出后首轮 SSE 应发送 approval_required 并结束首轮连接。
 */
final class AdminAiOpsToolApprovalPendingException extends RuntimeException
{
    /**
     * @param  string  $approvalId  审批行主键 UUID
     * @param  string  $toolName  Laravel AI 注册的工具名（通常为类短名）
     * @param  string  $summary  供 Modal 展示的一行摘要
     * @param  string  $fingerprint  参数指纹（sha256）
     */
    public function __construct(
        public readonly string $approvalId,
        public readonly string $toolName,
        public readonly string $summary,
        public readonly string $fingerprint,
        public readonly string $expiresAtIso8601,
    ) {
        parent::__construct('AI 运维工具调用需要用户确认。');
    }
}
