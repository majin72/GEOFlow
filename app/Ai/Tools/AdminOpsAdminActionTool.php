<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\Admin\AdminOps\AdminOpsAdminActionService;
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
    ) {}

    /**
     * {@inheritdoc}
     */
    public function description(): Stringable|string
    {
        return <<<'DESC'
统一后台「除超管敏感项外」的读写入口。参数：kind=read|write；op=操作名；payload_json=JSON 对象字符串（可 {}）。
read 常用 op：dashboard_summary, materials_stats, legacy_ai_configurator, sensitive_words_list, tasks_overview, tasks_form_options, task_detail(task_id), categories_list, articles_list(filters...), article_detail(article_id,only_trashed?), authors_list(search,page), author_detail(author_id), keyword_libraries_list, keyword_library_detail(library_id,search,page), title_libraries_list, title_library_detail(library_id,page), image_libraries_list, image_library_detail(library_id,page), knowledge_bases_list, knowledge_base_detail(knowledge_base_id), url_import_index_stats, url_import_job_show(job_id), url_import_history(page), url_import_status(job_id), ai_models_list, ai_prompts_list, ai_special_prompts_read。
write 常用 op：sensitive_words_add(words 多行文本), sensitive_words_delete(word_id)；category_create|category_update|category_delete；author_create|author_update|author_delete；task_toggle(task_id,current_status)|task_delete|task_batch_start_stop(task_id,action=start|stop)|task_create|task_update(task_id,payload)；articles_batch_status(article_ids,new_status)|articles_batch_review|articles_batch_soft_delete|articles_batch_restore|articles_batch_force_delete|articles_trash_empty|article_restore|article_force_delete|article_create|article_update(article_id,payload)；keyword_library_*|keyword_add|keyword_delete_batch|keyword_import；title_library_*|title_add|title_delete_batch|title_library_ai_generate(library_id,payload)；image_library_*|image_delete_batch；knowledge_base_*；url_import_create(payload)|url_import_run(job_id)|url_import_commit(job_id)；ai_model_create|ai_model_update|ai_model_delete|ai_model_test；ai_prompt_create|ai_prompt_update|ai_prompt_delete；ai_special_prompt_keyword|ai_special_prompt_description(content)。图片/知识库文件上传为 multipart，仅能通过后台页面上传。
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
                ->description('read 或 write'),
            'op' => $schema->string()
                ->description('操作名，见工具说明。'),
            'payload_json' => $schema->string()
                ->description('JSON 对象字符串，包含该 op 所需字段；无参数时传 {}'),
        ];
    }
}
