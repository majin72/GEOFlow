<?php

/**
 * 补齐 view_logs 表缺失字段（兼容旧库结构）。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('view_logs')) {
            return;
        }

        Schema::table('view_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('view_logs', 'source')) {
                $table->string('source', 32)->default('local')->index()->after('article_id')->comment('访问来源标识');
            }
            if (! Schema::hasColumn('view_logs', 'method')) {
                $table->string('method', 16)->default('GET')->after('source')->comment('HTTP 方法');
            }
            if (! Schema::hasColumn('view_logs', 'path')) {
                $table->string('path', 2048)->default('')->after('method')->comment('请求路径');
            }
            if (! Schema::hasColumn('view_logs', 'route_name')) {
                $table->string('route_name', 128)->nullable()->index()->after('path')->comment('Laravel 路由名');
            }
            if (! Schema::hasColumn('view_logs', 'status_code')) {
                $table->unsignedSmallInteger('status_code')->default(200)->index()->after('route_name')->comment('HTTP 响应状态码');
            }
            if (! Schema::hasColumn('view_logs', 'referer')) {
                $table->string('referer', 2048)->nullable()->after('user_agent')->comment('Referer 来源页');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('view_logs')) {
            return;
        }

        Schema::table('view_logs', function (Blueprint $table): void {
            foreach (['referer', 'status_code', 'route_name', 'path', 'method', 'source'] as $column) {
                if (Schema::hasColumn('view_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
