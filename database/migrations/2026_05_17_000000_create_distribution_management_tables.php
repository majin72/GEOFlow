<?php

/**
 * 文章分发管理：渠道、密钥、任务绑定、分发记录与日志表。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distribution_channels')) {
            Schema::create('distribution_channels', function (Blueprint $table): void {
                $table->id()->comment('主键');
                $table->string('name', 120)->comment('分发渠道名称');
                $table->string('domain', 255)->comment('目标站点域名');
                $table->string('endpoint_url', 500)->comment('分发 API 端点 URL');
                $table->string('channel_type', 60)->default('geoflow_agent')->comment('渠道类型');
                $table->string('template_key', 120)->nullable()->comment('使用的模板键');
                $table->json('site_settings')->nullable()->comment('远端站点展示设置 JSON');
                $table->json('channel_config')->nullable()->comment('渠道专有配置 JSON');
                $table->string('status', 30)->default('active')->index()->comment('渠道状态');
                $table->text('description')->nullable()->comment('渠道说明');
                $table->string('last_health_status', 30)->nullable()->comment('最近健康检查状态');
                $table->timestamp('last_health_checked_at')->nullable()->comment('最近健康检查时间');
                $table->text('last_error_message')->nullable()->comment('最近错误信息');
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->index()->comment('创建人管理员 ID');
                $table->timestamp('created_at')->nullable()->comment('创建时间');
                $table->timestamp('updated_at')->nullable()->comment('更新时间');

                $table->comment('文章分发渠道表');
            });
        }

        if (! Schema::hasTable('distribution_channel_secrets')) {
            Schema::create('distribution_channel_secrets', function (Blueprint $table): void {
                $table->id()->comment('主键');
                $table->foreignId('distribution_channel_id')->comment('所属分发渠道 ID')->constrained('distribution_channels')->cascadeOnDelete();
                $table->string('key_id', 80)->unique()->comment('密钥标识 ID');
                $table->text('secret_ciphertext')->comment('加密后的密钥密文');
                $table->string('status', 30)->default('active')->index()->comment('密钥状态');
                $table->json('scopes')->nullable()->comment('授权范围 JSON');
                $table->timestamp('last_used_at')->nullable()->comment('最近使用时间');
                $table->timestamp('created_at')->nullable()->comment('创建时间');
                $table->timestamp('updated_at')->nullable()->comment('更新时间');

                $table->comment('分发渠道 API 密钥表');
            });
        }

        if (! Schema::hasTable('task_distribution_channels')) {
            Schema::create('task_distribution_channels', function (Blueprint $table): void {
                $table->id()->comment('主键');
                $table->foreignId('task_id')->comment('内容任务 ID')->constrained('tasks')->cascadeOnDelete();
                $table->foreignId('distribution_channel_id')->comment('分发渠道 ID')->constrained('distribution_channels')->cascadeOnDelete();
                $table->string('trigger', 60)->default('after_local_publish')->comment('分发触发时机');
                $table->string('remote_status', 40)->default('follow_local')->comment('远端文章状态策略');
                $table->string('failure_policy', 60)->default('ignore_distribution_failure')->comment('失败处理策略');
                $table->unsignedSmallInteger('max_attempts')->default(3)->comment('最大重试次数');
                $table->timestamp('created_at')->nullable()->comment('创建时间');
                $table->timestamp('updated_at')->nullable()->comment('更新时间');

                $table->unique(['task_id', 'distribution_channel_id'], 'task_distribution_channels_unique');
                $table->comment('任务与分发渠道绑定表');
            });
        }

        if (! Schema::hasTable('article_distributions')) {
            Schema::create('article_distributions', function (Blueprint $table): void {
                $table->id()->comment('主键');
                $table->foreignId('article_id')->comment('文章 ID')->constrained('articles')->cascadeOnDelete();
                $table->foreignId('distribution_channel_id')->comment('分发渠道 ID')->constrained('distribution_channels')->cascadeOnDelete();
                $table->string('action', 30)->default('publish')->comment('分发动作：publish/update 等');
                $table->string('status', 30)->default('queued')->index()->comment('分发状态');
                $table->string('remote_id', 120)->nullable()->comment('远端平台文章 ID');
                $table->string('remote_url', 500)->nullable()->comment('远端文章 URL');
                $table->json('remote_meta')->nullable()->comment('远端回传元数据 JSON');
                $table->string('idempotency_key', 120)->unique()->comment('幂等键');
                $table->unsignedInteger('attempt_count')->default(0)->comment('已尝试次数');
                $table->timestamp('next_retry_at')->nullable()->index()->comment('下次重试时间');
                $table->timestamp('last_attempt_at')->nullable()->comment('最近尝试时间');
                $table->text('last_error_message')->nullable()->comment('最近错误信息');
                $table->string('payload_hash', 64)->nullable()->comment('请求 payload hash');
                $table->timestamp('created_at')->nullable()->comment('创建时间');
                $table->timestamp('updated_at')->nullable()->comment('更新时间');

                $table->unique(['article_id', 'distribution_channel_id', 'action'], 'article_distribution_unique');
                $table->comment('文章分发执行记录表');
            });
        }

        if (! Schema::hasTable('distribution_logs')) {
            Schema::create('distribution_logs', function (Blueprint $table): void {
                $table->id()->comment('主键');
                $table->unsignedBigInteger('distribution_channel_id')->nullable()->index()->comment('分发渠道 ID');
                $table->unsignedBigInteger('article_distribution_id')->nullable()->index()->comment('文章分发记录 ID');
                $table->unsignedBigInteger('article_id')->nullable()->index()->comment('文章 ID');
                $table->string('level', 20)->default('info')->comment('日志级别');
                $table->string('event', 120)->nullable()->comment('日志事件标识');
                $table->text('message')->comment('日志正文');
                $table->json('context')->nullable()->comment('附加上下文 JSON');
                $table->timestamp('created_at')->nullable()->comment('创建时间');

                $table->comment('文章分发运行日志表');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_logs');
        Schema::dropIfExists('article_distributions');
        Schema::dropIfExists('task_distribution_channels');
        Schema::dropIfExists('distribution_channel_secrets');
        Schema::dropIfExists('distribution_channels');
    }
};
