<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GeoMonitorObservation extends Model
{
    protected $table = 'geo_monitor_observations';

    protected $fillable = [
        'run_id',
        'project_id',
        'prompt_id',
        'platform_id',
        'account_id',
        'retried_from_observation_id',
        'prompt_text_snapshot',
        'status',
        'login_status',
        'answer_text',
        'answer_hash',
        'error_message',
        'duration_ms',
        'screenshot_path',
        'html_path',
        'raw_text_path',
        'markdown_path',
        'meta',
        'probed_at',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'project_id' => 'integer',
            'prompt_id' => 'integer',
            'platform_id' => 'integer',
            'account_id' => 'integer',
            'retried_from_observation_id' => 'integer',
            'duration_ms' => 'integer',
            'meta' => 'array',
            'probed_at' => 'datetime',
        ];
    }

    /**
     * 所属批次运行。
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorRun::class, 'run_id');
    }

    /**
     * 所属监测项目。
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorProject::class, 'project_id');
    }

    /**
     * 对应问题。
     */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorPrompt::class, 'prompt_id');
    }

    /**
     * 目标 AI 平台。
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorPlatform::class, 'platform_id');
    }

    /**
     * 使用的采集账号。
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorAccount::class, 'account_id');
    }

    /**
     * 本条观测重跑所来源的观测（若有）。
     */
    public function retriedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retried_from_observation_id');
    }

    /**
     * 由本条观测触发的后续重跑记录。
     */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retried_from_observation_id')->orderBy('id');
    }

    /**
     * 从回答中抽取的引用来源。
     */
    public function citations(): HasMany
    {
        return $this->hasMany(GeoMonitorCitation::class, 'observation_id')->orderBy('position');
    }

    /**
     * 品牌/竞品提及记录。
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(GeoMonitorMention::class, 'observation_id')->orderBy('position');
    }

    /**
     * 本次观测使用的账号/profile/代理分配。
     */
    public function resourceAssignment(): HasOne
    {
        return $this->hasOne(GeoMonitorResourceAssignment::class, 'observation_id');
    }

    /**
     * 该观测的评分快照。
     */
    public function scores(): HasMany
    {
        return $this->hasMany(GeoMonitorScore::class, 'observation_id');
    }
}
