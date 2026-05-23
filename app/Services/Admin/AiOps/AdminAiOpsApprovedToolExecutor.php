<?php

declare(strict_types=1);

namespace App\Services\Admin\AiOps;

use App\Services\Admin\AdminOps\AdminOpsAdminActionService;
use App\Services\Admin\AdminOps\AdminOpsSiteWriteService;
use RuntimeException;
use Throwable;

/**
 * 审批通过后按 tool_name 回放已存参数。
 */
final class AdminAiOpsApprovedToolExecutor
{
    public function __construct(
        private readonly AdminOpsAdminActionService $adminAction,
        private readonly AdminOpsSiteWriteService $siteWrite,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public function execute(string $toolName, array $decoded): string
    {
        try {
            $result = $this->executeUnsafe($toolName, $decoded);
        } catch (Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        }

        if (is_string($result)) {
            return $result;
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>|string
     */
    private function executeUnsafe(string $toolName, array $decoded): array|string
    {
        if ($this->isMirrorWriteTool($toolName)) {
            $op = strtolower(trim((string) ($decoded['op'] ?? '')));
            $payload = $decoded['payload'] ?? [];
            if (! is_array($payload)) {
                $payload = [];
            }

            return $this->adminAction->execute('write', $op, $payload);
        }

        if ($toolName === 'AdminOpsSitePatchBasicsTool') {
            $patch = $decoded['patch'] ?? [];
            if (! is_array($patch)) {
                return ['ok' => false, 'error' => 'patch 参数损坏。'];
            }

            return $this->siteWrite->patchBasics($patch);
        }

        if ($toolName === 'AdminOpsSiteSetActiveThemeTool') {
            return $this->siteWrite->setActiveTheme(trim((string) ($decoded['theme_id'] ?? '')));
        }

        if ($toolName === 'AdminOpsSiteSetArticleAdsTool') {
            $ads = $decoded['ads'] ?? [];
            if (! is_array($ads)) {
                return ['ok' => false, 'error' => 'ads 参数损坏。'];
            }

            return $this->siteWrite->setArticleDetailAds($ads);
        }

        if ($toolName === 'AdminOpsArticleSearchPatchTool') {
            $patch = $decoded['patch'] ?? [];
            if (! is_array($patch)) {
                return ['ok' => false, 'error' => 'patch 参数损坏。'];
            }

            return $this->siteWrite->patchArticleSearch($patch);
        }

        if ($toolName === 'AdminOpsExternalFetchPatchTool') {
            $patch = $decoded['patch'] ?? [];
            if (! is_array($patch)) {
                return ['ok' => false, 'error' => 'patch 参数损坏。'];
            }

            return $this->siteWrite->patchExternalFetch($patch);
        }

        if ($toolName === 'AdminOpsSetDefaultEmbeddingModelTool') {
            return $this->siteWrite->setDefaultEmbeddingModelId((int) ($decoded['model_id'] ?? 0));
        }

        throw new RuntimeException('暂不支持的审批工具：'.$toolName);
    }

    /**
     * 是否为委托 AdminActionService write 的领域工具。
     */
    public function isMirrorWriteTool(string $toolName): bool
    {
        return in_array($toolName, [
            'AdminOpsSensitiveWordsTool',
            'AdminOpsTasksTool',
            'AdminOpsCategoryWriteTool',
            'AdminOpsArticlesTool',
            'AdminOpsAuthorsTool',
            'AdminOpsKeywordLibrariesTool',
            'AdminOpsTitleLibrariesTool',
            'AdminOpsImageLibrariesTool',
            'AdminOpsKnowledgeBasesTool',
            'AdminOpsUrlImportTool',
            'AdminOpsAiConfigTool',
        ], true);
    }
}
