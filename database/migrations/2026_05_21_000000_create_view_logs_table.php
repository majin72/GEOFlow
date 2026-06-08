<?php

/**
 * 前台访问日志表（文章浏览与路由访问审计）。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('view_logs')) {
            return;
        }

        Schema::create('view_logs', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('article_id')->nullable()->index()->comment('关联文章 ID，非文章页为空');
            $table->string('source', 32)->default('local')->index()->comment('访问来源标识：local 等');
            $table->string('method', 16)->default('GET')->comment('HTTP 方法');
            $table->string('path', 2048)->default('')->comment('请求路径');
            $table->string('route_name', 128)->nullable()->index()->comment('Laravel 路由名');
            $table->unsignedSmallInteger('status_code')->default(200)->index()->comment('HTTP 响应状态码');
            $table->string('ip_address', 64)->default('')->index()->comment('访客 IP 地址');
            $table->text('user_agent')->nullable()->comment('User-Agent');
            $table->string('referer', 2048)->nullable()->comment('Referer 来源页');
            $table->timestamp('created_at')->nullable()->index()->comment('访问时间');

            $table->comment('前台访问日志表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('view_logs');
    }
};
