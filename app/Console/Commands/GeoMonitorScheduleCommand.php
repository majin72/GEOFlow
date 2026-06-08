<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GeoFlow\GeoMonitoring\GeoMonitorScheduleService;
use Illuminate\Console\Command;

/**
 * 扫描 GEO 监测定时计划并按窗口去重创建批次。
 */
class GeoMonitorScheduleCommand extends Command
{
    protected $signature = 'geoflow:geo-monitor-schedule';

    protected $description = 'Dispatch due GEO monitor schedules and create probe runs';

    /**
     * @param  GeoMonitorScheduleService  $scheduleService  计划服务
     */
    public function __construct(
        private readonly GeoMonitorScheduleService $scheduleService,
    ) {
        parent::__construct();
    }

    /**
     * 执行计划扫描。
     */
    public function handle(): int
    {
        $result = $this->scheduleService->dispatchDueSchedules();

        $this->info(sprintf(
            'GEO monitor scheduler done: dispatched=%d, skipped=%d',
            $result['dispatched'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
