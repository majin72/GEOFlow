<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoMonitorMention extends Model
{
    protected $table = 'geo_monitor_mentions';

    protected $fillable = [
        'observation_id',
        'entity_name',
        'entity_type',
        'mention_text',
        'sentiment',
        'context_snippet',
        'position',
        'is_recommendation',
    ];

    protected function casts(): array
    {
        return [
            'observation_id' => 'integer',
            'position' => 'integer',
            'is_recommendation' => 'boolean',
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
