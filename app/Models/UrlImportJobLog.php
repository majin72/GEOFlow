<?php

namespace App\Models;

use App\Services\GeoFlow\UrlImportRealtimeBroadcastService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UrlImportJobLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'url_import_job_logs';

    protected $fillable = [
        'job_id',
        'step',
        'level',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'job_id' => 'integer',
            'step' => 'string',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(UrlImportJob::class, 'job_id');
    }

    /**
     * 模型生命周期钩子：日志写入后立即向 Reverb 推送快照，使前端日志列表实时同步。
     *
     * 反查 UrlImportJob 后复用同一份快照构造逻辑；当 job 已不存在（如外键清理）
     * 时静默跳过，避免影响业务主流程。
     */
    protected static function booted(): void
    {
        static::created(function (self $log): void {
            $job = UrlImportJob::query()->whereKey((int) $log->job_id)->first();
            if ($job === null) {
                return;
            }
            app(UrlImportRealtimeBroadcastService::class)->broadcastProgress($job);
        });
    }
}
