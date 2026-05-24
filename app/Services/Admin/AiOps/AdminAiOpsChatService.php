<?php

namespace App\Services\Admin\AiOps;

use App\Ai\Agents\AdminAiOpsChatAgent;
use App\Ai\Tools\AdminOpsAiConfigTool;
use App\Ai\Tools\AdminOpsArticleSearchPatchTool;
use App\Ai\Tools\AdminOpsArticlesTool;
use App\Ai\Tools\AdminOpsAuthorsTool;
use App\Ai\Tools\AdminOpsCategoryWriteTool;
use App\Ai\Tools\AdminOpsDashboardTool;
use App\Ai\Tools\AdminOpsExternalFetchPatchTool;
use App\Ai\Tools\AdminOpsFetchUrlTool;
use App\Ai\Tools\AdminOpsImageLibrariesTool;
use App\Ai\Tools\AdminOpsKeywordLibrariesTool;
use App\Ai\Tools\AdminOpsKnowledgeBasesTool;
use App\Ai\Tools\AdminOpsListCategoriesTool;
use App\Ai\Tools\AdminOpsListThemesTool;
use App\Ai\Tools\AdminOpsSensitiveWordsTool;
use App\Ai\Tools\AdminOpsSetDefaultEmbeddingModelTool;
use App\Ai\Tools\AdminOpsSiteInfoTool;
use App\Ai\Tools\AdminOpsSitePatchBasicsTool;
use App\Ai\Tools\AdminOpsSiteSetActiveThemeTool;
use App\Ai\Tools\AdminOpsSiteSetArticleAdsTool;
use App\Ai\Tools\AdminOpsTasksTool;
use App\Ai\Tools\AdminOpsTitleLibrariesTool;
use App\Ai\Tools\AdminOpsUrlImportTool;
use App\Ai\Tools\TavilyWebSearchTool;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsToolApproval;
use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Closure;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;

/**
 * AI 运维对话：向配置的聊天模型发起补全（站点读/写、主题、栏目、统一后台动作、广告、联网搜索与外部抓取等工具；不含项目源码访问，支持流式增量回调）。
 */
class AdminAiOpsChatService
{
    /**
     * 历史轮次在拼入 messages() 时的总字符预算（与原先单条合并提示的上限一致，从最早轮开始丢弃）。
     */
    private const PRIOR_CONVERSATION_CHAR_BUDGET = 12000;

