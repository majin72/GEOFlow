<?php

/**
 * 知识分块表增加结构化切片元数据字段。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_chunks')) {
            return;
        }

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_chunks', 'chunk_title')) {
                $table->string('chunk_title', 255)->default('')->after('content_hash')->comment('分块标题');
            }
            if (! Schema::hasColumn('knowledge_chunks', 'section_path')) {
                $table->string('section_path', 500)->default('')->after('chunk_title')->comment('文档章节路径');
            }
            if (! Schema::hasColumn('knowledge_chunks', 'chunk_strategy')) {
                $table->string('chunk_strategy', 50)->default('structured_rule')->after('section_path')->comment('分块策略标识');
            }
            if (! Schema::hasColumn('knowledge_chunks', 'metadata_json')) {
                $table->text('metadata_json')->nullable()->after('chunk_strategy')->comment('分块扩展元数据 JSON');
            }
            if (! Schema::hasColumn('knowledge_chunks', 'source_hash')) {
                $table->string('source_hash', 64)->default('')->after('metadata_json')->comment('源内容 hash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_chunks')) {
            return;
        }

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            foreach (['source_hash', 'metadata_json', 'chunk_strategy', 'section_path', 'chunk_title'] as $column) {
                if (Schema::hasColumn('knowledge_chunks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
