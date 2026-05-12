<?php

namespace App\Jobs;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;

/**
 * URL 智能采集步骤：基于知识库内容提取核心关键词。
 */
class BuildUrlImportKeywordsJob extends UrlImportStepJob
{
    /**
     * 执行关键词提取步骤。
     */
    public function handle(UrlImportProcessingService $service): void
    {
        $job = UrlImportJob::query()->whereKey($this->urlImportJobId)->first();
        if (! $job || in_array($job->status, ['completed', 'imported'], true)) {
            return;
        }

        $service->processKeywordsStep($job);
    }
}
