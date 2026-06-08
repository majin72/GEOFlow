<?php

/**
 * GEO 引用监测：观测记录增加重跑来源关联，保留历史证据链。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为观测记录增加重跑来源关联，保留历史证据链。
     */
    public function up(): void
    {
        Schema::table('geo_monitor_observations', function (Blueprint $table): void {
            $table->foreignId('retried_from_observation_id')
                ->nullable()
                ->after('account_id')
                ->comment('重跑来源观测 ID，指向被重试的原始记录')
                ->constrained('geo_monitor_observations')
                ->nullOnDelete();
        });
    }

    /**
     * 回滚重跑来源字段。
     */
    public function down(): void
    {
        Schema::table('geo_monitor_observations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('retried_from_observation_id');
        });
    }
};
