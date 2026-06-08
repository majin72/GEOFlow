<?php

/**
 * 后台 AI Ops 会话、运行、步骤与附件表。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ai_ops_sessions', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('admin_id')->comment('所属管理员 ID')->constrained('admins')->cascadeOnDelete();
            $table->string('title', 160)->default('')->comment('会话标题');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('后台 AI Ops 会话表');
        });

        Schema::create('admin_ai_ops_runs', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('session_id')->comment('所属会话 ID')->constrained('admin_ai_ops_sessions')->cascadeOnDelete();
            $table->foreignId('admin_id')->comment('发起管理员 ID')->constrained('admins')->cascadeOnDelete();
            $table->string('status', 32)->default('queued')->index()->comment('运行状态');
            $table->text('input_text')->nullable()->comment('用户输入文本');
            $table->json('plan')->nullable()->comment('AI 生成的执行计划 JSON');
            $table->text('result_summary')->nullable()->comment('运行结果摘要');
            $table->text('error_message')->nullable()->comment('失败错误信息');
            $table->timestamp('confirmed_at')->nullable()->comment('用户确认执行时间');
            $table->timestamp('cancel_requested_at')->nullable()->comment('取消请求时间');
            $table->timestamp('started_at')->nullable()->comment('开始执行时间');
            $table->timestamp('finished_at')->nullable()->comment('结束时间');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('后台 AI Ops 运行记录表');
        });

        Schema::create('admin_ai_ops_steps', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('run_id')->comment('所属运行 ID')->constrained('admin_ai_ops_runs')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0)->comment('步骤顺序');
            $table->string('type', 32)->default('system')->comment('步骤类型');
            $table->string('status', 32)->default('pending')->index()->comment('步骤状态');
            $table->string('title', 160)->default('')->comment('步骤标题');
            $table->text('input_summary')->nullable()->comment('输入摘要');
            $table->text('output_summary')->nullable()->comment('输出摘要');
            $table->text('error_message')->nullable()->comment('失败错误信息');
            $table->json('meta')->nullable()->comment('扩展元数据 JSON');
            $table->timestamp('started_at')->nullable()->comment('开始时间');
            $table->timestamp('finished_at')->nullable()->comment('结束时间');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->index(['run_id', 'position']);
            $table->comment('后台 AI Ops 运行步骤表');
        });

        Schema::create('admin_ai_ops_attachments', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('run_id')->comment('所属运行 ID')->constrained('admin_ai_ops_runs')->cascadeOnDelete();
            $table->string('disk', 64)->default('local')->comment('存储 disk 名');
            $table->string('path', 500)->comment('文件相对路径');
            $table->string('original_name', 255)->default('')->comment('原始文件名');
            $table->string('mime_type', 160)->default('')->comment('MIME 类型');
            $table->unsignedBigInteger('size')->default(0)->comment('文件大小字节');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('后台 AI Ops 运行附件表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ai_ops_attachments');
        Schema::dropIfExists('admin_ai_ops_steps');
        Schema::dropIfExists('admin_ai_ops_runs');
        Schema::dropIfExists('admin_ai_ops_sessions');
    }
};
