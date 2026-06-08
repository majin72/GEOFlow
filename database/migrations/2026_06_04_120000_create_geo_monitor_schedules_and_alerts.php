<?php

/**
 * GEO 引用监测：定时计划与异常告警表。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_monitor_schedules', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('project_id')->unique()->comment('监测项目 ID，每项目至多一条计划')->constrained('geo_monitor_projects')->cascadeOnDelete();
            $table->string('frequency', 16)->default('manual')->index()->comment('计划频率：manual/daily/weekly');
            $table->json('platform_scope')->nullable()->comment('限定平台 code 列表，空表示全部已启用平台');
            $table->string('timezone', 64)->default('Asia/Shanghai')->comment('计划执行时区');
            $table->string('run_time', 5)->default('09:00')->comment('每日/每周触发时刻 HH:MM');
            $table->unsignedTinyInteger('weekday')->nullable()->comment('每周计划星期几，1=周一…7=周日');
            $table->boolean('is_enabled')->default(false)->index()->comment('是否启用自动调度');
            $table->timestamp('next_run_at')->nullable()->index()->comment('下次计划触发时间（UTC 存储）');
            $table->timestamp('last_run_at')->nullable()->comment('上次由计划触发的时间');
            $table->foreignId('last_run_id')->nullable()->comment('上次计划触发的批次 ID')->constrained('geo_monitor_runs')->nullOnDelete();
            $table->string('last_dedupe_key', 64)->nullable()->comment('窗口去重键，防止同一日历窗口重复触发');
            $table->json('meta')->nullable()->comment('扩展元数据');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('GEO 监测定时计划表');
        });

        Schema::create('geo_monitor_alerts', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->string('alert_type', 64)->index()->comment('告警类型，如 sidecar_unreachable/consecutive_failures');
            $table->string('severity', 16)->default('warning')->index()->comment('严重级别：info/warning/critical');
            $table->string('fingerprint', 128)->index()->comment('告警去重指纹');
            $table->string('title', 255)->comment('告警标题');
            $table->text('message')->comment('告警正文说明');
            $table->json('context')->nullable()->comment('附加上下文 JSON');
            $table->foreignId('project_id')->nullable()->comment('关联监测项目 ID')->constrained('geo_monitor_projects')->nullOnDelete();
            $table->foreignId('run_id')->nullable()->comment('关联批次运行 ID')->constrained('geo_monitor_runs')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable()->comment('运维确认已读时间');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->index(['fingerprint', 'created_at']);
            $table->comment('GEO 监测异常告警记录表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_monitor_alerts');
        Schema::dropIfExists('geo_monitor_schedules');
    }
};
