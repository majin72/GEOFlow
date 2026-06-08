<?php

/**
 * 分发渠道与文章分发记录增加 WordPress 扩展配置字段。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_channels') && ! Schema::hasColumn('distribution_channels', 'channel_config')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->json('channel_config')->nullable()->after('site_settings')->comment('渠道专有配置 JSON，如 WordPress 参数');
            });
        }

        if (Schema::hasTable('article_distributions') && ! Schema::hasColumn('article_distributions', 'remote_meta')) {
            Schema::table('article_distributions', function (Blueprint $table): void {
                $table->json('remote_meta')->nullable()->after('remote_url')->comment('远端平台回传元数据 JSON');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('article_distributions') && Schema::hasColumn('article_distributions', 'remote_meta')) {
            Schema::table('article_distributions', function (Blueprint $table): void {
                $table->dropColumn('remote_meta');
            });
        }

        if (Schema::hasTable('distribution_channels') && Schema::hasColumn('distribution_channels', 'channel_config')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->dropColumn('channel_config');
            });
        }
    }
};
