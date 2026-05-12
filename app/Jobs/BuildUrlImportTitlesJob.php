<?php

namespace App\Jobs;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;

/**
 * URL 智能采集步骤：基于关键词和知识库内容生成标题建议。
 */
class BuildUrlImportTitlesJob extends UrlImportStepJob
{
    /**
     * 执行标题生成步骤。
     */
    public function handle(UrlImportProcessingService $service): void
    {
        $job = UrlImportJob::query()->whereKey($this->urlImportJobId)->first();
        if (! $job || in_array($job->status, ['completed', 'imported'], true)) {
            return;
        }

        $service->processTitlesStep($job);
    }
}
