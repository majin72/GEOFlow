<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoMonitorProxyEndpoint extends Model
{
    protected $table = 'geo_monitor_proxy_endpoints';

    protected $fillable = [
        'label',
        'proxy_type',
        'host',
        'port',
        'region',
        'egress_ip_summary',
        'status',
        'failure_count',
        'cooldown_until',
        'last_health_check_at',
        'last_health_status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'failure_count' => 'integer',
            'cooldown_until' => 'datetime',
            'last_health_check_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * 使用该代理出口的账号。
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(GeoMonitorAccount::class, 'proxy_endpoint_id');
    }
}
