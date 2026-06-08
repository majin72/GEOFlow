<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoMonitorAlert extends Model
{
    protected $table = 'geo_monitor_alerts';

    protected $fillable = [
        'alert_type',
        'severity',
        'fingerprint',
        'title',
        'message',
        'context',
        'project_id',
        'run_id',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'run_id' => 'integer',
            'context' => 'array',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * 关联监测项目（若有）。
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorProject::class, 'project_id');
    }

    /**
     * 关联批次运行（若有）。
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorRun::class, 'run_id');
    }
}
