<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AdminOps\AdminOpsAdminActionService;
use App\Services\Admin\AiOps\AdminAiOpsToolApprovalService;
use App\Services\Admin\AiOps\AdminAiOpsToolRiskEvaluator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * 统一后台运维动作工具：通过 kind+op+payload_json 覆盖仪表盘、敏感词、任务、文章、栏目、作者、素材库、URL 导入、AI 模型与提示词等（不含管理员账号、API Token、活动日志、改密）。
 */
final class AdminOpsAdminActionTool implements Tool
{
    public function __construct(
        private readonly AdminOpsAdminActionService $actions,
        private readonly AdminAiOpsToolRiskEvaluator $aiOpsRisk,
        private readonly AdminAiOpsToolApprovalService $aiOpsApprovals,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return <<<'DESC'
统一后台「除超管敏感项外」的读写入口。调用格式：kind=read|write；op=操作名；payload_json=JSON 对象（无参传 "{}"）。

【read 常用 op】
- dashboard_summary / materials_stats / legacy_ai_configurator
- sensitive_words_list
- tasks_overview / tasks_form_options（创建任务前必须先调，取 titleLibraries/prompts/aiModels 的 id）
- task_detail：{"task_id":1}
- categories_list
- articles_list：filters 同后台列表（status、search、page 等，字段放 payload 顶层）
- article_detail：{"article_id":1,"only_trashed":false}
- authors_list：{"search":"","page":1} / author_detail：{"author_id":1}
- keyword_libraries_list / keyword_library_detail：{"library_id":1,"search":"","page":1}
- title_libraries_list / title_library_detail：{"library_id":1,"page":1}
- image_libraries_list / image_library_detail：{"library_id":1,"page":1}
- knowledge_bases_list / knowledge_base_detail：{"knowledge_base_id":1}
- url_import_* / ai_models_list / ai_prompts_list / ai_special_prompts_read

【write · task_create / task_update 字段（与后台「创建任务」表单一致，禁止别名）】
创建前：read op=tasks_form_options，从返回的 options 里选 id。
task_create 的 payload_json 示例（试跑 1 篇）：
{"task_name":"示例任务","title_library_id":1,"prompt_id":3,"ai_model_id":1,"status":"active","article_limit":1,"draft_limit":1,"publish_interval":60,"category_mode":"smart","model_selection_mode":"fixed","need_review":0,"is_loop":1,"auto_keywords":1,"auto_description":1,"author_id":0}
必填：task_name（勿用 name）；title_library_id；prompt_id；ai_model_id；status 字符串 active|paused（勿用 1/0）。
选填：author_id（0=不指定）；image_library_id；image_count(0-5)；knowledge_base_id；fixed_category_id（固定栏目，勿用 category_id）；article_limit；draft_limit；publish_interval（分钟）；category_mode：smart|fixed|random（random 会当作 smart）；model_selection_mode：fixed|smart_failover；need_review/is_loop/auto_keywords/auto_description：0|1 或布尔（省略时：need_review 默认 0，其余三个默认 1）。
task_update：{"task_id":1,"payload":{...同上字段，仅传要改的...}} 或顶层带 task_id 与字段。
task_toggle：{"task_id":1,"current_status":"active"|"paused"}；task_delete：{"task_id":1}；task_batch_start_stop：{"task_id":1,"action":"start"|"stop"}

【write · 其他常用 op】
sensitive_words_add：{"words":"词1\n词2"}；sensitive_words_delete：{"word_id":1}
category_create / category_update / category_delete（定位支持 category_id、id、slug、category_name）
author_create|author_update|author_delete
articles_batch_* / article_create|article_update（article 字段见后台文章表单）
keyword_library_* / title_library_* / image_library_* / knowledge_base_*
url_import_create|run|commit；ai_model_*；ai_prompt_*；ai_special_prompt_*
图片/知识库文件上传为 multipart，仅能通过后台页面上传。
DESC;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): Stringable|string
    {
        $kind = strtolower(trim((string) Arr::get($request->toArray(), 'kind', '')));
        $op = strtolower(trim((string) Arr::get($request->toArray(), 'op', '')));
        $rawPayload = trim((string) Arr::get($request->toArray(), 'payload_json', '{}'));

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return json_encode(['ok' => false, 'error' => 'payload_json 不是合法 JSON。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        if (! is_array($payload)) {
            return json_encode(['ok' => false, 'error' => 'payload_json 必须为 JSON 对象。'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        $risk = $this->aiOpsRisk->evaluate('AdminOpsAdminActionTool', [
            'kind' => $kind,
            'op' => $op,
            'payload' => $payload,
        ]);
        if ($risk !== null) {
            $this->aiOpsApprovals->createPendingAndThrow('AdminOpsAdminActionTool', [
                'kind' => $kind,
                'op' => $op,
                'payload' => $payload,
            ], $risk);
        }

        try {
            $result = $this->actions->execute($kind, $op, $payload);

            return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        } catch (Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()
                ->description('read=只读查询；write=写入（任务创建等可能需管理员审批）'),
            'op' => $schema->string()
                ->description('操作名。创建文章生成任务：先 read tasks_form_options，再 write task_create。勿用 name/category_id/count 等别名。'),
            'payload_json' => $schema->string()
                ->description('JSON 对象字符串。task_create 必填：task_name,title_library_id,prompt_id,ai_model_id,status(active|paused)。选填：author_id,image_library_id,image_count,knowledge_base_id,fixed_category_id,article_limit,draft_limit,publish_interval(分钟),category_mode,model_selection_mode,need_review,is_loop,auto_keywords,auto_description。task_update 另需 task_id。无参传 {}'),
        ];
    }
}
