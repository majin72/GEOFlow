<?php

namespace App\Jobs;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;

/**
 * URL 智能采集步骤：抓取目标页面 HTML。
 */
class FetchUrlImportPageJob extends UrlImportStepJob
{
    /**
     * 执行页面抓取步骤。
     */
    public function handle(UrlImportProcessingService $service): void
    {
        $job = UrlImportJob::query()->whereKey($this->urlImportJobId)->first();
        if (! $job || in_array($job->status, ['completed', 'imported'], true)) {
            return;
        }

        $service->processFetchStep($job);
    }
}
