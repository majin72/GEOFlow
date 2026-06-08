<?php

/**
 * 后台 AI Ops 工具调用人工审批表。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ai_ops_tool_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('主键 UUID');
            $table->foreignId('run_id')->comment('所属 AI Ops 运行 ID')->constrained('admin_ai_ops_runs')->cascadeOnDelete();
            $table->foreignId('admin_id')->comment('审批管理员 ID')->constrained('admins')->cascadeOnDelete();
            $table->string('tool_name', 128)->comment('待审批工具名称');
            $table->longText('arguments_json')->comment('工具参数 JSON');
            $table->string('args_fingerprint', 64)->comment('参数指纹，用于去重');
            $table->string('risk_label', 160)->nullable()->comment('风险标签说明');
            $table->string('status', 32)->default('pending')->index()->comment('审批状态：pending/approved/rejected 等');
            $table->timestamp('expires_at')->comment('审批过期时间');
            $table->timestamp('decided_at')->nullable()->comment('审批决策时间');
            $table->text('rejection_reason')->nullable()->comment('拒绝原因');
            $table->longText('executed_output')->nullable()->comment('工具执行结果');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->index(['run_id', 'status']);
            $table->comment('后台 AI Ops 工具调用审批表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ai_ops_tool_approvals');
    }
};
