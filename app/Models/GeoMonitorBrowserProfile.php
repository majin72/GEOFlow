<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoMonitorBrowserProfile extends Model
{
    protected $table = 'geo_monitor_browser_profiles';

    protected $fillable = [
        'account_id',
        'profile_key',
        'storage_path',
        'host_node',
        'user_agent_summary',
        'locale',
        'timezone_id',
        'viewport',
        'health_status',
        'last_maintained_at',
        'last_maintenance_via',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'viewport' => 'array',
            'last_maintained_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * 绑定的采集账号。
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorAccount::class, 'account_id');
    }

    /**
     * 该 profile 的维护事件。
     */
    public function maintenanceEvents(): HasMany
    {
        return $this->hasMany(GeoMonitorProfileMaintenanceEvent::class, 'browser_profile_id');
    }
}
