<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GeoMonitorAccount extends Model
{
    protected $table = 'geo_monitor_accounts';

    protected $fillable = [
        'platform_id',
        'external_id',
        'label',
        'status',
        'profile_storage_path',
        'proxy_endpoint_id',
        'daily_quota',
        'hourly_quota',
        'cooldown_until',
        'last_login_check_at',
        'last_login_status',
        'last_error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'platform_id' => 'integer',
            'proxy_endpoint_id' => 'integer',
            'daily_quota' => 'integer',
            'hourly_quota' => 'integer',
            'cooldown_until' => 'datetime',
            'last_login_check_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * 所属 AI 平台。
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorPlatform::class, 'platform_id');
    }

    /**
     * 绑定的代理出口。
     */
    public function proxyEndpoint(): BelongsTo
    {
        return $this->belongsTo(GeoMonitorProxyEndpoint::class, 'proxy_endpoint_id');
    }

    /**
     * 账号对应的浏览器 profile（1:1）。
     */
    public function browserProfile(): HasOne
    {
        return $this->hasOne(GeoMonitorBrowserProfile::class, 'account_id');
    }

    /**
     * 使用该账号的观测记录。
     */
    public function observations(): HasMany
    {
        return $this->hasMany(GeoMonitorObservation::class, 'account_id');
    }

    /**
     * 该账号的 profile 维护事件。
     */
    public function maintenanceEvents(): HasMany
    {
        return $this->hasMany(GeoMonitorProfileMaintenanceEvent::class, 'account_id');
    }
}
