<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoMonitorProfileMaintenanceEvent extends Model
{
    protected $table = 'geo_monitor_profile_maintenance_events';

    protected $fillable = [
        'account_id',
        'browser_profile_id',
        'proxy_endpoint_id',
        'trigger_reason',
        'maintenance_via',
        'status',
        'operator_admin_id',
        'egress_ip_summary',
        'notes',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'browser_profile_id' => 'integer',
            'proxy_endpoint_id' => 'integer',
            'operator_admin_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * 维护的采集账号。
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorAccount::class, 'account_id');
    }

    /**
     * 维护的浏览器 profile。
     */
    public function browserProfile(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorBrowserProfile::class, 'browser_profile_id');
    }

    /**
     * 维护时使用的代理出口。
     */
    public function proxyEndpoint(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorProxyEndpoint::class, 'proxy_endpoint_id');
    }

    /**
     * 执行维护的管理员。
     */
    public function operatorAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'operator_admin_id');
    }
}
