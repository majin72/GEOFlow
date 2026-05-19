<?php

namespace App\Services\Admin\AiOps;

/**
 * 判断 AI 运维工具调用是否必须先经用户确认。
 *
 * 写库、Shell、集成 patch 等变更类工具需审批；只读工具（含工作区 Read/Grep/Glob）无需审批。
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

        if ($toolName === 'AdminOpsSiteSetActiveThemeTool') {
            $theme = trim((string) ($arguments['theme_id'] ?? ''));

            return 'site_set_theme:'.($theme !== '' ? $theme : '(default)');
        }

        if ($toolName === 'AdminOpsSiteSetArticleAdsTool') {
            $count = is_array($arguments['ads'] ?? null) ? count($arguments['ads']) : 0;

            return 'site_set_article_ads:count='.$count;
        }

        if ($toolName === 'AdminOpsArticleSearchPatchTool') {
            $patch = $arguments['patch'] ?? null;
            if (! is_array($patch)) {
                return 'article_search_patch:(invalid)';
            }
            $keys = implode(',', array_slice(array_keys($patch), 0, 8));

            return 'article_search_patch:'.($keys !== '' ? $keys : '(empty)');
        }

        if ($toolName === 'AdminOpsExternalFetchPatchTool') {
            $patch = $arguments['patch'] ?? null;
            if (! is_array($patch)) {
                return 'external_fetch_patch:(invalid)';
            }
            $keys = implode(',', array_slice(array_keys($patch), 0, 8));

            return 'external_fetch_patch:'.($keys !== '' ? $keys : '(empty)');
        }

        if ($toolName === 'AdminOpsSetDefaultEmbeddingModelTool') {
            return 'set_default_embedding:model_id='.(string) ($arguments['model_id'] ?? 0);
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
