<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAiOpsStep extends Model
{
    protected $fillable = [
        'run_id',
        'position',
        'type',
        'status',
        'title',
        'input_summary',
        'output_summary',
        'error_message',
        'meta',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AdminAiOpsRun::class, 'run_id');
    }
}
