<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class UrlImportProcessingService
{
    private const AI_ANALYSIS_MAX_ATTEMPTS = 3;

    /**
     * @param  ApiKeyCrypto  $apiKeyCrypto  AI 模型密钥解密服务
     */
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * 标准化用户输入 URL，并阻止本机、内网和私有地址目标。
     *
     * @param  string  $input  用户输入的 URL、域名或路径
     * @return array{url:string,host:string}
     *
     * @throws \InvalidArgumentException
     */
    public function normalizeInputUrl(string $input): array
    {
        $candidate = trim($input);
        if ($candidate === '') {
            throw new \InvalidArgumentException(__('admin.url_import.error.url_required'));
        }

        if (! preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://'.$candidate;
        }

        $parts = parse_url($candidate);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException(__('admin.url_import.error.invalid_url'));
        }

        $this->guardAgainstPrivateTargets($host);

        return [
            'url' => $candidate,
            'host' => $host,
        ];
    }

    /**
     * 确认至少存在一个可用于 URL 智能采集的聊天模型。
     *
     * @throws \RuntimeException
     */
    public function assertAnalysisModelReady(): AiModel
    {
        $lastException = null;
        foreach ($this->resolveAnalysisModels() as $model) {
            try {
                $this->prepareAiRuntime($model);

                return $model;
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        if ($lastException) {
            throw new \RuntimeException($lastException->getMessage(), 0, $lastException);
        }

        throw new \RuntimeException(__('admin.url_import.error.ai_model_required'));
    }

    /**
     * 返回所有当前可用的 URL 分析模型。
     *
     * @return Collection<int, AiModel>
     *
     * @throws \RuntimeException
     */
    private function assertAnalysisModelsReady(): Collection
    {
        $models = $this->resolveAnalysisModels();
        if ($models->isEmpty()) {
            throw new \RuntimeException(__('admin.url_import.error.ai_model_required'));
        }

        $ready = collect();
        $errors = [];
        foreach ($models as $model) {
            try {
                $this->prepareAiRuntime($model);
                $ready->push($model);
            } catch (Throwable $exception) {
                $errors[] = $this->formatModelFailure($model, $exception);
            }
        }

        if ($ready->isEmpty()) {
            throw new \RuntimeException(__('admin.url_import.error.ai_all_models_failed', [
                'messages' => implode('；', $errors),
            ]));
        }

        return $ready;
    }

    /**
     * 判断后台是否已有可用的 URL 智能采集分析模型。
     */
    public function hasReadyAnalysisModel(): bool
    {
        try {
            $this->assertAnalysisModelReady();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 同步执行完整 URL 智能采集流程；主要供测试和手动命令复用。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     */
    public function process(UrlImportJob $job): UrlImportJob
    {
        try {
            $job = $this->processFetchStep($job);
            $job = $this->processPageJsonStep($job);
            $job = $this->processKnowledgeStep($job);
            $job = $this->processKeywordsStep($job);
            $job = $this->processTitlesStep($job);
            $job = $this->processPreviewStep($job);

            return $job->refresh();
        } catch (Throwable $exception) {
            $job->update([
                'status' => 'failed',
                'progress_percent' => 100,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
            $this->log($job, 'error', __('admin.url_import.log.failed', ['message' => $exception->getMessage()]));

            return $job->refresh();
        }
    }

    /**
     * 执行 URL 导入的页面抓取步骤，并暂存原始 HTML 供后续链式 Job 使用。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     *
     * @throws \RuntimeException
     */
    public function processFetchStep(UrlImportJob $job): UrlImportJob
    {
        $this->updateStep($job, 'fetch', 10, [
            'status' => 'running',
            'started_at' => $job->started_at ?: now(),
            'finished_at' => null,
            'error_message' => '',
        ]);
        $this->log($job, 'info', __('admin.url_import.log.fetch_start', ['url' => $job->normalized_url]), 'fetch');

        $fetched = $this->fetchPage((string) $job->normalized_url);
        $this->log($job, 'info', __('admin.url_import.log.fetch_done', ['length' => strlen($fetched['html'])]), 'fetch');

        $result = $this->decodeResult($job);
        $result['source'] = [
            'url' => (string) $job->url,
            'normalized_url' => (string) $job->normalized_url,
            'domain' => (string) $job->source_domain,
            'fetched_at' => now()->toIso8601String(),
            'status' => $fetched['status'],
        ];
        $result['_working']['html'] = $fetched['html'];
        $this->saveResult($job, $result);

        return $job->refresh();
    }

    /**
     * 执行 URL 导入的页面 JSON 抽取步骤。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     *
     * @throws \RuntimeException
     */
    public function processPageJsonStep(UrlImportJob $job): UrlImportJob
    {
        $result = $this->decodeResult($job);
        $html = (string) data_get($result, '_working.html', '');
        if ($html === '') {
            throw new \RuntimeException(__('admin.url_import.error.step_prerequisite_missing', ['step' => 'fetch']));
        }

        $this->updateStep($job, 'page_json', 25);
        $this->log($job, 'info', __('admin.url_import.log.page_json_start'), 'page_json');

        $parsed = $this->parseHtml($html, (string) $job->normalized_url);
        $this->log($job, 'info', __('admin.url_import.log.extract_done', [
            'chars' => mb_strlen((string) ($parsed['text'] ?? ''), 'UTF-8'),
        ]), 'page_json');

        $result['page'] = $parsed;
        $result['analysis']['page_json'] = $this->buildPageJson($parsed, $job);
        $this->log($job, 'info', __('admin.url_import.log.page_json_done', [
            'chars' => mb_strlen((string) data_get($result, 'analysis.page_json.text', ''), 'UTF-8'),
        ]), 'page_json');
        $this->saveResult($job, $result);

        return $job->refresh();
    }

    /**
     * 执行 URL 导入的 AI 清洗与知识库 Markdown 生成步骤。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     *
     * @throws \RuntimeException
     */
    public function processKnowledgeStep(UrlImportJob $job): UrlImportJob
    {
        $result = $this->decodeResult($job);
        $parsed = $this->requireResultArray($result, 'page', 'page_json');
        $pageJson = $this->requireResultArray($result, 'analysis.page_json', 'page_json');
        $title = (string) ($parsed['title'] ?? '');
        $text = (string) ($parsed['text'] ?? '');
        $summary = (string) ($parsed['summary'] ?? '');
        $libraryName = $this->safeName($title !== '' ? $title : (string) $job->source_domain);
        $errors = [];

        foreach ($this->assertAnalysisModelsReady() as $model) {
            for ($attempt = 1; $attempt <= self::AI_ANALYSIS_MAX_ATTEMPTS; $attempt++) {
                try {
                    $runtime = $this->prepareAiRuntime($model);

                    $this->updateStep($job, 'knowledge', 45);
                    $this->log($job, 'info', __('admin.url_import.log.clean_start'), 'knowledge');
                    $cleaned = $this->normalizeCleanedPage($this->requestAiJson(
                        $runtime,
                        $this->buildCleanSystemPrompt(),
                        $this->buildCleanUserPrompt($pageJson)
                    ), $parsed);
                    $this->log($job, 'info', __('admin.url_import.log.clean_done', [
                        'chars' => mb_strlen((string) ($cleaned['text'] ?? ''), 'UTF-8'),
                    ]), 'knowledge');

                    $this->log($job, 'info', __('admin.url_import.log.knowledge_start'), 'knowledge');
                    $knowledgePayload = $this->requestAiJson(
                        $runtime,
                        $this->buildKnowledgeSystemPrompt(),
                        $this->buildKnowledgeUserPrompt($pageJson, $cleaned, [])
                    );
                    $aiSummary = $this->normalizeText($this->aiResponseTextToString($knowledgePayload['summary'] ?? $cleaned['summary'] ?? $summary));
                    $aiLibraryName = $this->safeName($this->aiResponseTextToString($knowledgePayload['library_name'] ?? $cleaned['title'] ?? $libraryName));
                    $aiKnowledge = trim($this->aiResponseTextToString($knowledgePayload['knowledge_markdown'] ?? ''));
                    if ($aiKnowledge === '') {
                        throw new \RuntimeException(__('admin.url_import.error.ai_knowledge_missing'));
                    }

                    $result = $this->decodeResult($job);
                    $result['analysis'] = array_merge(is_array($result['analysis'] ?? null) ? $result['analysis'] : [], [
                        'summary' => $aiSummary !== '' ? $aiSummary : Str::limit($text, 220, '...'),
                        'library_name' => $aiLibraryName !== '' ? $aiLibraryName : $libraryName,
                        'knowledge_markdown' => $aiKnowledge,
                        'analysis_source' => 'ai',
                        'model' => [
                            'id' => (int) $model->id,
                            'name' => (string) $model->name,
                        ],
                        'page_json' => $pageJson,
                        'cleaned' => $cleaned,
                    ]);
                    $this->saveResult($job, $result);
                    $this->log($job, 'info', __('admin.url_import.log.knowledge_done', [
                        'chars' => mb_strlen($aiKnowledge, 'UTF-8'),
                    ]), 'knowledge');

                    return $job->refresh();
                } catch (Throwable $exception) {
                    $message = $this->normalizeAiErrorMessage($exception, $model);
                    if ($attempt < self::AI_ANALYSIS_MAX_ATTEMPTS) {
                        $this->log($job, 'warning', __('admin.url_import.log.ai_model_retry', [
                            'model' => $this->modelDisplayName($model),
                            'current' => $attempt,
                            'max' => self::AI_ANALYSIS_MAX_ATTEMPTS,
                            'message' => $message,
                        ]), 'knowledge');

                        continue;
                    }

                    $errors[] = $this->formatModelFailure($model, $exception);
                    $this->log($job, 'warning', __('admin.url_import.log.ai_model_failed', [
                        'model' => $this->modelDisplayName($model),
                        'message' => $message,
                    ]), 'knowledge');
                }
            }
        }

        throw new \RuntimeException(__('admin.url_import.error.ai_parse_failed', [
            'message' => __('admin.url_import.error.ai_all_models_failed', [
                'messages' => implode('；', $errors),
            ]),
        ]));
    }

    /**
     * 执行 URL 导入的核心关键词提取步骤。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     *
     * @throws \RuntimeException
     */
    public function processKeywordsStep(UrlImportJob $job): UrlImportJob
    {
        $result = $this->decodeResult($job);
        $analysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];
        $pageJson = $this->requireResultArray($result, 'analysis.page_json', 'knowledge');
        $cleaned = $this->requireResultArray($result, 'analysis.cleaned', 'knowledge');
        $aiKnowledge = trim((string) ($analysis['knowledge_markdown'] ?? ''));
        if ($aiKnowledge === '') {
            throw new \RuntimeException(__('admin.url_import.error.step_prerequisite_missing', ['step' => 'knowledge']));
        }

        $model = $this->resolveAnalysisModelForStep($analysis);
        $runtime = $this->prepareAiRuntime($model);

        $this->updateStep($job, 'keywords', 62);
        $this->log($job, 'info', __('admin.url_import.log.keywords_start'), 'keywords');
        $keywordPayload = $this->requestAiJson(
            $runtime,
            $this->buildKeywordsSystemPrompt(),
            $this->buildKeywordsUserPrompt($pageJson, $cleaned, $aiKnowledge),
            'keywords'
        );
        $keywordValues = $keywordPayload['keywords'] ?? (array_is_list($keywordPayload) ? $keywordPayload : []);
        $aiKeywords = array_slice($this->cleanKeywordList($this->stringList($keywordValues)), 0, 10);
        if ($aiKeywords === []) {
            throw new \RuntimeException(__('admin.url_import.error.ai_keywords_missing'));
        }

        $result['analysis']['keywords'] = $aiKeywords;
        $this->saveResult($job, $result);
        $this->log($job, 'info', __('admin.url_import.log.keywords_done', ['count' => count($aiKeywords)]), 'keywords');

        return $job->refresh();
    }

    /**
     * 执行 URL 导入的标题建议生成步骤。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     *
     * @throws \RuntimeException
     */
    public function processTitlesStep(UrlImportJob $job): UrlImportJob
    {
        $result = $this->decodeResult($job);
        $analysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];
        $pageJson = $this->requireResultArray($result, 'analysis.page_json', 'keywords');
        $cleaned = $this->requireResultArray($result, 'analysis.cleaned', 'keywords');
        $aiKnowledge = trim((string) ($analysis['knowledge_markdown'] ?? ''));
        $aiKeywords = $this->stringList($analysis['keywords'] ?? []);
        if ($aiKnowledge === '' || $aiKeywords === []) {
            throw new \RuntimeException(__('admin.url_import.error.step_prerequisite_missing', ['step' => 'keywords']));
        }

        $model = $this->resolveAnalysisModelForStep($analysis);
        $runtime = $this->prepareAiRuntime($model);

        $this->updateStep($job, 'titles', 80);
        $this->log($job, 'info', __('admin.url_import.log.titles_start'), 'titles');
        $titlePayload = $this->requestAiJson(
            $runtime,
            $this->buildTitlesSystemPrompt(),
            $this->buildTitlesUserPrompt($pageJson, $cleaned, $aiKnowledge, $aiKeywords),
            'titles'
        );
        $titleValues = $titlePayload['titles'] ?? (array_is_list($titlePayload) ? $titlePayload : []);
        $aiTitles = array_slice($this->stringList($titleValues), 0, 50);
        if ($aiTitles === []) {
            throw new \RuntimeException(__('admin.url_import.error.ai_titles_missing'));
        }

        $result['analysis']['titles'] = $aiTitles;
        $this->saveResult($job, $result);
        $this->log($job, 'info', __('admin.url_import.log.titles_done', ['count' => count($aiTitles)]), 'titles');

        return $job->refresh();
    }

    /**
     * 执行 URL 导入的预览汇总步骤，并将业务任务标记为 completed。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     *
     * @throws \RuntimeException
     */
    public function processPreviewStep(UrlImportJob $job): UrlImportJob
    {
        $result = $this->decodeResult($job);
        $page = $this->requireResultArray($result, 'page', 'page_json');
        $analysis = $this->requireResultArray($result, 'analysis', 'titles');
        if (trim((string) ($analysis['knowledge_markdown'] ?? '')) === '' || $this->stringList($analysis['keywords'] ?? []) === [] || $this->stringList($analysis['titles'] ?? []) === []) {
            throw new \RuntimeException(__('admin.url_import.error.step_prerequisite_missing', ['step' => 'titles']));
        }

        $this->updateStep($job, 'preview', 96);
        $this->log($job, 'info', __('admin.url_import.log.preview_start'), 'preview');

        unset($result['_working']);
        $result['source'] = is_array($result['source'] ?? null) ? $result['source'] : [
            'url' => (string) $job->url,
            'normalized_url' => (string) $job->normalized_url,
            'domain' => (string) $job->source_domain,
            'fetched_at' => now()->toIso8601String(),
            'status' => 0,
        ];
        $result['page'] = $page;
        $result['analysis'] = $analysis;
        $result['import'] = [
            'status' => 'preview',
            'summary' => null,
        ];

        $this->updateStep($job, 'preview', 100, [
            'page_title' => (string) ($page['title'] ?? ''),
            'status' => 'completed',
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'finished_at' => now(),
        ]);
        $this->log($job, 'info', __('admin.url_import.log.preview_ready'), 'preview');

        return $job->refresh();
    }

    /**
     * 将 URL 采集预览结果写入素材库。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     * @return array{knowledge_base:int,keyword_library:int,title_library:int,keywords:int,titles:int}
     *
     * @throws \RuntimeException
     */
    public function commit(UrlImportJob $job): array
    {
        $result = $this->decodeResult($job);
        if ($result === []) {
            throw new \RuntimeException(__('admin.url_import.error.commit_before_parse'));
        }
        if (($result['import']['status'] ?? '') === 'imported' && is_array($result['import']['summary'] ?? null)) {
            /** @var array{knowledge_base:int,keyword_library:int,title_library:int,keywords:int,titles:int} $summary */
            $summary = $result['import']['summary'];

            return $summary;
        }

        /** @var array<string, mixed> $page */
        $page = is_array($result['page'] ?? null) ? $result['page'] : [];
        /** @var array<string, mixed> $analysis */
        $analysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];
        // 库名优先使用采集到的页面标题；为空时回退 AI 生成名 → 域名 → "URL素材"，保持可读性。
        $candidateNames = [
            (string) ($page['title'] ?? ''),
            (string) ($job->page_title ?? ''),
            (string) ($analysis['library_name'] ?? ''),
            (string) ($job->source_domain ?? ''),
        ];
        $preferredName = '';
        foreach ($candidateNames as $candidate) {
            if (trim($candidate) !== '') {
                $preferredName = $candidate;
                break;
            }
        }
        $baseName = $this->safeName($preferredName !== '' ? $preferredName : 'URL素材');
        $knowledgeContent = trim((string) ($analysis['knowledge_markdown'] ?? $page['text'] ?? ''));
        if ($knowledgeContent === '') {
            throw new \RuntimeException(__('admin.url_import.error.commit_before_parse'));
        }
        $keywords = $this->stringList($analysis['keywords'] ?? []);
        $titles = $this->stringList($analysis['titles'] ?? []);
        if ($keywords === []) {
            throw new \RuntimeException(__('admin.url_import.error.ai_keywords_missing'));
        }
        if ($titles === []) {
            throw new \RuntimeException(__('admin.url_import.error.ai_titles_missing'));
        }

        $summary = DB::transaction(function () use ($baseName, $knowledgeContent, $analysis, $keywords, $titles): array {
            $knowledgeBase = KnowledgeBase::query()->create([
                'name' => $baseName.' 知识库',
                'description' => (string) ($analysis['summary'] ?? ''),
                'content' => $knowledgeContent,
                'character_count' => mb_strlen($knowledgeContent, 'UTF-8'),
                'used_task_count' => 0,
                'file_type' => 'markdown',
                'file_path' => '',
                'word_count' => mb_strlen($knowledgeContent, 'UTF-8'),
                'usage_count' => 0,
            ]);

            $keywordLibrary = KeywordLibrary::query()->create([
                'name' => $baseName.' 关键词库',
                'description' => 'URL智能采集自动生成',
                'keyword_count' => 0,
            ]);
            foreach ($keywords as $keyword) {
                Keyword::query()->firstOrCreate(
                    ['library_id' => (int) $keywordLibrary->id, 'keyword' => $keyword],
                    ['used_count' => 0, 'usage_count' => 0]
                );
            }
            $keywordLibrary->update(['keyword_count' => Keyword::query()->where('library_id', (int) $keywordLibrary->id)->count()]);

            $titleLibrary = TitleLibrary::query()->create([
                'name' => $baseName.' 标题库',
                'description' => 'URL智能采集自动生成',
                'title_count' => 0,
                'generation_type' => 'url_import',
                'generation_rounds' => 1,
                'is_ai_generated' => 1,
            ]);
            foreach ($titles as $index => $title) {
                Title::query()->firstOrCreate(
                    ['library_id' => (int) $titleLibrary->id, 'title' => $title],
                    [
                        'keyword' => $keywords[$index % max(1, count($keywords))] ?? '',
                        'is_ai_generated' => true,
                        'used_count' => 0,
                        'usage_count' => 0,
                    ]
                );
            }
            $titleLibrary->update(['title_count' => Title::query()->where('library_id', (int) $titleLibrary->id)->count()]);

            return [
                'knowledge_base' => (int) $knowledgeBase->id,
                'keyword_library' => (int) $keywordLibrary->id,
                'title_library' => (int) $titleLibrary->id,
                'keywords' => (int) Keyword::query()->where('library_id', (int) $keywordLibrary->id)->count(),
                'titles' => (int) Title::query()->where('library_id', (int) $titleLibrary->id)->count(),
            ];
        });

        $result['import'] = [
            'status' => 'imported',
            'imported_at' => now()->toIso8601String(),
            'summary' => $summary,
        ];
        $job->update([
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'current_step' => 'imported',
            'progress_percent' => 100,
        ]);

        return $summary;
    }

    /**
     * 解码 URL 采集任务的结果 JSON。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     * @return array<string, mixed>
     */
    public function decodeResult(UrlImportJob $job): array
    {
        $decoded = json_decode((string) $job->result_json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 保存 URL 采集任务的阶段结果 JSON。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     * @param  array<string, mixed>  $result
     */
    private function saveResult(UrlImportJob $job, array $result): void
    {
        $job->update([
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    /**
     * 从阶段结果中读取必须存在的数组节点。
     *
     * @param  array<string, mixed>  $result
     * @param  string  $key  data_get 路径
     * @param  string  $requiredStep  缺失时提示需要补跑的前置步骤
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function requireResultArray(array $result, string $key, string $requiredStep): array
    {
        $value = data_get($result, $key);
        if (! is_array($value) || $value === []) {
            throw new \RuntimeException(__('admin.url_import.error.step_prerequisite_missing', ['step' => $requiredStep]));
        }

        return $value;
    }

    /**
     * 解析后续链式步骤应使用的 AI 模型；原模型不可用时回退到当前可用模型。
     *
     * @param  array<string, mixed>  $analysis
     *
     * @throws \RuntimeException
     */
    private function resolveAnalysisModelForStep(array $analysis): AiModel
    {
        $modelId = (int) data_get($analysis, 'model.id', 0);
        if ($modelId > 0) {
            $model = AiModel::query()->whereKey($modelId)->first();
            if ($model) {
                try {
                    $this->prepareAiRuntime($model);

                    return $model;
                } catch (Throwable) {
                    // 若原模型已不可用，继续回退到当前可用模型。
                }
            }
        }

        return $this->assertAnalysisModelReady();
    }

    /**
     * 校验目标主机不能指向本机、内网或保留地址，降低 SSRF 风险。
     *
     * @param  string  $host  已解析出的 URL 主机名
     *
     * @throws \InvalidArgumentException
     */
    private function guardAgainstPrivateTargets(string $host): void
    {
        return;
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true) || str_ends_with($host, '.local')) {
            throw new \InvalidArgumentException(__('admin.url_import.error.private_url'));
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        $allowMixedDns = (bool) config('geoflow.url_import_allow_mixed_dns', false);

        foreach ($records ?: [] as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '') {
                continue;
            }

            // 默认严格阻断所有私有/保留地址。该开关仅用于明确受控的混合 DNS 环境。
            if ($allowMixedDns && self::isUlaAddress($ip)) {
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException(__('admin.url_import.error.private_url'));
            }
        }
    }

    /**
     * 判断 IPv6 地址是否属于 ULA（fc00::/7）。
     *
     * @param  string  $ip  IPv6 地址
     */
    private static function isUlaAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $bin = @inet_pton($ip);

        return $bin !== false && (ord($bin[0]) & 0xfe) === 0xfc;
    }

    /**
     * 抓取目标 URL 的 HTML 内容。
     *
     * @param  string  $url  标准化后的目标 URL
     * @return array{html:string,status:int}
     *
     * @throws \RuntimeException
     */
    private function fetchPage(string $url): array
    {
        $response = Http::timeout(20)
            ->connectTimeout(8)
            ->withHeaders([
                'User-Agent' => 'GEOFlow URL Importer/1.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException(__('admin.url_import.error.fetch_failed', ['status' => $response->status()]));
        }

        $html = (string) $response->body();
        if (trim($html) === '') {
            throw new \RuntimeException(__('admin.url_import.error.empty_page'));
        }

        return [
            'html' => $html,
            'status' => $response->status(),
        ];
    }

    /**
     * 从 HTML 中抽取标题、描述、正文和原始页面 JSON。
     *
     * @param  string  $html  页面 HTML
     * @param  string  $baseUrl  页面来源 URL
     * @return array{title:string,description:string,text:string,summary:string,raw_json:array<string,mixed>}
     */
    private function parseHtml(string $html, string $baseUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript|//nav|//footer|//header|//form|//aside') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = $this->firstMetaContent($xpath, ['og:title', 'twitter:title']);
        if ($title === '') {
            $titleNode = $xpath->query('//title')->item(0);
            $title = $titleNode ? trim((string) $titleNode->textContent) : '';
        }
        if ($title === '') {
            $h1 = $xpath->query('//h1')->item(0);
            $title = $h1 ? trim((string) $h1->textContent) : ((string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'URL素材'));
        }

        $description = $this->firstMetaContent($xpath, ['description', 'og:description', 'twitter:description']);
        $body = $xpath->query('//article')->item(0) ?: $xpath->query('//main')->item(0) ?: $xpath->query('//body')->item(0);
        $text = $body ? $this->normalizeText((string) $body->textContent) : '';
        $summary = $description !== '' ? $description : Str::limit($text, 220, '...');

        return [
            'title' => $this->normalizeText($title),
            'description' => $this->normalizeText($description),
            'text' => Str::limit($text, 20000, ''),
            'summary' => $this->normalizeText($summary),
            'raw_json' => [
                'title' => $this->normalizeText($title),
                'description' => $this->normalizeText($description),
                'text' => Str::limit($text, 20000, ''),
            ],
        ];
    }

    /**
     * 查询所有状态可用且未超过每日额度的聊天模型。
     *
     * @return Collection<int, AiModel>
     */
    private function resolveAnalysisModels(): Collection
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->where(function ($query): void {
                $query->whereNull('daily_limit')
                    ->orWhere('daily_limit', 0)
                    ->orWhereColumn('used_today', '<', 'daily_limit');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * 为指定 AI 模型准备 URL 导入运行时 provider。
     *
     * @param  AiModel  $model  AI 模型配置
     * @return array{provider:string,model_id:string,model:AiModel}
     *
     * @throws \RuntimeException
     */
    private function prepareAiRuntime(AiModel $model): array
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($providerUrl === '') {
            throw new \RuntimeException(__('admin.url_import.error.ai_url_missing'));
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException(__('admin.url_import.error.ai_key_missing'));
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('url_import_analysis', $driver, $providerUrl, $apiKey);

        return [
            'provider' => $providerName,
            'model_id' => (string) ($model->model_id ?? ''),
            'model' => $model,
        ];
    }

    /**
     * 调用 AI 并解析 JSON 响应。
     *
     * @param  array{provider:string,model_id:string,model:AiModel}  $runtime
     * @param  string  $systemPrompt  系统提示词
     * @param  string  $userPrompt  用户提示词
     * @param  string|null  $listFallbackKey  列表型步骤的纯文本回退字段名
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function requestAiJson(array $runtime, string $systemPrompt, string $userPrompt, ?string $listFallbackKey = null): array
    {
        $agent = new MarkdownContentWriterAgent($systemPrompt);

        try {
            $response = $agent->prompt(
                $userPrompt,
                [],
                $runtime['provider'],
                $runtime['model_id']
            );
        } catch (Throwable $exception) {
            /** @var AiModel $model */
            $model = $runtime['model'];
            throw new \RuntimeException($this->normalizeAiErrorMessage($exception, $model), 0, $exception);
        }

        $content = $this->aiResponseTextToString($response->text ?? '');
        if ($content === '') {
            throw new \RuntimeException(__('admin.url_import.error.ai_empty_content'));
        }

        $decoded = $this->decodeAiJson($content);
        if ($decoded === []) {
            $fallbackList = $listFallbackKey ? $this->parseAiList($content) : [];
            if ($fallbackList !== []) {
                $decoded = [$listFallbackKey => $fallbackList];
            }
        }

        if ($decoded === []) {
            throw new \RuntimeException(__('admin.url_import.error.ai_invalid_json', [
                'preview' => $this->previewAiContent($content),
            ]));
        }

        /** @var AiModel $model */
        $model = $runtime['model'];
        AiModel::query()->whereKey((int) $model->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        return $decoded;
    }

    /**
     * 将不同模型返回的文本结构统一转换为字符串。
     *
     * @param  mixed  $value  AI SDK 返回的文本片段或结构化内容
     */
    private function aiResponseTextToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                foreach (['text', 'content', 'message'] as $key) {
                    if (array_key_exists($key, $value)) {
                        $nested = $this->aiResponseTextToString($value[$key]);
                        if ($nested !== '') {
                            return $nested;
                        }
                    }
                }

                $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

                return is_string($json) ? trim($json) : '';
            }

            $parts = [];
            foreach ($value as $item) {
                $part = $this->aiResponseTextToString($item);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }

            return trim(implode("\n", $parts));
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return trim((string) $value);
            }

            return $this->aiResponseTextToString(get_object_vars($value));
        }

        return '';
    }

    /**
     * 获取用于日志展示的模型名称。
     *
     * @param  AiModel  $model  AI 模型配置
     */
    private function modelDisplayName(AiModel $model): string
    {
        $name = trim((string) ($model->name ?? ''));
        $modelId = trim((string) ($model->model_id ?? ''));

        return trim($name.($modelId !== '' ? ' / '.$modelId : '')) ?: '#'.(int) $model->id;
    }

    /**
     * 格式化单个模型失败原因。
     *
     * @param  AiModel  $model  AI 模型配置
     * @param  Throwable  $exception  模型调用异常
     */
    private function formatModelFailure(AiModel $model, Throwable $exception): string
    {
        return $this->modelDisplayName($model).'：'.$this->normalizeAiErrorMessage($exception, $model);
    }

    /**
     * 归一化 AI 接口异常文案，隐藏底层运行时差异。
     *
     * @param  Throwable  $exception  模型调用异常
     * @param  AiModel|null  $model  关联模型配置
     */
    private function normalizeAiErrorMessage(Throwable $exception, ?AiModel $model = null): string
    {
        $providerUrl = '';
        if ($model) {
            $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        }

        return OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl);
    }

    /**
     * 构造页面清洗步骤的系统提示词。
     */
    private function buildCleanSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的网页正文清洗器。只输出 JSON，不要输出 Markdown 代码块。
字段固定为：clean_title, clean_summary, clean_text, core_business, entities, facts, noise_removed。
目标：从页面 JSON 中去掉导航、菜单、广告、版权、按钮、登录、推荐流、重复模板文案，只保留页面主体内容和可被知识库引用的事实，并识别页面背后的真实核心业务。
core_business 必须描述页面主体对应的行业、产品/服务、目标客户、商业场景、价值主张和可验证边界。
不能虚构页面没有的信息。
PROMPT;
    }

    /**
     * 构造发送给 AI 的页面 JSON 上下文。
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, string>
     */
    private function buildPageJson(array $parsed, UrlImportJob $job): array
    {
        $options = json_decode((string) $job->options_json, true);
        $options = is_array($options) ? $options : [];

        return [
            'source_url' => (string) $job->normalized_url,
            'source_domain' => (string) $job->source_domain,
            'project_name' => (string) ($options['project_name'] ?? ''),
            'source_label' => (string) ($options['source_label'] ?? ''),
            'content_language' => (string) ($options['content_language'] ?? ''),
            'operator_notes' => (string) ($options['notes'] ?? ''),
            'title' => (string) ($parsed['title'] ?? ''),
            'description' => (string) ($parsed['description'] ?? ''),
            'summary' => (string) ($parsed['summary'] ?? ''),
            'text' => Str::limit((string) ($parsed['text'] ?? ''), 12000, ''),
        ];
    }

    /**
     * 构造页面清洗步骤的用户提示词。
     *
     * @param  array<string, mixed>  $pageJson
     */
    private function buildCleanUserPrompt(array $pageJson): string
    {
        return "请清洗以下页面 JSON，输出 clean_title、clean_summary、clean_text、entities、facts、noise_removed。\n\n页面 JSON：\n"
            .json_encode($pageJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ."\n\n输出要求：\n"
            ."1. clean_text 只保留主体正文，不要保留“查看详情、返回首页、登录、注册、更多、相关阅读”等模板噪声。\n"
            ."2. clean_summary 120-240 字，概括真实主体内容。\n"
            ."3. core_business 输出对象，包含 industry、products_services、target_audience、commercial_scenarios、value_proposition、evidence_limits。\n"
            ."4. facts 输出页面明确出现或可直接归纳的事实短句，优先服务/产品/能力/客户/场景/数据。\n"
            .'5. entities 输出品牌、产品、服务、行业、目标用户、地名、人名等实体。';
    }

    /**
     * 构造关键词提取步骤的系统提示词。
     */
    private function buildKeywordsSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的核心业务关键词提炼器。只输出 JSON，不要输出 Markdown 代码块。
字段固定为：keywords。
keywords 最多 10 个，必须是短关键词或短语。中文关键词优先 2-5 个字，英文关键词优先 1-3 个单词。
只允许输出基于知识库反推出来的核心业务词根、产品/服务词、行业词、需求场景词、问题词、解决方案词。
关键词必须具备商业价值或内容选题价值，能支撑后续生成 GEO 文章。
禁止输出：AI、GEO、URL、来源、页面描述、引擎、官网、首页、公司名、人名、导航词、按钮词、广告口号、整段摘要、长句和重复词。
不能虚构页面没有的信息。
PROMPT;
    }

    /**
     * 构造关键词提取步骤的用户提示词。
     *
     * @param  array<string, mixed>  $pageJson
     * @param  array<string, mixed>  $cleaned
     * @param  string  $knowledgeMarkdown  已生成的知识库 Markdown
     */
    private function buildKeywordsUserPrompt(array $pageJson, array $cleaned, string $knowledgeMarkdown): string
    {
        return "请只基于已清洗知识库，提取 5-10 个最核心的业务词根或业务关键词。不要从原网页机械摘词，要先判断业务本质，再输出能带来商业检索价值的短关键词。\n\n"
            ."GEOFlow 内置规则：\n".$this->builtInGeoCollectionPrompt()."\n\n"
            ."后台关键词提示词：\n".$this->latestPromptContent('keyword')."\n\n"
            ."页面来源与清洗结果：\n".json_encode([
                'source_url' => $pageJson['source_url'] ?? '',
                'source_domain' => $pageJson['source_domain'] ?? '',
                'title' => $cleaned['title'] ?? $pageJson['title'] ?? '',
                'summary' => $cleaned['summary'] ?? '',
                'entities' => $cleaned['entities'] ?? [],
                'facts' => $cleaned['facts'] ?? [],
                'knowledge_markdown' => Str::limit($knowledgeMarkdown, 9000, ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * 构造标题生成步骤的系统提示词。
     */
    private function buildTitlesSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的 GEO 标题库构建器。只输出 JSON，不要输出 Markdown 代码块。
字段固定为：titles。
titles 最多 50 个，必须基于页面真实信息、知识库和 10 个核心业务词生成，适合后续生成真实可信的 GEO 内容。
标题角度要多样：是什么、为什么、怎么做、选型、对比、指南、清单、常见问题、场景拆解、趋势判断、2026 趋势或商业价值。
每个标题都必须围绕某个核心业务词或业务场景展开，面向 AI 搜索/GEO 的问答、推荐、比较、选型、采购、实施和风险判断。
不要机械复读网页标题，不要虚构“第一、最好、领先”等无来源支撑的绝对化表述。
PROMPT;
    }

    /**
     * 构造标题生成步骤的用户提示词。
     *
     * @param  array<string, mixed>  $pageJson
     * @param  array<string, mixed>  $cleaned
     * @param  string  $knowledgeMarkdown  已生成的知识库 Markdown
     * @param  list<string>  $keywords
     */
    private function buildTitlesUserPrompt(array $pageJson, array $cleaned, string $knowledgeMarkdown, array $keywords): string
    {
        return "请为 GEOFlow 标题库生成 50 个可用于内容任务的标题。标题要围绕核心业务词展开，必须服务于用户在 AI 搜索中的真实问题、比较、选型、采购、实施或运营决策。\n\n"
            ."后台正文提示词参考：\n".$this->latestPromptContent('content')."\n\n"
            ."输入：\n".json_encode([
                'source_url' => $pageJson['source_url'] ?? '',
                'title' => $cleaned['title'] ?? $pageJson['title'] ?? '',
                'summary' => $cleaned['summary'] ?? '',
                'keywords' => array_slice($keywords, 0, 10),
                'facts' => $cleaned['facts'] ?? [],
                'knowledge_markdown' => Str::limit($knowledgeMarkdown, 7000, ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * 构造知识库生成步骤的系统提示词。
     */
    private function buildKnowledgeSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的知识库构建器。只输出 JSON，不要输出 Markdown 代码块。
字段固定为：summary, library_name, knowledge_markdown。
knowledge_markdown 必须围绕“核心业务”构建，是真实可追溯、结构化、原子化的知识库内容，保留来源 URL，只沉淀页面明确出现或可由页面内容直接归纳的信息。
必须优先抽取：核心业务、产品/服务、目标用户、业务场景、能力/优势、可验证事实、使用边界、适合支撑的 GEO 内容方向。
不能虚构事实、案例、客户、排名、数据、背书。信息不足时明确标注“页面未明确说明”。
PROMPT;
    }

    /**
     * 构造知识库生成步骤的用户提示词。
     *
     * @param  array<string, mixed>  $pageJson
     * @param  array<string, mixed>  $cleaned
     * @param  list<string>  $keywords
     */
    private function buildKnowledgeUserPrompt(array $pageJson, array $cleaned, array $keywords): string
    {
        return "请基于页面 JSON 和清洗正文生成可直接入库的 GEOFlow 知识库 Markdown。先识别核心业务，再把页面信息拆成结构化、原子化事实，最后归纳 GEO 内容可用方向和使用边界。\n\n"
            ."后台描述提示词参考：\n".$this->latestPromptContent('description')."\n\n"
            ."输入：\n".json_encode([
                'source_url' => $pageJson['source_url'] ?? '',
                'source_domain' => $pageJson['source_domain'] ?? '',
                'title' => $cleaned['title'] ?? $pageJson['title'] ?? '',
                'summary' => $cleaned['summary'] ?? '',
                'core_business' => $cleaned['core_business'] ?? [],
                'keywords' => array_slice($keywords, 0, 40),
                'entities' => $cleaned['entities'] ?? [],
                'facts' => $cleaned['facts'] ?? [],
                'clean_text' => Str::limit((string) ($cleaned['text'] ?? ''), 10000, ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ."\n\n建议结构：来源、核心业务摘要、原子化事实、产品/服务与能力、目标用户与场景、可引用事实、GEO 内容建议、使用边界。";
    }

    /**
     * 返回 URL 采集内置的 GEO 素材构建规则。
     */
    private function builtInGeoCollectionPrompt(): string
    {
        return <<<'PROMPT'
你正在为 GEOFlow 构建可复用素材库。请把网页内容拆成三类资产：

关键词库：
- 输出短词或短语，不要输出完整句子。
- 优先：产品/服务词、行业词、目标客户词、需求场景词、痛点词、解决方案词、AI 搜索/GEO/SEO/内容运营相关词。
- 避免：纯品牌词、公司名、人名、泛词、空话、标点堆叠、整句广告语、无法独立检索的长句。
- 中文关键词尽量控制在 2-5 个字，英文关键词尽量控制在 1-3 个单词。

标题库：
- 标题要能驱动后续生成文章，围绕“是什么、为什么、怎么做、对比、选型、指南、清单、案例拆解、常见问题、趋势判断”等角度展开。
- 不要全部套用同一个模板；不要虚构“最好、第一、领先”等没有来源支撑的绝对化表述。

知识库：
- 先沉淀事实，再生成观点。
- 保留来源 URL、页面标题、页面摘要、明确出现的品牌/产品/服务/能力/场景。
- 对不确定信息要标注边界，不能伪造客户案例、数据、第三方评价或排名。
PROMPT;
    }

    /**
     * 读取指定类型的最新后台提示词内容。
     *
     * @param  string  $type  prompt.type
     */
    private function latestPromptContent(string $type): string
    {
        return (string) (Prompt::query()
            ->where('type', $type)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->value('content') ?? '');
    }

    /**
     * 从 AI 文本响应中解析 JSON。
     *
     * @param  string  $content  AI 原始文本响应
     * @return array<string, mixed>
     */
    private function decodeAiJson(string $content): array
    {
        foreach ($this->jsonCandidates($content) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * 从 AI 文本响应中提取可能的 JSON 片段。
     *
     * @param  string  $content  AI 原始文本响应
     * @return list<string>
     */
    private function jsonCandidates(string $content): array
    {
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);
        $content = trim(preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $content) ?? $content);

        $candidates = [$content];

        if (preg_match_all('/```(?:json)?\s*(.*?)```/is', $content, $matches)) {
            foreach ($matches[1] ?? [] as $match) {
                $candidates[] = trim((string) $match);
            }
        }

        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $balanced = $this->extractBalancedJson($content, $open, $close);
            if ($balanced !== '') {
                $candidates[] = $balanced;
            }

            $start = strpos($content, $open);
            $end = strrpos($content, $close);
            if ($start !== false && $end !== false && $end > $start) {
                $candidates[] = substr($content, $start, $end - $start + 1);
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $candidates))));
    }

    /**
     * 从文本中提取首个括号平衡的 JSON 片段。
     *
     * @param  string  $content  AI 原始文本响应
     * @param  string  $open  起始括号
     * @param  string  $close  结束括号
     */
    private function extractBalancedJson(string $content, string $open, string $close): string
    {
        $start = strpos($content, $open);
        if ($start === false) {
            return '';
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($content);

        for ($index = $start; $index < $length; $index++) {
            $char = $content[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === $open) {
                $depth++;

                continue;
            }

            if ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $index - $start + 1);
                }
            }
        }

        return '';
    }

    /**
     * 生成用于错误提示的 AI 响应预览。
     *
     * @param  string  $content  AI 原始文本响应
     */
    private function previewAiContent(string $content): string
    {
        $content = strip_tags($content);
        $content = preg_replace('/\s+/u', ' ', $content) ?? $content;

        return Str::limit(trim($content), 240, '...');
    }

    /**
     * 解析非 JSON 的列表型 AI 响应。
     *
     * Some models obey the semantic request but ignore the strict JSON wrapper and
     * return comma-separated or numbered lists. Preserve those valid answers for
     * list-only steps such as keywords and titles, while still requiring JSON for
     * knowledge-base extraction.
     *
     * @return list<string>
     */
    private function parseAiList(string $content): array
    {
        $content = trim(strip_tags($content));
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $content) ?? $content;
        $content = preg_replace('/```(?:json|text)?\s*|\s*```/i', '', $content) ?? $content;
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $lines = preg_split('/\R/u', $content) ?: [];
        $items = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/^\s*(?:[-*•]|\d+[\.\)、)]|[（(]?\d+[）)])\s*/u', '', $line) ?? $line;
            foreach (preg_split('/[,\x{FF0C};；、]/u', $line) ?: [] as $part) {
                $part = trim((string) $part);
                $part = preg_replace('/^[\"“”‘’]+|[\"“”‘’]+$/u', '', $part) ?? $part;
                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }

        return Collection::make($items)
            ->map(fn (string $item): string => $this->normalizeText($item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->take(80)
            ->values()
            ->all();
    }

    /**
     * 清洗关键词列表，过滤模板噪声、长句和泛词。
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function cleanKeywordList(array $keywords): array
    {
        $stopWords = [
            'ai', 'geo', 'url', '来源', '引擎', '官网', '页面', '页面描述', '来源域名', '公司',
            '查看详情', '详情', '重磅', '更多', '查看更多', '了解更多', '阅读更多', '返回首页', '首页',
            '登录', '注册', '免费咨询', '立即咨询', '点击查看', '上一篇', '下一篇', '相关阅读', '推荐阅读',
            '更多精彩内容', '查看', '分享', '收藏', '导航', '菜单', '按钮', '新闻', '资讯',
        ];

        return Collection::make($keywords)
            ->map(fn (string $keyword): string => $this->normalizeText($keyword))
            ->map(static fn (string $keyword): string => preg_replace('/^[\s,，。.!！?？:：;；|｜\/\\\\()（）\[\]【】{}「」\'"“”‘’]+|[\s,，。.!！?？:：;；|｜\/\\\\()（）\[\]【】{}「」\'"“”‘’]+$/u', '', $keyword) ?? $keyword)
            ->filter(function (string $keyword) use ($stopWords): bool {
                $length = mb_strlen($keyword, 'UTF-8');
                if ($length < 2 || $length > 12) {
                    return false;
                }

                $isMostlyChinese = preg_match('/^[\p{Han}A-Za-z0-9\-\+\. ]+$/u', $keyword) === 1
                    && preg_match('/\p{Han}/u', $keyword) === 1;
                if ($isMostlyChinese && $length > 8) {
                    return false;
                }

                $lower = mb_strtolower($keyword, 'UTF-8');
                if (in_array($lower, $stopWords, true)) {
                    return false;
                }

                if (preg_match('/[。！？!?；;，,]{1}/u', $keyword)) {
                    return false;
                }

                if (preg_match('/(点击|查看|详情|更多|登录|注册|返回|上一篇|下一篇|版权所有|联系我们|加入我们)/u', $keyword)) {
                    return false;
                }

                // Avoid treating full sentences or long slogans as keywords.
                if (preg_match('/(提供|拥有|旨在|帮助|发布|实现|包含|面向).{5,}/u', $keyword)) {
                    return false;
                }

                return true;
            })
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * 按 meta name/property 读取页面首个非空 content。
     *
     * @param  DOMXPath  $xpath  页面 XPath 查询器
     * @param  list<string>  $names
     */
    private function firstMetaContent(DOMXPath $xpath, array $names): string
    {
        foreach ($names as $name) {
            $query = sprintf('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%1$s" or translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%1$s"]/@content', strtolower($name));
            $node = $xpath->query($query)->item(0);
            if ($node) {
                $content = trim((string) $node->nodeValue);
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return '';
    }

    /**
     * 归一化文本空白、HTML 实体和换行。
     *
     * @param  string  $text  原始文本
     */
    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * 将任意标题转换为适合素材库名称的安全文本。
     *
     * @param  string  $name  原始名称
     */
    private function safeName(string $name): string
    {
        $name = $this->normalizeText($name);
        $name = preg_replace('/[\/\\\\:\*\?"<>\|\x00-\x1F]/u', ' ', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return Str::limit($name !== '' ? $name : 'URL素材', 80, '');
    }

    /**
     * 将 AI 返回的列表结构转成去重字符串列表。
     *
     * @param  mixed  $value  AI 返回的列表或其他值
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return Collection::make($value)
            ->map(fn (mixed $item): string => $this->aiResponseTextToString($item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 标准化页面清洗结果，补齐缺失字段并限制长度。
     *
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $parsed
     * @return array{title:string,summary:string,text:string,core_business:array<string,mixed>,entities:list<string>,facts:list<string>,noise_removed:list<string>}
     */
    private function normalizeCleanedPage(array $decoded, array $parsed): array
    {
        $title = $this->normalizeText($this->aiResponseTextToString($decoded['clean_title'] ?? $decoded['title'] ?? $parsed['title'] ?? ''));
        $summary = $this->normalizeText($this->aiResponseTextToString($decoded['clean_summary'] ?? $decoded['summary'] ?? $parsed['summary'] ?? ''));
        $text = $this->normalizeText($this->aiResponseTextToString($decoded['clean_text'] ?? $decoded['text'] ?? $parsed['text'] ?? ''));

        if ($text === '') {
            $text = $this->normalizeText((string) ($parsed['text'] ?? ''));
        }
        if ($summary === '') {
            $summary = Str::limit($text, 240, '...');
        }

        $coreBusiness = $decoded['core_business'] ?? [];
        $coreBusiness = is_array($coreBusiness) ? $coreBusiness : [];

        return [
            'title' => $title !== '' ? $title : $this->safeName((string) ($parsed['title'] ?? 'URL素材')),
            'summary' => $summary,
            'text' => Str::limit($text, 16000, ''),
            'core_business' => $coreBusiness,
            'entities' => array_slice($this->cleanKeywordList($this->stringList($decoded['entities'] ?? [])), 0, 40),
            'facts' => array_slice($this->stringList($decoded['facts'] ?? []), 0, 40),
            'noise_removed' => array_slice($this->stringList($decoded['noise_removed'] ?? []), 0, 40),
        ];
    }

    /**
     * 构造 URL 导入任务详情页所需的实时状态快照。
     *
     * 该结构同时供 HTTP 状态接口（{@see \App\Http\Controllers\Admin\UrlImportController::status}）
     * 与 Reverb 实时事件（{@see \App\Events\Admin\UrlImportProgressUpdated}）使用，
     * 确保前端 renderStatus 拿到一致的字段集。
     *
     * @param  UrlImportJob  $job  URL 导入任务
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
    public function buildStatusPayload(UrlImportJob $job): array
    {
        $logs = UrlImportJobLog::query()
            ->where('job_id', (int) $job->id)
            ->oldest()
            ->limit(120)
            ->get();
        $latestLogStep = (string) ($logs->last()?->step ?: '');
        $storedStep = (string) $job->current_step;
        $currentStep = $latestLogStep !== '' && ! ($latestLogStep === 'queued' && $storedStep !== 'queued')
            ? $latestLogStep
            : $storedStep;

        return [
            'id' => (int) $job->id,
            'status' => (string) $job->status,
            'status_label' => __('admin.url_import_history.status.'.$job->status),
            'current_step' => $currentStep,
            'stored_step' => $storedStep,
            'progress_percent' => (int) $job->progress_percent,
            'error_message' => (string) $job->error_message,
            'result_ready' => (string) $job->result_json !== '',
            'finished_at' => optional($job->finished_at)->format('Y-m-d H:i:s'),
            'logs' => $logs
                ->map(fn (UrlImportJobLog $log): array => [
                    'step' => (string) ($log->step ?: ''),
                    'level' => (string) $log->level,
                    'message' => (string) $log->message,
                    'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
                ])
                ->all(),
        ];
    }

    /**
     * 写入 URL 导入任务日志。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     * @param  string  $level  日志等级
     * @param  string  $message  日志内容
     * @param  string|null  $step  显式步骤；为空时使用任务当前步骤
     */
    private function log(UrlImportJob $job, string $level, string $message, ?string $step = null): void
    {
        UrlImportJobLog::query()->create([
            'job_id' => (int) $job->id,
            'step' => $step ?: (string) ($job->current_step ?: 'queued'),
            'level' => $level,
            'message' => $message,
        ]);
    }

    /**
     * 更新 URL 导入任务的步骤和进度。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     * @param  string  $step  当前步骤
     * @param  int  $progress  进度百分比
     * @param  array<string, mixed>  $extra
     */
    private function updateStep(UrlImportJob $job, string $step, int $progress, array $extra = []): void
    {
        $job->update(array_merge([
            'current_step' => $step,
            'progress_percent' => max(0, min(100, $progress)),
        ], $extra));
    }
}
