<?php

namespace App\Jobs;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;

/**
 * URL 智能采集步骤：汇总阶段结果并生成可确认入库的预览。
 */
class CompleteUrlImportPreviewJob extends UrlImportStepJob
{
    /**
     * 执行预览汇总步骤。
     */
    public function handle(UrlImportProcessingService $service): void
    {
        $job = UrlImportJob::query()->whereKey($this->urlImportJobId)->first();
        if (! $job || in_array($job->status, ['completed', 'imported'], true)) {
            return;
        }

        $service->processPreviewStep($job);
    }
}
