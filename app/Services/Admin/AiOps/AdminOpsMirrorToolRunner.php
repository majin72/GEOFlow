<?php

declare(strict_types=1);

namespace App\Services\Admin\AiOps;

use App\Services\Admin\AdminOps\AdminOpsAdminActionService;

/**
 * 领域 Mirror 工具统一执行：只读直调 AdminActionService；写入经审批守卫。
 */
final class AdminOpsMirrorToolRunner
{
    public function __construct(
        private readonly AdminOpsAdminActionService $adminAction,
        private readonly AdminAiOpsPendingWriteGuard $writeGuard,
    ) {}

    /**
     * 执行只读 op 并返回 JSON 字符串。
     *
     * @param  array<string, mixed>  $payload
     */
    public function runRead(string $op, array $payload = []): string
    {
        return $this->encode($this->adminAction->execute('read', $op, $payload));
    }

    /**
     * 执行写入 op（可能挂起审批）；审批参数存为 op + payload。
     *
     * @param  array<string, mixed>  $payload
     */
    public function runWrite(string $toolName, string $op, array $payload): string
    {
        $approvalArgs = [
            'op' => $op,
            'payload' => $payload,
        ];

        return $this->writeGuard->runJson($toolName, $approvalArgs, function () use ($op, $payload): array {
            return $this->adminAction->execute('write', $op, $payload);
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function encode(array $result): string
    {
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
