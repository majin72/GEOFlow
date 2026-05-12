<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BuildUrlImportKeywordsJob;
use App\Jobs\BuildUrlImportKnowledgeJob;
use App\Jobs\BuildUrlImportPageJsonJob;
use App\Jobs\BuildUrlImportTitlesJob;
use App\Jobs\CompleteUrlImportPreviewJob;
use App\Jobs\FetchUrlImportPageJob;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\GeoFlow\UrlImportProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\View\View;

class UrlImportController extends Controller
{
    /**
     * @param  UrlImportProcessingService  $urlImportProcessingService  URL 智能采集业务服务
     */
    public function __construct(private readonly UrlImportProcessingService $urlImportProcessingService) {}

    /**
     * 显示 URL 智能采集新建页面。
     */
    public function index(): View
    {
        return view('admin.url-import.index', [
            'pageTitle' => __('admin.url_import.page_title'),
            'activeMenu' => 'materials',
            'stats' => $this->loadStats(),
            'aiModelReady' => $this->urlImportProcessingService->hasReadyAnalysisModel(),
            'aiModelConfigUrl' => route('admin.ai-models.index'),
        ]);
    }

    /**
     * 创建 URL 智能采集任务并跳转到任务详情页。
     *
     * @param  Request  $request  后台提交的 URL 采集表单请求
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'project_name' => ['nullable', 'string', 'max:120'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'content_language' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'outputs' => ['array'],
            'outputs.*' => ['string', 'in:knowledge,keywords,titles'],
        ]);

        try {
            $normalized = $this->urlImportProcessingService->normalizeInputUrl((string) $validated['url']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['url' => $exception->getMessage()]);
        }

        try {
            $this->urlImportProcessingService->assertAnalysisModelReady();
        } catch (\Throwable $exception) {
            return redirect()
                ->route('admin.ai-models.index')
                ->withInput()
                ->withErrors(['ai_model' => $exception->getMessage()]);
        }

        $job = UrlImportJob::query()->create([
            'url' => $validated['url'],
            'normalized_url' => $normalized['url'],
            'source_domain' => $normalized['host'],
            'page_title' => $validated['project_name'] ?? '',
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
            'options_json' => json_encode([
                'project_name' => $validated['project_name'] ?? '',
                'source_label' => $validated['source_label'] ?? '',
                'content_language' => $validated['content_language'] ?? '',
                'notes' => $validated['notes'] ?? '',
                'outputs' => $validated['outputs'] ?? ['knowledge', 'keywords', 'titles'],
            ], JSON_UNESCAPED_UNICODE),
            'result_json' => '',
            'error_message' => '',
            'created_by' => Auth::guard('admin')->user()?->username ?? '',
        ]);

        UrlImportJobLog::query()->create([
            'job_id' => $job->id,
            'step' => 'queued',
            'level' => 'info',
            'message' => __('admin.url_import.section.new_job_desc'),
        ]);

        return redirect()->route('admin.url-import.show', ['jobId' => $job->id]);
    }

    /**
     * 启动或重启 URL 智能采集任务。
     *
     * 非测试环境会根据当前步骤构造链式队列；失败任务会从失败步骤继续。
     *
     * @param  int  $jobId  URL 导入任务 ID
     */
    public function run(int $jobId): JsonResponse
    {
        $job = UrlImportJob::query()->whereKey($jobId)->firstOrFail();

        if (in_array($job->status, ['queued', 'failed'], true)) {
            try {
                $this->urlImportProcessingService->assertAnalysisModelReady();
            } catch (\Throwable $exception) {
                $job->update([
                    'status' => 'failed',
                    'progress_percent' => max(1, (int) $job->progress_percent),
                    'error_message' => $exception->getMessage(),
                    'finished_at' => now(),
                ]);

                UrlImportJobLog::query()->create([
                    'job_id' => $job->id,
                    'step' => $job->current_step ?: 'queued',
                    'level' => 'error',
                    'message' => __('admin.url_import.log.failed', ['message' => $exception->getMessage()]),
                ]);

                return response()->json($this->statusPayload($job->refresh()), 422);
            }

            if (app()->runningUnitTests()) {
                $job = $this->urlImportProcessingService->process($job);
            } else {
                $job->update([
                    'status' => 'running',
                    'current_step' => $job->current_step ?: 'queued',
                    'progress_percent' => max(0, (int) $job->progress_percent),
                    'error_message' => '',
                    'started_at' => $job->started_at ?: now(),
                ]);

                $chain = Bus::chain($this->urlImportJobChain($job));
                $queueName = trim((string) config('geoflow.url_import_queue', ''));
                if ($queueName !== '') {
                    $chain->onQueue($queueName);
                }
                $chain->dispatch();
            }
        }

        return response()->json($this->statusPayload($job->refresh()));
    }

