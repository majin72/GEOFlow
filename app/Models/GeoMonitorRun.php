<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoMonitorRun extends Model
{
    protected $table = 'geo_monitor_runs';

    protected $fillable = [
        'project_id',
        'triggered_by_admin_id',
        'status',
        'platform_scope',
        'prompt_count',
        'observation_count',
        'success_count',
        'failed_summary',
        'meta',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'triggered_by_admin_id' => 'integer',
            'platform_scope' => 'array',
            'prompt_count' => 'integer',
            'observation_count' => 'integer',
            'success_count' => 'integer',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * 触发运行的管理员。
     */
    public function triggeredByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'triggered_by_admin_id');
    }

    /**
     * 批次内的观测结果。
     */
    public function observations(): HasMany
    {
        return $this->hasMany(GeoMonitorObservation::class, 'run_id');
    }

    /**
     * 批次级评分快照。
     */
    public function scores(): HasMany
    {
        return $this->hasMany(GeoMonitorScore::class, 'run_id');
    }
}
