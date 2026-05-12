<?php

namespace App\Services\GeoFlow;

use App\Events\Admin\UrlImportProgressUpdated;
use App\Models\UrlImportJob;
use Throwable;

/**
 * URL 智能采集任务实时推送服务。
 *
 * 统一封装“构造状态快照并广播到 Reverb”的流程，吞掉广播异常避免 WebSocket
 * 抖动影响主业务流程（任务进度推进/状态切换/日志写入等）。
 */
class UrlImportRealtimeBroadcastService
{
    public function __construct(
        private readonly UrlImportProcessingService $urlImportProcessingService,
    ) {}

    /**
     * 把当前任务的最新状态快照推送到 url-import.{jobId} 频道。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     */
    public function broadcastProgress(UrlImportJob $job): void
    {
        try {
            $payload = $this->urlImportProcessingService->buildStatusPayload($job);
            broadcast(new UrlImportProgressUpdated((int) $job->id, $payload));
        } catch (Throwable) {
            // Ignore broadcast failure and keep business flow stable.
        }
    }
}
