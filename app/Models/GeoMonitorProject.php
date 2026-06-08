<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GeoMonitorProject extends Model
{
    protected $table = 'geo_monitor_projects';

    protected $fillable = [
        'name',
        'slug',
        'brand_name',
        'primary_domain',
        'competitor_domains',
        'competitor_brands',
        'product_keywords',
        'status',
        'notes',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'competitor_domains' => 'array',
            'competitor_brands' => 'array',
            'product_keywords' => 'array',
            'created_by_admin_id' => 'integer',
        ];
    }

    /**
     * 创建该项目的管理员。
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * 项目下监测问题集。
     */
    public function prompts(): HasMany
    {
        return $this->hasMany(GeoMonitorPrompt::class, 'project_id');
    }

    /**
     * 项目下批次运行记录。
     */
    public function runs(): HasMany
    {
        return $this->hasMany(GeoMonitorRun::class, 'project_id');
    }

    /**
     * 项目级评分快照。
     */
    public function scores(): HasMany
    {
        return $this->hasMany(GeoMonitorScore::class, 'project_id');
    }

    /**
     * 项目定时监测计划（每项目至多一条）。
     */
    public function schedule(): HasOne
    {
        return $this->hasOne(GeoMonitorSchedule::class, 'project_id');
    }
}
