<?php

/**
 * AI Ops 运行表增加所选 AI 模型外键。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_ai_ops_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('admin_ai_ops_runs', 'ai_model_id')) {
                $table->foreignId('ai_model_id')->nullable()->after('admin_id')->comment('使用的 AI 模型 ID')->constrained('ai_models')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_ai_ops_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('admin_ai_ops_runs', 'ai_model_id')) {
                $table->dropConstrainedForeignId('ai_model_id');
            }
        });
    }
};
