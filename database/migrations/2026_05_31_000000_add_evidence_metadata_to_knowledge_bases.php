<?php

/**
 * 知识库表增加来源与审核元数据字段。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_bases')) {
            return;
        }

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_bases', 'source_name')) {
                $table->string('source_name', 150)->nullable()->comment('知识来源名称');
            }
            if (! Schema::hasColumn('knowledge_bases', 'source_url')) {
                $table->string('source_url', 500)->nullable()->comment('知识来源 URL');
            }
            if (! Schema::hasColumn('knowledge_bases', 'source_type')) {
                $table->string('source_type', 50)->default('document')->comment('来源类型：document/url 等');
            }
            if (! Schema::hasColumn('knowledge_bases', 'business_line')) {
                $table->string('business_line', 100)->nullable()->comment('业务线标识');
            }
            if (! Schema::hasColumn('knowledge_bases', 'effective_date')) {
                $table->date('effective_date')->nullable()->comment('知识生效日期');
            }
            if (! Schema::hasColumn('knowledge_bases', 'risk_level')) {
                $table->string('risk_level', 20)->default('medium')->comment('内容风险等级');
            }
            if (! Schema::hasColumn('knowledge_bases', 'review_status')) {
                $table->string('review_status', 20)->default('unreviewed')->comment('审核状态');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_bases')) {
            return;
        }

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            foreach (['review_status', 'risk_level', 'effective_date', 'business_line', 'source_type', 'source_url', 'source_name'] as $column) {
                if (Schema::hasColumn('knowledge_bases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
