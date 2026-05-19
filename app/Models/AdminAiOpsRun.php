<?php

namespace App\Models;

use App\Casts\Utf8SafeArrayCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminAiOpsRun extends Model
{
    protected $fillable = [
        'session_id',
        'admin_id',
        'ai_model_id',
        'status',
        'input_text',
        'plan',
        'result_summary',
        'error_message',
        'plan_stream_snapshot',
        'confirmed_at',
        'cancel_requested_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'plan_stream_snapshot' => Utf8SafeArrayCast::class,
            'ai_model_id' => 'integer',
            'confirmed_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AdminAiOpsSession::class, 'session_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AdminAiOpsStep::class, 'run_id')->orderBy('position')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AdminAiOpsAttachment::class, 'run_id');
    }

    /**
     * 工具调用审批记录（高风险写操作挂起）。
     */
    public function toolApprovals(): HasMany
    {
        return $this->hasMany(AdminAiOpsToolApproval::class, 'run_id');
    }
}
