<?php

/**
 * URL 导入任务与日志表（缺失时创建）。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('url_import_jobs')) {
            Schema::create('url_import_jobs', function (Blueprint $table): void {
                $table->id()->comment('主键');
                $table->text('url')->comment('原始导入 URL');
                $table->text('normalized_url')->comment('规范化后的 URL');
                $table->string('source_domain')->default('')->comment('来源域名');
                $table->string('page_title')->default('')->comment('页面标题');
                $table->string('status', 20)->default('queued')->comment('任务状态');
                $table->string('current_step', 50)->default('queued')->comment('当前处理步骤');
                $table->integer('progress_percent')->default(0)->comment('进度百分比 0-100');
                $table->text('options_json')->default('')->comment('导入选项 JSON 字符串');
                $table->text('result_json')->default('')->comment('导入结果 JSON 字符串');
                $table->text('error_message')->default('')->comment('错误信息');
                $table->string('created_by', 100)->default('')->comment('创建人标识');
                $table->timestamp('started_at')->nullable()->comment('开始处理时间');
                $table->timestamp('finished_at')->nullable()->comment('结束时间');
                $table->timestamp('created_at')->nullable()->comment('创建时间');
                $table->timestamp('updated_at')->nullable()->comment('更新时间');

                $table->comment('URL 导入任务表');
            });
        }

        if (! Schema::hasTable('url_import_job_logs')) {
            Schema::create('url_import_job_logs', function (Blueprint $table): void {
                $table->id()->comment('主键');
                $table->foreignId('job_id')->comment('所属导入任务 ID')->constrained('url_import_jobs')->cascadeOnDelete();
                $table->string('step', 50)->default('queued')->comment('日志所属处理步骤');
                $table->string('level', 20)->default('info')->comment('日志级别');
                $table->text('message')->comment('日志正文');
                $table->timestamp('created_at')->nullable()->comment('创建时间');

                $table->comment('URL 导入任务日志表');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('url_import_job_logs');
        Schema::dropIfExists('url_import_jobs');
    }
};
