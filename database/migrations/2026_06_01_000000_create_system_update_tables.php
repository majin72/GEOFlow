<?php

/**
 * 系统更新中心：更新运行记录与备份包表。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_update_runs')) {
            Schema::create('system_update_runs', function (Blueprint $table): void {
                $table->id()->comment('主键');
                $table->string('run_uuid', 64)->unique()->comment('更新运行 UUID，全局唯一');
                $table->string('action', 30)->index()->comment('更新动作类型');
                $table->string('status', 30)->index()->comment('运行状态');
                $table->string('current_version', 50)->nullable()->comment('更新前版本号');
                $table->string('target_version', 50)->nullable()->comment('目标版本号');
                $table->string('current_commit', 80)->nullable()->comment('更新前 Git commit');
                $table->string('target_commit', 80)->nullable()->comment('目标 Git commit');
                $table->string('deployment_mode', 60)->nullable()->comment('部署模式标识');
                $table->string('risk_level', 20)->nullable()->comment('风险评估等级');
                $table->json('plan_json')->nullable()->comment('更新计划 JSON');
                $table->string('plan_path', 500)->nullable()->comment('计划文件路径');
                $table->string('backup_path', 500)->nullable()->comment('本次运行备份目录路径');
                $table->string('log_path', 500)->nullable()->comment('运行日志文件路径');
                $table->text('error_message')->nullable()->comment('失败错误信息');
                $table->unsignedBigInteger('started_by_admin_id')->nullable()->index()->comment('发起管理员 ID');
                $table->timestamp('started_at')->nullable()->comment('开始时间');
                $table->timestamp('finished_at')->nullable()->comment('结束时间');
                $table->timestamp('created_at')->nullable()->comment('创建时间');
                $table->timestamp('updated_at')->nullable()->comment('更新时间');

                $table->comment('系统更新运行记录表');
            });
        }

        if (! Schema::hasTable('system_update_backups')) {
            Schema::create('system_update_backups', function (Blueprint $table): void {
                $table->id()->comment('主键');
                $table->string('backup_uuid', 64)->unique()->comment('备份包 UUID');
                $table->foreignId('run_id')->nullable()->comment('关联更新运行 ID')->constrained('system_update_runs')->nullOnDelete();
                $table->string('from_version', 50)->nullable()->comment('备份前版本号');
                $table->string('to_version', 50)->nullable()->comment('备份后/目标版本号');
                $table->string('from_commit', 80)->nullable()->comment('备份前 commit');
                $table->string('to_commit', 80)->nullable()->comment('备份后 commit');
                $table->string('backup_path', 500)->comment('备份根目录路径');
                $table->string('manifest_path', 500)->comment('备份清单文件路径');
                $table->string('files_archive_path', 500)->nullable()->comment('代码文件归档路径');
                $table->string('database_dump_path', 500)->nullable()->comment('数据库 dump 路径');
                $table->unsignedInteger('file_count')->default(0)->comment('备份文件数量');
                $table->unsignedBigInteger('total_bytes')->default(0)->comment('备份总字节数');
                $table->string('status', 30)->default('available')->index()->comment('备份状态');
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->index()->comment('创建人管理员 ID');
                $table->timestamp('created_at')->nullable()->comment('创建时间');
                $table->timestamp('updated_at')->nullable()->comment('更新时间');

                $table->comment('系统更新备份包表');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_update_backups');
        Schema::dropIfExists('system_update_runs');
    }
};
