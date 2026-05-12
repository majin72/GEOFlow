<?php

namespace App\Jobs;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;

/**
 * URL 智能采集步骤：AI 清洗页面并生成知识库 Markdown。
 */
class BuildUrlImportKnowledgeJob extends UrlImportStepJob
{
    /**
     * 执行 AI 清洗与知识库生成步骤。
     */
    public function handle(UrlImportProcessingService $service): void
    {
        $job = UrlImportJob::query()->whereKey($this->urlImportJobId)->first();
        if (! $job || in_array($job->status, ['completed', 'imported'], true)) {
            return;
        }

        $service->processKnowledgeStep($job);
    }
}
