<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoMonitorPrompt extends Model
{
    protected $table = 'geo_monitor_prompts';

    protected $fillable = [
        'project_id',
        'code',
        'prompt_text',
        'intent',
        'keywords',
        'target_product',
        'target_article_url',
        'locale',
        'region',
        'priority',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'keywords' => 'array',
            'priority' => 'integer',
            'is_enabled' => 'boolean',
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
     * 该问题产生的观测记录。
     */
    public function observations(): HasMany
    {
        return $this->hasMany(GeoMonitorObservation::class, 'prompt_id');
    }
}
