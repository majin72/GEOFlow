<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoMonitorResourceAssignment extends Model
{
    protected $table = 'geo_monitor_resource_assignments';

    protected $fillable = [
        'observation_id',
        'account_id',
        'browser_profile_id',
        'proxy_endpoint_id',
        'scheduler_strategy',
        'assigned_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'observation_id' => 'integer',
            'account_id' => 'integer',
            'browser_profile_id' => 'integer',
            'proxy_endpoint_id' => 'integer',
            'assigned_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * 对应的观测记录。
     */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorObservation::class, 'observation_id');
    }

    /**
     * 分配的采集账号。
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorAccount::class, 'account_id');
    }

    /**
     * 分配的浏览器 profile。
     */
    public function browserProfile(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorBrowserProfile::class, 'browser_profile_id');
    }

    /**
     * 分配的代理出口。
     */
    public function proxyEndpoint(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorProxyEndpoint::class, 'proxy_endpoint_id');
    }
}
