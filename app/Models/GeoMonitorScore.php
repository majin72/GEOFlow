<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoMonitorScore extends Model
{
    protected $table = 'geo_monitor_scores';

    protected $fillable = [
        'project_id',
        'run_id',
        'observation_id',
        'score_version',
        'metrics',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'run_id' => 'integer',
            'observation_id' => 'integer',
            'metrics' => 'array',
            'computed_at' => 'datetime',
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
     * 所属批次（可选）。
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorRun::class, 'run_id');
    }

    /**
     * 所属观测（可选）。
     */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorObservation::class, 'observation_id');
    }
}
