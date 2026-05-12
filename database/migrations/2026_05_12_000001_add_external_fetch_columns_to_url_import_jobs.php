<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 增加外部浏览器抓取结果字段。
     *
     * fetched_markdown 存放 opencli 返回的 Markdown，fetch_source 标记本次抓取来源：
     * - direct：普通 HTTP 直连
     * - external_primary：域名白名单命中，优先走外部浏览器
     * - external_fallback：普通 HTTP 失败后回退到外部浏览器
     */
    public function up(): void
    {
        Schema::table('url_import_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('url_import_jobs', 'fetched_markdown')) {
                $table->text('fetched_markdown')->nullable()->after('result_json');
            }

            if (! Schema::hasColumn('url_import_jobs', 'fetch_source')) {
                $table->string('fetch_source', 32)->default('direct')->after('fetched_markdown');
            }
        });
    }

    /**
     * 回滚外部浏览器抓取结果字段。
     */
    public function down(): void
    {
        Schema::table('url_import_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('url_import_jobs', 'fetch_source')) {
                $table->dropColumn('fetch_source');
            }

            if (Schema::hasColumn('url_import_jobs', 'fetched_markdown')) {
                $table->dropColumn('fetched_markdown');
            }
        });
    }
};
