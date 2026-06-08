<?php

/**
 * GEO 引用监测核心表：项目、平台、账号、批次、观测、引用、评分与维护审计。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_monitor_projects', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->string('name', 160)->comment('监测项目名称');
            $table->string('slug', 120)->unique()->comment('项目唯一标识 slug');
            $table->string('brand_name', 160)->default('')->comment('监测品牌名称');
            $table->string('primary_domain', 255)->default('')->comment('品牌主域名');
            $table->json('competitor_domains')->nullable()->comment('竞品域名列表 JSON');
            $table->json('competitor_brands')->nullable()->comment('竞品品牌名列表 JSON');
            $table->json('product_keywords')->nullable()->comment('产品/业务关键词 JSON');
            $table->string('status', 32)->default('active')->index()->comment('项目状态：active/inactive 等');
            $table->text('notes')->nullable()->comment('备注说明');
            $table->foreignId('created_by_admin_id')->nullable()->comment('创建人管理员 ID')->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('GEO 监测项目表');
        });

        Schema::create('geo_monitor_platforms', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->string('code', 32)->unique()->comment('平台 code，如 doubao/deepseek/yuanbao');
            $table->string('label', 80)->comment('平台展示名称');
            $table->string('selector_version', 80)->default('')->comment('DOM 选择器版本号');
            $table->string('chat_url', 500)->default('')->comment('平台聊天页 URL');
            $table->boolean('is_enabled')->default(true)->index()->comment('是否在监测中启用');
            $table->json('config')->nullable()->comment('平台扩展配置 JSON');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('GEO 监测 AI 平台定义表');
        });

        Schema::create('geo_monitor_proxy_endpoints', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->string('label', 120)->comment('代理出口名称');
            $table->string('proxy_type', 32)->default('http')->comment('代理类型：http/socks5 等');
            $table->string('host', 255)->comment('代理主机');
            $table->unsignedSmallInteger('port')->default(0)->comment('代理端口');
            $table->string('region', 64)->nullable()->comment('代理地区标识');
            $table->string('egress_ip_summary', 120)->nullable()->comment('出口 IP 脱敏摘要');
            $table->string('status', 32)->default('active')->index()->comment('代理状态：active/cooldown/disabled 等');
            $table->unsignedInteger('failure_count')->default(0)->comment('累计失败次数');
            $table->timestamp('cooldown_until')->nullable()->comment('冷却截止时间');
            $table->timestamp('last_health_check_at')->nullable()->comment('最近健康检查时间');
            $table->string('last_health_status', 32)->nullable()->comment('最近健康检查结果');
            $table->json('meta')->nullable()->comment('扩展元数据 JSON');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('GEO 监测代理出口资源表');
        });

        Schema::create('geo_monitor_accounts', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('platform_id')->comment('所属 AI 平台 ID')->constrained('geo_monitor_platforms')->cascadeOnDelete();
            $table->string('external_id', 120)->comment('sidecar accounts.json 中的账号 ID');
            $table->string('label', 160)->default('')->comment('账号展示名称');
            $table->string('status', 40)->default('active')->index()->comment('账号状态：active/needs_login/cooldown 等');
            $table->string('profile_storage_path', 500)->default('')->comment('浏览器 profile 存储相对路径');
            $table->foreignId('proxy_endpoint_id')->nullable()->comment('绑定代理出口 ID')->constrained('geo_monitor_proxy_endpoints')->nullOnDelete();
            $table->unsignedSmallInteger('daily_quota')->nullable()->comment('每日探测额度上限');
            $table->unsignedSmallInteger('hourly_quota')->nullable()->comment('每小时探测额度上限');
            $table->timestamp('cooldown_until')->nullable()->comment('账号冷却截止时间');
            $table->timestamp('last_login_check_at')->nullable()->comment('最近登录态检查时间');
            $table->string('last_login_status', 40)->nullable()->comment('最近登录态检查结果');
            $table->text('last_error_message')->nullable()->comment('最近错误摘要');
            $table->json('meta')->nullable()->comment('扩展元数据 JSON');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->unique(['platform_id', 'external_id']);
            $table->comment('GEO 监测平台账号资源表');
        });

        Schema::create('geo_monitor_browser_profiles', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('account_id')->unique()->comment('绑定账号 ID，一对一')->constrained('geo_monitor_accounts')->cascadeOnDelete();
            $table->string('profile_key', 120)->comment('profile 逻辑键名');
            $table->string('storage_path', 500)->comment('profile 目录存储路径');
            $table->string('host_node', 120)->nullable()->comment('所属 sidecar 节点标识');
            $table->string('user_agent_summary', 255)->nullable()->comment('UA 摘要');
            $table->string('locale', 16)->default('zh-CN')->comment('浏览器语言区域');
            $table->string('timezone_id', 64)->default('Asia/Shanghai')->comment('浏览器时区 ID');
            $table->json('viewport')->nullable()->comment('视窗尺寸 JSON');
            $table->string('health_status', 32)->default('unknown')->index()->comment('profile 健康状态');
            $table->timestamp('last_maintained_at')->nullable()->comment('最近人工维护时间');
            $table->string('last_maintenance_via', 32)->nullable()->comment('最近维护方式：novnc/local_upload 等');
            $table->json('meta')->nullable()->comment('扩展元数据 JSON');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('GEO 监测浏览器 Profile 资源表');
        });

        Schema::create('geo_monitor_prompts', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('project_id')->comment('所属监测项目 ID')->constrained('geo_monitor_projects')->cascadeOnDelete();
            $table->string('code', 80)->comment('问题唯一 code，项目内唯一');
            $table->text('prompt_text')->comment('完整监测问题文本');
            $table->string('intent', 40)->default('generic')->index()->comment('问题意图分类');
            $table->json('keywords')->nullable()->comment('关联关键词 JSON');
            $table->string('target_product', 160)->nullable()->comment('目标产品名');
            $table->string('target_article_url', 500)->nullable()->comment('目标文章 URL');
            $table->string('locale', 16)->default('zh-CN')->comment('问题语言区域');
            $table->string('region', 64)->nullable()->comment('目标地区');
            $table->unsignedSmallInteger('priority')->default(0)->comment('调度优先级，越大越优先');
            $table->boolean('is_enabled')->default(true)->index()->comment('是否参与监测');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->unique(['project_id', 'code']);
            $table->comment('GEO 监测问题集表');
        });

        Schema::create('geo_monitor_runs', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('project_id')->comment('所属监测项目 ID')->constrained('geo_monitor_projects')->cascadeOnDelete();
            $table->foreignId('triggered_by_admin_id')->nullable()->comment('手动触发管理员 ID，计划触发为空')->constrained('admins')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index()->comment('批次状态：pending/running/succeeded/partial/failed 等');
            $table->json('platform_scope')->nullable()->comment('本批次覆盖的平台 code 列表');
            $table->unsignedInteger('prompt_count')->default(0)->comment('问题数量');
            $table->unsignedInteger('observation_count')->default(0)->comment('观测任务总数 prompt×platform');
            $table->unsignedInteger('success_count')->default(0)->comment('成功/部分成功观测数');
            $table->text('failed_summary')->nullable()->comment('失败状态汇总文本');
            $table->json('meta')->nullable()->comment('扩展元数据：触发来源、运行日志等');
            $table->timestamp('started_at')->nullable()->comment('批次开始时间');
            $table->timestamp('finished_at')->nullable()->comment('批次结束时间');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('GEO 监测批次运行表');
        });

        Schema::create('geo_monitor_observations', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('run_id')->comment('所属批次 ID')->constrained('geo_monitor_runs')->cascadeOnDelete();
            $table->foreignId('project_id')->comment('所属项目 ID，冗余便于查询')->constrained('geo_monitor_projects')->cascadeOnDelete();
            $table->foreignId('prompt_id')->comment('监测问题 ID')->constrained('geo_monitor_prompts')->cascadeOnDelete();
            $table->foreignId('platform_id')->comment('AI 平台 ID')->constrained('geo_monitor_platforms')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->comment('调度使用的账号 ID')->constrained('geo_monitor_accounts')->nullOnDelete();
            $table->text('prompt_text_snapshot')->comment('探测时的问题文本快照');
            $table->string('status', 40)->index()->comment('观测状态：pending/success/failed/partial 等');
            $table->string('login_status', 40)->default('unknown')->comment('登录态：logged_in/needs_login/captcha_required 等');
            $table->longText('answer_text')->nullable()->comment('AI 回答纯文本');
            $table->char('answer_hash', 64)->nullable()->index()->comment('回答内容 hash，用于去重对比');
            $table->text('error_message')->nullable()->comment('失败错误信息');
            $table->unsignedInteger('duration_ms')->default(0)->comment('探测耗时毫秒');
            $table->string('screenshot_path', 500)->nullable()->comment('截图证据相对路径');
            $table->string('html_path', 500)->nullable()->comment('HTML 证据相对路径');
            $table->string('raw_text_path', 500)->nullable()->comment('纯文本证据相对路径');
            $table->string('markdown_path', 500)->nullable()->comment('Markdown 证据相对路径');
            $table->json('meta')->nullable()->comment('sidecar 回传扩展字段 JSON');
            $table->timestamp('probed_at')->nullable()->comment('实际完成探测时间');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->index(['run_id', 'platform_id']);
            $table->index(['project_id', 'status']);
            $table->comment('GEO 监测单条观测结果表');
        });

        Schema::create('geo_monitor_citations', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('observation_id')->comment('所属观测 ID')->constrained('geo_monitor_observations')->cascadeOnDelete();
            $table->string('url', 2000)->comment('引用来源 URL');
            $table->string('domain', 255)->default('')->index()->comment('引用域名');
            $table->string('title', 500)->nullable()->comment('引用标题');
            $table->text('snippet')->nullable()->comment('引用摘要片段');
            $table->string('source_type', 40)->default('link')->comment('来源类型：link/card 等');
            $table->unsignedSmallInteger('position')->default(0)->comment('在回答中的引用顺序');
            $table->boolean('is_own_domain')->default(false)->index()->comment('是否我方主域引用');
            $table->boolean('is_competitor_domain')->default(false)->index()->comment('是否竞品域引用');
            $table->text('evidence_snippet')->nullable()->comment('判定依据上下文片段');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->index(['observation_id', 'position']);
            $table->comment('GEO 监测回答引用来源表');
        });

        Schema::create('geo_monitor_mentions', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('observation_id')->comment('所属观测 ID')->constrained('geo_monitor_observations')->cascadeOnDelete();
            $table->string('entity_name', 160)->comment('实体标准名称');
            $table->string('entity_type', 32)->default('other')->index()->comment('实体类型：brand/competitor/product 等');
            $table->text('mention_text')->comment('原文提及文本');
            $table->string('sentiment', 16)->nullable()->comment('情感倾向摘要');
            $table->text('context_snippet')->nullable()->comment('提及上下文片段');
            $table->unsignedSmallInteger('position')->default(0)->comment('在回答中的出现顺序');
            $table->boolean('is_recommendation')->default(false)->comment('是否表现为推荐语义');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->index(['observation_id', 'position']);
            $table->comment('GEO 监测品牌/竞品提及表');
        });

        Schema::create('geo_monitor_scores', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('project_id')->comment('所属项目 ID')->constrained('geo_monitor_projects')->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->comment('所属批次 ID，批次级评分时使用')->constrained('geo_monitor_runs')->nullOnDelete();
            $table->foreignId('observation_id')->nullable()->comment('所属观测 ID，观测级评分时使用')->constrained('geo_monitor_observations')->nullOnDelete();
            $table->string('score_version', 32)->default('v1')->comment('评分公式版本号');
            $table->json('metrics')->comment('指标快照 JSON');
            $table->timestamp('computed_at')->comment('评分计算时间');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->index(['project_id', 'computed_at']);
            $table->comment('GEO 监测引用度评分快照表');
        });

        Schema::create('geo_monitor_resource_assignments', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('observation_id')->unique()->comment('观测 ID，一条观测一条分配记录')->constrained('geo_monitor_observations')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->comment('实际使用账号 ID')->constrained('geo_monitor_accounts')->nullOnDelete();
            $table->foreignId('browser_profile_id')->nullable()->comment('实际使用 browser profile ID')->constrained('geo_monitor_browser_profiles')->nullOnDelete();
            $table->foreignId('proxy_endpoint_id')->nullable()->comment('实际使用代理 ID')->constrained('geo_monitor_proxy_endpoints')->nullOnDelete();
            $table->string('scheduler_strategy', 64)->nullable()->comment('调度策略标识');
            $table->timestamp('assigned_at')->comment('资源分配时间');
            $table->json('meta')->nullable()->comment('扩展元数据 JSON');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('GEO 监测观测资源分配审计表');
        });

        Schema::create('geo_monitor_profile_maintenance_events', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('account_id')->nullable()->comment('维护账号 ID')->constrained('geo_monitor_accounts')->nullOnDelete();
            $table->foreignId('browser_profile_id')->nullable()->comment('维护 profile ID')->constrained('geo_monitor_browser_profiles')->nullOnDelete();
            $table->foreignId('proxy_endpoint_id')->nullable()->comment('维护时绑定代理 ID')->constrained('geo_monitor_proxy_endpoints')->nullOnDelete();
            $table->string('trigger_reason', 40)->index()->comment('触发原因：needs_login/captcha/routine 等');
            $table->string('maintenance_via', 32)->default('other')->comment('维护方式：novnc/local_upload/bridge 等');
            $table->string('status', 32)->default('in_progress')->index()->comment('维护事件状态');
            $table->foreignId('operator_admin_id')->nullable()->comment('操作管理员 ID')->constrained('admins')->nullOnDelete();
            $table->string('egress_ip_summary', 120)->nullable()->comment('维护时代理出口 IP 脱敏摘要');
            $table->text('notes')->nullable()->comment('维护备注');
            $table->timestamp('started_at')->comment('维护开始时间');
            $table->timestamp('finished_at')->nullable()->comment('维护结束时间');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('GEO 监测 Profile 人工维护事件表');
        });

        $now = now();
        DB::table('geo_monitor_platforms')->insert([
            [
                'code' => 'doubao',
                'label' => '豆包',
                'selector_version' => '2026-06-03-poc-v5-doubao-fast',
                'chat_url' => 'https://www.doubao.com/chat/',
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'deepseek',
                'label' => 'DeepSeek',
                'selector_version' => '2026-06-03-poc-v1',
                'chat_url' => 'https://chat.deepseek.com/',
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'yuanbao',
                'label' => '腾讯元宝',
                'selector_version' => '2026-06-03-poc-v4-yuanbao-online',
                'chat_url' => 'https://yuanbao.tencent.com/chat',
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_monitor_profile_maintenance_events');
        Schema::dropIfExists('geo_monitor_resource_assignments');
        Schema::dropIfExists('geo_monitor_scores');
        Schema::dropIfExists('geo_monitor_mentions');
        Schema::dropIfExists('geo_monitor_citations');
        Schema::dropIfExists('geo_monitor_observations');
        Schema::dropIfExists('geo_monitor_runs');
        Schema::dropIfExists('geo_monitor_prompts');
        Schema::dropIfExists('geo_monitor_browser_profiles');
        Schema::dropIfExists('geo_monitor_accounts');
        Schema::dropIfExists('geo_monitor_proxy_endpoints');
        Schema::dropIfExists('geo_monitor_platforms');
        Schema::dropIfExists('geo_monitor_projects');
    }
};
