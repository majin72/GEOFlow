<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoMonitorSchedule extends Model
{
    protected $table = 'geo_monitor_schedules';

    protected $fillable = [
        'project_id',
        'frequency',
        'platform_scope',
        'timezone',
        'run_time',
        'weekday',
        'is_enabled',
        'next_run_at',
        'last_run_at',
        'last_run_id',
        'last_dedupe_key',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'platform_scope' => 'array',
            'weekday' => 'integer',
            'is_enabled' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'last_run_id' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * 所属监测项目。
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorProject::class, 'project_id');
    }

    /**
     * 最近一次由计划触发的批次。
     */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorRun::class, 'last_run_id');
    }
}