    /**
     * 返回 URL 智能采集任务当前状态和最近日志。
     *
     * @param  int  $jobId  URL 导入任务 ID
     */
    public function status(int $jobId): JsonResponse
    {
        $job = UrlImportJob::query()->whereKey($jobId)->firstOrFail();

        return response()->json($this->statusPayload($job));
    }

    /**
     * 将已完成的 URL 智能采集结果写入知识库、关键词库和标题库。
     *
     * @param  int  $jobId  URL 导入任务 ID
     */
    public function commit(int $jobId): RedirectResponse
    {
        $job = UrlImportJob::query()->whereKey($jobId)->firstOrFail();

        try {
            $summary = $this->urlImportProcessingService->commit($job);
        } catch (\Throwable $exception) {
            return back()->withErrors(__('admin.url_import.error.commit_failed').': '.$exception->getMessage());
        }

        return redirect()
            ->route('admin.url-import.show', ['jobId' => $jobId])
            ->with('message', __('admin.url_import.commit.success').'：'.__('admin.url_import_history.import.summary', [
                'knowledge_base' => $summary['knowledge_base'],
                'keywords' => $summary['keywords'],
                'titles' => $summary['titles'],
            ]));
    }

    /**
     * 显示 URL 智能采集任务详情、进度、日志和预览结果。
     *
     * @param  int  $jobId  URL 导入任务 ID
     */
    public function show(int $jobId): View
    {
        $job = UrlImportJob::query()->findOrFail($jobId);

        $job->load(['logs' => fn ($query) => $query->oldest()->limit(120)]);

        return view('admin.url-import.show', [
            'pageTitle' => __('admin.url_import.page_title'),
            'activeMenu' => 'materials',
            'job' => $job,
            'result' => $this->decodeJson((string) $job->result_json),
            'logs' => $job->logs,
        ]);
    }

    /**
     * 显示 URL 智能采集历史列表。
     */
    public function history(): View
    {
        return view('admin.url-import.history', [
            'pageTitle' => __('admin.url_import_history.page_title'),
            'activeMenu' => 'materials',
            'jobs' => UrlImportJob::query()->latest()->paginate(20),
            'stats' => [
                'total' => UrlImportJob::query()->count(),
                'completed' => UrlImportJob::query()->where('status', 'completed')->count(),
                'running' => UrlImportJob::query()->whereIn('status', ['queued', 'running'])->count(),
                'failed' => UrlImportJob::query()->where('status', 'failed')->count(),
            ],
        ]);
    }

    /**
     * 读取素材库容量统计。
     *
     * @return array{knowledge_bases:int,keyword_libraries:int,title_libraries:int}
     */
    private function loadStats(): array
    {
        return [
            'knowledge_bases' => KnowledgeBase::query()->count(),
            'keyword_libraries' => KeywordLibrary::query()->count(),
            'title_libraries' => TitleLibrary::query()->count(),
        ];
    }

    /**
     * @param  string  $value  JSON 字符串
     * @return array<string, mixed>
     */
    private function decodeJson(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 根据当前步骤构造可续跑的 URL 导入任务链；失败后再次启动时从失败步骤继续。
     *
     * @param  UrlImportJob  $job  URL 导入任务
     * @return list<FetchUrlImportPageJob|BuildUrlImportPageJsonJob|BuildUrlImportKnowledgeJob|BuildUrlImportKeywordsJob|BuildUrlImportTitlesJob|CompleteUrlImportPreviewJob>
     */
    private function urlImportJobChain(UrlImportJob $job): array
    {
        $jobId = (int) $job->id;
        $steps = [
            'fetch' => new FetchUrlImportPageJob($jobId),
            'page_json' => new BuildUrlImportPageJsonJob($jobId),
            'knowledge' => new BuildUrlImportKnowledgeJob($jobId),
            'keywords' => new BuildUrlImportKeywordsJob($jobId),
            'titles' => new BuildUrlImportTitlesJob($jobId),
            'preview' => new CompleteUrlImportPreviewJob($jobId),
        ];

        $currentStep = (string) ($job->current_step ?: 'queued');
        $startAt = match ($currentStep) {
            'fetch' => 'fetch',
            'page_json' => 'page_json',
            'knowledge' => 'knowledge',
            'keywords' => 'keywords',
            'titles' => 'titles',
            'preview' => 'preview',
            default => 'fetch',
        };

        $keys = array_keys($steps);
        $offset = array_search($startAt, $keys, true);
        $offset = $offset === false ? 0 : $offset;

        return array_values(array_slice($steps, $offset));
    }

    /**
     * 委托至 service 层构造前端所需的状态快照，保持原有 HTTP 接口契约不变。
     *
     * 同一份 payload 结构也被 {@see \App\Events\Admin\UrlImportProgressUpdated} 通过 Reverb 推送，
     * 因此真实实现集中在 {@see UrlImportProcessingService::buildStatusPayload}。
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
    private function statusPayload(UrlImportJob $job): array
    {
        return $this->urlImportProcessingService->buildStatusPayload($job);
    }
}
