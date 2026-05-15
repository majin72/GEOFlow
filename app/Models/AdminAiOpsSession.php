<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminAiOpsSession extends Model
{
    protected $fillable = [
        'admin_id',
        'title',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AdminAiOpsRun::class, 'session_id');
    }
}
