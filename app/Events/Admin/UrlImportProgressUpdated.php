<?php

namespace App\Events\Admin;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * URL 智能采集任务进度实时推送事件。
 *
 * 通过 Reverb 把任务状态、当前步骤、进度百分比以及最新日志推送至详情页，
 * 取代前端的 HTTP 轮询。频道与项目内已有的 admin.tasks 保持同一风格（public
 * channel），隔离依赖后台 admin guard 鉴权 + import id 不可猜测。
 */
class UrlImportProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  int  $jobId  URL 导入任务 ID
     * @param  array{
     *     id:int,
     *     status:string,
     *     status_label:string,
     *     current_step:string,
     *     stored_step:string,
     *     progress_percent:int,
     *     error_message:string,
     *     result_ready:bool,
     *     finished_at:string|null,
     *     logs:list<array{step:string,level:string,message:string,created_at:string|null}>
     * }  $payload
     */
    public function __construct(
        public int $jobId,
        public array $payload,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('url-import.'.$this->jobId);
    }

    public function broadcastAs(): string
    {
        return 'url-import.progress.updated';
    }

    /**
     * @return array{
     *     id:int,
     *     status:string,
     *     status_label:string,
     *     current_step:string,
     *     stored_step:string,
     *     progress_percent:int,
     *     error_message:string,
     *     result_ready:bool,
     *     finished_at:string|null,
     *     logs:list<array{step:string,level:string,message:string,created_at:string|null}>
     * }
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
