<?php

/**
 * AI Ops 运行表增加计划流式输出快照字段。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_ai_ops_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('admin_ai_ops_runs', 'plan_stream_snapshot')) {
                $table->longText('plan_stream_snapshot')->nullable()->after('error_message')->comment('计划阶段流式输出快照');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_ai_ops_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('admin_ai_ops_runs', 'plan_stream_snapshot')) {
                $table->dropColumn('plan_stream_snapshot');
            }
        });
    }
};
