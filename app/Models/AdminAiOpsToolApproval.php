<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 运维单次工具调用的挂起审批记录（高风险写操作在用户确认前不执行）。
 */
class AdminAiOpsToolApproval extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'run_id',
        'admin_id',
        'tool_name',
        'arguments_json',
        'args_fingerprint',
        'risk_label',
        'status',
        'expires_at',
        'decided_at',
        'rejection_reason',
        'executed_output',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'admin_id' => 'integer',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * 所属执行轮次。
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AdminAiOpsRun::class, 'run_id');
    }

    /**
     * 发起人管理员。
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
