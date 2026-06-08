<?php

/**
 * 为 sqlite 等非 pgsql 测试/开发环境补齐 article_images 关联表。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为 sqlite 等非 pgsql 测试/开发环境补齐 article_images 表。
     */
    public function up(): void
    {
        if (
            DB::getDriverName() === 'pgsql'
            || Schema::hasTable('article_images')
            || ! Schema::hasTable('articles')
            || ! Schema::hasTable('images')
        ) {
            return;
        }

        Schema::create('article_images', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('article_id')->comment('文章 ID');
            $table->unsignedBigInteger('image_id')->comment('图片 ID');
            $table->integer('position')->default(0)->comment('图片在文章中的排序位置');
            $table->timestamp('created_at')->nullable()->useCurrent()->comment('创建时间');

            $table->foreign('article_id')->references('id')->on('articles')->onDelete('cascade');
            $table->foreign('image_id')->references('id')->on('images');

            $table->comment('文章与图片关联表（非 PostgreSQL 环境补齐）');
        });
    }

    /**
     * 回滚非 pgsql 环境下补齐的 article_images 表。
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            return;
        }

        Schema::dropIfExists('article_images');
    }
};
