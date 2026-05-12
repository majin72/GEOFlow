<?php

namespace App\Jobs;

use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * URL 智能采集链式步骤 Job 基类，统一超时、重试、标签和最终失败回写。
 */
abstract class UrlImportStepJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $urlImportJobId)
    {
        $this->timeout = max(60, (int) config('geoflow.url_import_queue_timeout_seconds', 900));
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'geoflow',
            'url-import',
            'url-import:'.$this->urlImportJobId,
        ];
    }

    /**
     * 队列重试耗尽、超时或 worker 中断后，将业务任务标为失败，允许后台再次启动。
     */
    public function failed(?Throwable $exception): void
    {
        $job = UrlImportJob::query()->whereKey($this->urlImportJobId)->first();
        if (! $job || in_array($job->status, ['completed', 'imported'], true)) {
            return;
        }

        $reason = $exception?->getMessage() ?: (string) __('admin.url_import.error.queue_aborted_unknown');
        $errorMessage = (string) __('admin.url_import.error.queue_aborted', ['reason' => $reason]);

        $job->update([
            'status' => 'failed',
            'progress_percent' => max(1, (int) $job->progress_percent),
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ]);

        UrlImportJobLog::query()->create([
            'job_id' => $job->id,
            'step' => $job->current_step ?: 'queued',
            'level' => 'error',
            'message' => __('admin.url_import.log.failed', ['message' => $errorMessage]),
        ]);
    }
}
