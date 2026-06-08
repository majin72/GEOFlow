<?php

/**
 * Artisan 自定义命令注册（闭包命令或后续类命令）。
 */

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Horizon 监控快照：用于沉淀队列吞吐、等待等时序指标。
 */
Schedule::command('horizon:snapshot')->everyFiveMinutes();

/**
 * GeoFlow 任务调度：每分钟扫描一次可执行任务并入队（对齐 bak cron 逻辑）。
 */
Schedule::command('geoflow:schedule-tasks')->everyMinute();

/**
 * GEO 监测定时计划：每分钟扫描到期计划并创建批次。
 */
Schedule::command('geoflow:geo-monitor-schedule')->everyMinute();

/**
 * 前台静态 sitemap：生产环境每日凌晨重写 storage/app/public/sitemap.xml（需服务器 cron 运行 `schedule:run`）。
 */
Schedule::command('geoflow:generate-static-sitemap')
    ->dailyAt('02:30')
    ->environments(['production']);