    /**
     * @param  ApiKeyCrypto  $apiKeyCrypto  用于解密 ai_models 表中的密文 API Key
     * @param  TavilyWebSearchTool  $tavilyWebSearchTool  公开网络事实检索（Tavily，与站点「文章搜索」配置 patch 不同）
     */
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AdminOpsSiteInfoTool $siteInfoTool,
        private readonly AdminOpsListThemesTool $listThemesTool,
        private readonly AdminOpsListCategoriesTool $listCategoriesTool,
        private readonly AdminOpsDashboardTool $dashboardTool,
        private readonly AdminOpsSensitiveWordsTool $sensitiveWordsTool,
        private readonly AdminOpsTasksTool $tasksTool,
        private readonly AdminOpsCategoryWriteTool $categoryWriteTool,
        private readonly AdminOpsArticlesTool $articlesTool,
        private readonly AdminOpsAuthorsTool $authorsTool,
        private readonly AdminOpsKeywordLibrariesTool $keywordLibrariesTool,
        private readonly AdminOpsTitleLibrariesTool $titleLibrariesTool,
        private readonly AdminOpsImageLibrariesTool $imageLibrariesTool,
        private readonly AdminOpsKnowledgeBasesTool $knowledgeBasesTool,
        private readonly AdminOpsUrlImportTool $urlImportTool,
        private readonly AdminOpsAiConfigTool $aiConfigTool,
        private readonly AdminOpsSitePatchBasicsTool $sitePatchBasicsTool,
        private readonly AdminOpsSiteSetActiveThemeTool $siteSetActiveThemeTool,
        private readonly AdminOpsSiteSetArticleAdsTool $siteSetArticleAdsTool,
        private readonly AdminOpsArticleSearchPatchTool $articleSearchPatchTool,
        private readonly AdminOpsExternalFetchPatchTool $externalFetchPatchTool,
        private readonly TavilyWebSearchTool $tavilyWebSearchTool,
        private readonly AdminOpsSetDefaultEmbeddingModelTool $setDefaultEmbeddingModelTool,
        private readonly AdminOpsFetchUrlTool $fetchUrlTool,
        private readonly AdminAiOpsLlmTranscriptCodec $transcriptCodec,
    ) {}

    /**
     * 将当前 run 之前本会话内已有内容的轮次转为 Laravel AI SDK 所需的 history 消息列表。
     *
     * 含 completed / failed / awaiting_confirmation：审批挂起轮若被排除，后续追问时模型会误以为无历史。
     *
     * @return array<int, Message>
     */
    public function priorMessagesBeforeRun(int $sessionId, int $beforeRunId): array
    {
        $rows = AdminAiOpsRun::query()
            ->where('session_id', $sessionId)
            ->where('id', '<', $beforeRunId)
            ->whereIn('status', ['completed', 'failed', 'awaiting_confirmation'])
            ->orderBy('id')
            ->get(['input_text', 'result_summary', 'error_message', 'status', 'plan_stream_snapshot']);

        /** @var array<int, array<int, Message>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $snapshot = is_array($row->plan_stream_snapshot) ? $row->plan_stream_snapshot : [];
            $stored = is_array($snapshot['llm_messages'] ?? null) ? $snapshot['llm_messages'] : [];
            $storedMessages = $stored !== [] ? $this->transcriptCodec->fromArray($stored) : [];
            if ($storedMessages !== []) {
                $groups[] = $storedMessages;

                continue;
            }

            $userLine = trim((string) ($row->input_text ?? ''));
            if ($userLine === '') {
                continue;
            }

            $status = (string) $row->status;
            if ($status === 'completed') {
                $assistantLine = trim((string) ($row->result_summary ?? ''));
            } elseif ($status === 'awaiting_confirmation') {
                $assistantLine = trim((string) ($row->result_summary ?? ''));
                if ($assistantLine === '') {
                    $assistantLine = trim((string) ($snapshot['partial_assistant_text'] ?? ''));
                }
                if ($assistantLine !== '') {
                    $assistantLine .= "\n\n（该轮有写操作待管理员在后台批准执行，可能尚未全部落库。）";
                }
            } else {
                $assistantLine = trim((string) ($row->error_message ?? ''));
                if ($assistantLine === '') {
                    $assistantLine = '（本轮未成功完成。）';
                }
            }

            if ($assistantLine === '') {
                continue;
            }

            $groups[] = [
                new UserMessage($userLine),
                new AssistantMessage($assistantLine),
            ];
        }

        while ($this->priorGroupsEstimatedChars($groups) > self::PRIOR_CONVERSATION_CHAR_BUDGET && $groups !== []) {
            array_shift($groups);
        }

        $messages = [];
        foreach ($groups as $group) {
            array_push($messages, ...$group);
        }

        return $messages;
    }

    /**
     * 流式调用模型：在每个文本增量上回调当前累积全文，最终返回去首尾空白后的完整回复。
     *
     * @param  string  $currentUserMessage  本轮用户输入（单独作为最后一条 user 消息，与 {@see Conversational::messages()} 衔接）
     * @param  array<int, Message>  $priorConversationMessages  由 {@see priorMessagesBeforeRun()} 等构造的历史消息
     * @param  AiModel  $aiModel  已校验为启用的聊天模型
     * @param  Closure(string): void  $onTextAccumulated  每当有新的可见文本 token 时传入当前全文
     * @param  (Closure(object): void)|null  $onRawModelStreamEvent  每收到一条 SDK 流事件时回调（含 tool_call、text_delta 等原始结构）
     * @param  (Closure(array<string, mixed>): void)|null  $onLlmStreamFinished  流结束后回调（含 invocation_id、usage、合并正文等）
     * @return string 助手纯文本（与最后一次回调累积一致，经 trim）
     *
     * @throws \Throwable 底层 HTTP/SDK 异常原样抛出，由控制器统一归一化错误文案
     */
    public function streamAssistantReply(
        string $currentUserMessage,
        array $priorConversationMessages,
        AiModel $aiModel,
        Closure $onTextAccumulated,
        ?Closure $onRawModelStreamEvent = null,
        ?Closure $onLlmStreamFinished = null,
        bool $webSearchEnabled = false,
    ): string {
        return $this->streamAssistantWithPriorAgent(
            currentUserMessage: $currentUserMessage,
            priorConversationMessages: $priorConversationMessages,
            aiModel: $aiModel,
            onTextAccumulated: $onTextAccumulated,
            onRawModelStreamEvent: $onRawModelStreamEvent,
            onLlmStreamFinished: $onLlmStreamFinished,
            webSearchEnabled: $webSearchEnabled,
        );
    }

    /**
     * Legacy：旧审批 nonce 续流使用的合成上下文；新链路禁止调用，改由 Prism 原生 role=tool 继续。
     *
     * @param  array<int, Message>  $priorConversationMessages
     * @param  Closure(string): void  $onTextAccumulated
     * @param  (Closure(object): void)|null  $onRawModelStreamEvent
     * @param  (Closure(array<string, mixed>): void)|null  $onLlmStreamFinished
     */
    public function streamAssistantResumeAfterApproval(
        AdminAiOpsRun $run,
        array $priorConversationMessages,
        AiModel $aiModel,
        Closure $onTextAccumulated,
        ?Closure $onRawModelStreamEvent = null,
        ?Closure $onLlmStreamFinished = null,
        bool $webSearchEnabled = false,
    ): string {
        $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
        $partial = trim((string) ($snapshot['partial_assistant_text'] ?? ''));
        $toolOutput = $this->buildDecidedApprovalsSynthesis((int) $run->id);
        if ($toolOutput === '') {
            $toolOutput = trim((string) ($snapshot['tool_output_text'] ?? ''));
        }

        $toolName = '';
        $executedRow = AdminAiOpsToolApproval::query()
            ->where('run_id', (int) $run->id)
            ->where('status', 'executed')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        if ($executedRow instanceof AdminAiOpsToolApproval) {
            $toolName = (string) $executedRow->tool_name;
        }

        $synthesis = $this->buildResumeSynthesisBlockApprove(
            partialAssistantText: $partial,
            toolOutputText: $toolOutput,
            toolName: $toolName,
        );

        $mergedPrior = array_merge($priorConversationMessages, [
            new UserMessage($synthesis),
        ]);

        return $this->streamAssistantWithPriorAgent(
            currentUserMessage: '请基于上文（含已批准工具输出）用简体中文给出最终答复。',
            priorConversationMessages: $mergedPrior,
            aiModel: $aiModel,
            onTextAccumulated: $onTextAccumulated,
            onRawModelStreamEvent: $onRawModelStreamEvent,
            onLlmStreamFinished: $onLlmStreamFinished,
            webSearchEnabled: $webSearchEnabled,
        );
    }

    /**
     * Legacy：旧拒绝 nonce 续流使用的合成上下文；新链路禁止调用，改由 Prism 原生 role=tool 继续。
     *
     * @param  array<int, Message>  $priorConversationMessages
     * @param  Closure(string): void  $onTextAccumulated
     * @param  (Closure(object): void)|null  $onRawModelStreamEvent
     * @param  (Closure(array<string, mixed>): void)|null  $onLlmStreamFinished
     */
    public function streamAssistantResumeAfterReject(
        AdminAiOpsRun $run,
        array $priorConversationMessages,
        AiModel $aiModel,
        string $toolName,
        string $rejectReason,
        string $argsFingerprint,
        Closure $onTextAccumulated,
        ?Closure $onRawModelStreamEvent = null,
        ?Closure $onLlmStreamFinished = null,
        bool $webSearchEnabled = false,
    ): string {
        $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
        $partial = trim((string) ($snapshot['partial_assistant_text'] ?? ''));
        $reason = trim($rejectReason) !== '' ? trim($rejectReason) : 'denied by user approval prompt';
        $batchOutput = $this->buildDecidedApprovalsSynthesis((int) $run->id);

        $synthesis = $this->buildResumeSynthesisBlockReject(
            partialAssistantText: $partial,
            toolName: $toolName,
            rejectReason: $reason,
            argsFingerprint: $argsFingerprint,
            batchToolOutputText: $batchOutput,
        );

        $mergedPrior = array_merge($priorConversationMessages, [
            new UserMessage($synthesis),
        ]);

        return $this->streamAssistantWithPriorAgent(
            currentUserMessage: '请基于上文（工具被拒绝）用简体中文给出最终答复，不要假装已执行写操作。',
            priorConversationMessages: $mergedPrior,
            aiModel: $aiModel,
            onTextAccumulated: $onTextAccumulated,
            onRawModelStreamEvent: $onRawModelStreamEvent,
            onLlmStreamFinished: $onLlmStreamFinished,
            webSearchEnabled: $webSearchEnabled,
        );
    }

    /**
     * 从 run 的 plan_stream_snapshot 读取本轮是否启用联网搜索（Tavily）。
     */
    public function runWebSearchEnabled(AdminAiOpsRun $run): bool
    {
        $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];

        return (bool) ($snapshot['web_search_enabled'] ?? false);
    }

    /**
     * 使用自定义 prior 消息列表发起一次流式对话（与 {@see streamAssistantReply} 共享 Provider 注册逻辑）。
     *
     * @param  array<int, Message>  $priorConversationMessages
     */
    private function streamAssistantWithPriorAgent(
        string $currentUserMessage,
        array $priorConversationMessages,
        AiModel $aiModel,
        Closure $onTextAccumulated,
        ?Closure $onRawModelStreamEvent,
        ?Closure $onLlmStreamFinished,
        bool $webSearchEnabled = false,
    ): string {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        $modelId = trim((string) ($aiModel->model_id ?? ''));

        if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
            throw new RuntimeException('AI 模型配置不完整');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
        $providerName = OpenAiRuntimeProvider::registerProvider('admin_ai_ops_chat', $driver, $providerUrl, $apiKey);

        $tools = [
            $this->siteInfoTool,
            $this->listThemesTool,
            $this->listCategoriesTool,
            $this->dashboardTool,
            $this->sensitiveWordsTool,
            $this->tasksTool,
            $this->categoryWriteTool,
            $this->articlesTool,
            $this->authorsTool,
            $this->keywordLibrariesTool,
            $this->titleLibrariesTool,
            $this->imageLibrariesTool,
            $this->knowledgeBasesTool,
            $this->urlImportTool,
            $this->aiConfigTool,
            $this->sitePatchBasicsTool,
            $this->siteSetActiveThemeTool,
            $this->siteSetArticleAdsTool,
            $this->articleSearchPatchTool,
            $this->externalFetchPatchTool,
            $this->setDefaultEmbeddingModelTool,
        ];
        if ((bool) config('geoflow.admin_ai_ops_url_fetch.enabled', true)) {
            $tools[] = $this->fetchUrlTool;
        }
        if ($webSearchEnabled) {
            $tools[] = $this->tavilyWebSearchTool;
        }

        $agent = new AdminAiOpsChatAgent(
            tools: $tools,
            priorConversationMessages: $priorConversationMessages,
        );
        $transcript = new AdminAiOpsLlmTranscriptRecorder($this->transcriptCodec);
        $transcript->start($currentUserMessage);
        if (app()->bound(AdminAiOpsStreamContext::class)) {
            app(AdminAiOpsStreamContext::class)->llmTranscript = $transcript;
        }
        $stream = $agent->stream($currentUserMessage, [], $providerName, $modelId);

        $manual = '';
        foreach ($stream as $event) {
            $transcript->record($event);
            if ($onRawModelStreamEvent instanceof Closure) {
                $onRawModelStreamEvent($event);
            }
            if ($event instanceof TextDelta) {
                $manual .= $event->delta;
                $onTextAccumulated($manual);
            }
        }

        $fromStream = trim((string) ($stream->text ?? ''));
        $fromManual = trim((string) $manual);
        // 部分 Provider 在工具轮次后仅填充较短的 stream->text；始终以手工累积的更长正文为准，避免落库/历史丢失前半段。
        $final = mb_strlen($fromManual) > mb_strlen($fromStream) ? $fromManual : ($fromStream !== '' ? $fromStream : $fromManual);

        if ($onLlmStreamFinished instanceof Closure) {
            $onLlmStreamFinished([
                'invocation_id' => $stream->invocationId,
                'final_text' => (string) ($stream->text ?? $manual),
                'final_text_trimmed' => $final,
                'usage' => $stream->usage?->toArray(),
                'events_count' => $stream->events->count(),
            ]);
        }
        $transcript->finish();

        return $final;
    }

    /**
     * 构造「批准 + 工具输出」续跑用的合成 user 文本块（控制总长度）。
     */
    private function buildResumeSynthesisBlockApprove(string $partialAssistantText, string $toolOutputText, string $toolName): string
    {
        $budget = self::PRIOR_CONVERSATION_CHAR_BUDGET;
        $half = max(1000, (int) floor($budget / 2));
        $partialT = $this->truncateForResumeBudget('中断前助手片段', $partialAssistantText, $half);
        $remaining = max(500, $budget - mb_strlen($partialT));
        $toolT = $this->truncateForResumeBudget('工具输出', $toolOutputText, $remaining);

        return <<<TXT
【续跑上下文 / 非最终答案】
以下「中断前助手已输出片段」来自首轮 SSE，可能不完整，请勿将其视为最终结论：
---
{$partialT}
---
管理员已批准执行工具「{$toolName}」。工具返回（通常为 JSON 文本）如下：
---
{$toolT}
---
请用简体中文基于工具真实输出给出最终答复；若工具返回 ok:false，请如实说明原因与后续建议。
TXT;
    }

    /**
     * 将本轮已决定（执行/拒绝）的全部工具结果合成为续跑上下文。
     */
    public function buildDecidedApprovalsSynthesis(int $runId): string
    {
        $rows = AdminAiOpsToolApproval::query()
            ->where('run_id', $runId)
            ->whereIn('status', ['executed', 'rejected'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $blocks = [];
        foreach ($rows as $row) {
            $name = (string) $row->tool_name;
            if ((string) $row->status === 'executed') {
                $out = trim((string) ($row->executed_output ?? ''));
                $blocks[] = "工具「{$name}」已批准并执行，返回：\n".($out !== '' ? $out : '（空）');
            } else {
                $reason = trim((string) ($row->rejection_reason ?? ''));
                if ($reason === '') {
                    $reason = 'denied by user approval prompt';
                }
                $blocks[] = "工具「{$name}」已被管理员拒绝。原因：{$reason}";
            }
        }

        return implode("\n\n---\n\n", $blocks);
    }

    /**
     * 构造「拒绝工具」续跑用的合成 user 文本块（语义对齐 tool_result is_error=true）。
     */
    private function buildResumeSynthesisBlockReject(string $partialAssistantText, string $toolName, string $rejectReason, string $argsFingerprint, string $batchToolOutputText = ''): string
    {
        $budget = self::PRIOR_CONVERSATION_CHAR_BUDGET;
        $partialT = $this->truncateForResumeBudget('中断前助手片段', $partialAssistantText, (int) floor($budget * 0.35));

        $meta = '工具：'.$toolName."\n".'拒绝原因：'.$rejectReason."\n".'参数指纹：'.$argsFingerprint;
        $batchT = trim($batchToolOutputText) !== ''
            ? $this->truncateForResumeBudget('同轮其它工具结果', $batchToolOutputText, (int) floor($budget * 0.35))
            : '';

        $batchSection = $batchT !== '' ? "\n\n同轮已处理工具汇总：\n---\n{$batchT}\n---" : '';

        return <<<TXT
【等价于 tool_result（is_error=true）】
{$meta}{$batchSection}

以下为首轮流式输出片段（可能不完整）：
---
{$partialT}
---

请用简体中文说明：用户已拒绝该工具调用，你不得假装写操作已成功；尊重用户选择并给出安全、可执行的替代建议（控制在合理长度内）。
TXT;
    }

    /**
     * 将文本截断到指定字符预算并追加提示。
     */
    private function truncateForResumeBudget(string $label, string $text, int $maxChars): string
    {
        $text = trim($text);
        if ($text === '') {
            return '（空）';
        }
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, max(1, $maxChars - 12))."\n…（{$label} 已截断）";
    }

    /**
     * @param  array<int, array<int, Message>>  $groups
     */
    private function priorGroupsEstimatedChars(array $groups): int
    {
        $total = 0;
        foreach ($groups as $group) {
            $total += $this->transcriptCodec->estimatedChars($group);
        }

        return $total;
    }
}
