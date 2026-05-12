<?php

namespace App\Jobs;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;

/**
 * URL 智能采集步骤：把抓取到的 HTML 抽取为页面 JSON。
 */
class BuildUrlImportPageJsonJob extends UrlImportStepJob
{
    /**
     * 执行页面 JSON 抽取步骤。
     */
    public function handle(UrlImportProcessingService $service): void
    {
        $job = UrlImportJob::query()->whereKey($this->urlImportJobId)->first();
        if (! $job || in_array($job->status, ['completed', 'imported'], true)) {
            return;
        }

        $service->processPageJsonStep($job);
    }
}
