<?php

/**
 * URL 导入任务日志表增加 step 字段。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('url_import_job_logs') && ! Schema::hasColumn('url_import_job_logs', 'step')) {
            Schema::table('url_import_job_logs', function (Blueprint $table): void {
                $table->string('step', 50)->default('queued')->after('job_id')->comment('日志所属处理步骤');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('url_import_job_logs') && Schema::hasColumn('url_import_job_logs', 'step')) {
            Schema::table('url_import_job_logs', function (Blueprint $table): void {
                $table->dropColumn('step');
            });
        }
    }
};
