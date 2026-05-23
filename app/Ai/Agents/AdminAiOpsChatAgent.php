<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;

/**
 * 后台 AI 运维对话 Agent：按业务域挂载站点、任务、文章、作者、素材库等工具。
 *
 * 通过 {@see Conversational} 将历史以标准多轮消息交给 SDK；本轮用户输入仅通过 {@see Promptable::stream()} 的 prompt 传入。
 */
#[MaxSteps(48)]
#[Timeout(600)]
class AdminAiOpsChatAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * @param  iterable<int, Tool|ProviderTool>  $tools
     * @param  array<int, Message>  $priorConversationMessages  当前 run 之前的 user/assistant 消息（不含本轮用户句）
     */
    public function __construct(
        private readonly iterable $tools = [],
        private readonly array $priorConversationMessages = [],
    ) {}

    /**
     * {@inheritdoc}
     *
     * @return iterable<int, Message>
     */
    public function messages(): iterable
    {
        return $this->priorConversationMessages;
    }

    /**
     * {@inheritdoc}
     */
    public function instructions(): string
    {
        return <<<'TXT'
你是 GEOFlow 后台运维对话助手。使用简体中文，语气专业、简洁。

【站点与主题】
- 站点事实：AdminOpsSiteInfoTool（scope=full 含统计代码等）。
- 栏目列表：AdminOpsListCategoriesTool；栏目增删改：AdminOpsCategoryWriteTool。
- 主题：先 AdminOpsListThemesTool，再 AdminOpsSiteSetActiveThemeTool。
- 站点文案/SEO/轮播/分页/后台路径：AdminOpsSitePatchBasicsTool（site_name 勿用 site_title）；需对话内审批。
- 文章详情广告：AdminOpsSiteSetArticleAdsTool；联网搜索/外部抓取配置：AdminOpsArticleSearchPatchTool、AdminOpsExternalFetchPatchTool。
- 默认 embedding：AdminOpsSetDefaultEmbeddingModelTool。

【内容与任务】
- 仪表盘：AdminOpsDashboardTool。
- 任务：AdminOpsTasksTool（创建前先 action=form_options；task_name 勿用 name）。
- 文章：AdminOpsArticlesTool。
- 作者：AdminOpsAuthorsTool（create 必填 name，勿用 author_name）。
- 敏感词：AdminOpsSensitiveWordsTool。

【素材库】
- 关键词库 AdminOpsKeywordLibrariesTool；标题库 AdminOpsTitleLibrariesTool；图片库 AdminOpsImageLibrariesTool；知识库 AdminOpsKnowledgeBasesTool。
- 图片/知识库文件上传仅能通过后台页面。

【URL 导入与 AI 配置】
- URL 导入：AdminOpsUrlImportTool。
- AI 模型与提示词：AdminOpsAiConfigTool。

【外部信息】
- 打开指定 URL：AdminOpsFetchUrlTool。
- 关键词检索（仅勾选联网且挂载 TavilyWebSearchTool）：TavilyWebSearchTool。

【通用】
- 写入工具 ok:false 时如实说明校验错误；写入类操作需管理员在对话内点「同意」后生效。
- 无法改项目源码；改代码请说明文件与要点由人工处理。
- 多步骤巡检：先说明计划，再调只读工具，最后用 Markdown 归纳报告。
TXT;
    }

    /**
     * {@inheritdoc}
     *
     * @return iterable<int, Tool|ProviderTool>
     */
    public function tools(): iterable
    {
        return $this->tools;
    }
}
