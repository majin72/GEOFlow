<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoMonitorPlatform extends Model
{
    protected $table = 'geo_monitor_platforms';

    protected $fillable = [
        'code',
        'label',
        'selector_version',
        'chat_url',
        'is_enabled',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    /**
     * 平台绑定的采集账号。
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(GeoMonitorAccount::class, 'platform_id');
    }

    /**
     * 该平台下的观测记录。
     */
    public function observations(): HasMany
    {
        return $this->hasMany(GeoMonitorObservation::class, 'platform_id');
    }
}
