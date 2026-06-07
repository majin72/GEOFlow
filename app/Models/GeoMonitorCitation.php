<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoMonitorCitation extends Model
{
    protected $table = 'geo_monitor_citations';

    protected $fillable = [
        'observation_id',
        'url',
        'domain',
        'title',
        'snippet',
        'source_type',
        'position',
        'is_own_domain',
        'is_competitor_domain',
        'evidence_snippet',
    ];

    protected function casts(): array
    {
        return [
            'observation_id' => 'integer',
            'position' => 'integer',
            'is_own_domain' => 'boolean',
            'is_competitor_domain' => 'boolean',
        ];
    }

    /**
     * 所属观测记录。
     */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorObservation::class, 'observation_id');
    }
}
