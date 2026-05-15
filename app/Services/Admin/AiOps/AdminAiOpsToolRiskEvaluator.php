<?php

namespace App\Services\Admin\AiOps;

/**
 * 判断 AI 运维工具调用是否必须先经用户确认。
 *
 * 当前策略：{@see AdminOpsAdminActionTool} 的 write；{@see AdminOpsSitePatchBasicsTool} 任意字段合并写入。
 * 注意：若模型在同一轮 assistant 的 tool_calls 中并行挂多个工具，挂起发生在执行到需审批工具时；
 * 此前已执行完毕的工具结果会照常推送到 SSE，续跑语义以审批记录为准，建议提示词要求「先读后写、分轮调用」以降低歧义。
 */
final class AdminAiOpsToolRiskEvaluator
{
    /**
     * 若需要审批则返回非空风险标签（用于摘要）；不需要则返回 null。
     *
     * @param  array<string, mixed>  $arguments  工具入参（已解析为数组）
     */
    public function evaluate(string $toolName, array $arguments): ?string
    {
        if (! (bool) config('geoflow.admin_ai_ops_tool_approval.enabled', true)) {
            return null;
        }

        if ($toolName === 'AdminOpsSitePatchBasicsTool') {
            $patch = $arguments['patch'] ?? null;
            if (! is_array($patch)) {
                return 'site_patch_basics:(invalid_patch)';
            }
            $keys = array_keys($patch);
            $preview = implode(',', array_slice($keys, 0, 12));

            return 'site_patch_basics:'.($preview !== '' ? $preview : '(empty)');
        }

        if ($toolName !== 'AdminOpsAdminActionTool') {
            return null;
        }

        $kind = strtolower(trim((string) ($arguments['kind'] ?? '')));
        if ($kind !== 'write') {
            return null;
        }

        $op = trim((string) ($arguments['op'] ?? ''));

        return 'admin_write:'.($op !== '' ? $op : '(unknown_op)');
    }
}
