<?php

namespace App\Models;

use App\Services\GeoFlow\UrlImportRealtimeBroadcastService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UrlImportJob extends Model
{
    protected $table = 'url_import_jobs';

    protected $fillable = [
        'url',
        'normalized_url',
        'source_domain',
        'page_title',
        'status',
        'current_step',
        'progress_percent',
        'options_json',
        'result_json',
        'error_message',
        'created_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UrlImportJobLog::class, 'job_id');
    }

    /**
     * 模型生命周期钩子：在状态/进度等字段更新后向 Reverb 推送实时事件。
     *
     * Eloquent 的 updated 事件在 save 完成后同步触发，因此前端能及时收到与
     * 数据库一致的快照，无须前端主动轮询。
     */
    protected static function booted(): void
    {
        static::updated(function (self $job): void {
            app(UrlImportRealtimeBroadcastService::class)->broadcastProgress($job);
        });
    }
}
