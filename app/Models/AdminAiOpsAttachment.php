<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAiOpsAttachment extends Model
{
    protected $fillable = [
        'run_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AdminAiOpsRun::class, 'run_id');
    }
}
