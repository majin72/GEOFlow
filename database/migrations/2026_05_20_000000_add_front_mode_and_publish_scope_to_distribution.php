<?php

/**
 * 分发渠道增加前台模式；任务表增加发布范围字段。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_channels') && ! Schema::hasColumn('distribution_channels', 'front_mode')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->string('front_mode', 30)->default('static')->after('channel_type')->comment('前台渲染模式：static 等');
            });

            DB::table('distribution_channels')
                ->whereNull('front_mode')
                ->orWhere('front_mode', '')
                ->update(['front_mode' => 'static']);
        }

        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'publish_scope')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->string('publish_scope', 40)->default('local_and_distribution')->after('status')->comment('发布范围：local_only/local_and_distribution 等');
            });

            DB::table('tasks')
                ->whereNull('publish_scope')
                ->orWhere('publish_scope', '')
                ->update(['publish_scope' => 'local_and_distribution']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'publish_scope')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropColumn('publish_scope');
            });
        }

        if (Schema::hasTable('distribution_channels') && Schema::hasColumn('distribution_channels', 'front_mode')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->dropColumn('front_mode');
            });
        }
    }
};
