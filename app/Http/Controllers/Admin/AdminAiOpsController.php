<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsSession;
use App\Models\AiModel;
use App\Services\Admin\AiOps\AdminAiOpsChatService;
use App\Services\Admin\AiOps\AdminAiOpsRunService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolCall as AiStreamToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as AiStreamToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * 后台 AI 运维：POST 创建排队 run；GET（EventSource）流式拉取模型输出并完成落库。
 */
class AdminAiOpsController extends Controller
{
    /**
     * 显示 AI 运维独立会话页。
     */
    public function index(): View
    {
        return view('admin.ai-ops.index', [
            'pageTitle' => __('admin.ai_ops.page_title'),
            'activeMenu' => 'ai_ops',
            'adminSiteName' => AdminWeb::siteName(),
            'models' => $this->availableChatModels(),
        ]);
    }

    /**
     * 返回当前管理员的历史会话列表。
     */
    public function sessions(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $sessions = AdminAiOpsSession::query()
            ->where('admin_id', (int) $admin->id)
            ->with(['runs' => fn ($query) => $query->latest('id')])
            ->latest('updated_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (AdminAiOpsSession $session): array => $this->sessionListItem($session))
            ->values()
            ->all();

        return response()->json(['items' => $sessions]);
    }

    /**
     * 创建一个空的 AI 运维会话。
     */
    public function createSession(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
        ]);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $session = AdminAiOpsSession::query()->create([
            'admin_id' => (int) $admin->id,
            'title' => trim((string) ($payload['title'] ?? '')) ?: __('admin.ai_ops.new_session_title'),
        ]);

        return response()->json($this->sessionPayload($session), 201);
    }

    /**
     * 返回指定会话的完整历史（runs 按时间正序）。
     */
    public function showSession(Request $request, int $sessionId, AdminAiOpsRunService $runs): JsonResponse
    {
        $session = $this->findOwnedSession($request, $sessionId);

        return response()->json($this->sessionPayload($session, $runs));
    }

    /**
     * 创建一条待流式补全的 run（status=queued）；客户端随后用 EventSource 打开 {@see stream}。
     */
    public function chat(Request $request, AdminAiOpsRunService $runs): JsonResponse
    {
        $payload = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'ai_model_id' => ['required', 'integer', 'exists:ai_models,id'],
            'session_id' => ['nullable', 'integer', 'exists:admin_ai_ops_sessions,id'],
        ]);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $message = trim((string) $payload['message']);

        $session = ! empty($payload['session_id'])
            ? $this->findOwnedSession($request, (int) $payload['session_id'])
            : AdminAiOpsSession::query()->create([
                'admin_id' => (int) $admin->id,
                'title' => Str::limit($message, 60, ''),
            ]);

        if (trim((string) $session->title) === '' || (string) $session->title === __('admin.ai_ops.new_session_title')) {
            $session->update(['title' => Str::limit($message, 60, '')]);
        }

        $modelId = $this->resolveAiModelId((int) $payload['ai_model_id']);

        $run = AdminAiOpsRun::query()->create([
            'session_id' => (int) $session->id,
            'admin_id' => (int) $admin->id,
            'ai_model_id' => $modelId,
            'status' => 'queued',
            'input_text' => $message,
        ]);

        $session->touch();

        $run = $run->fresh(['steps', 'attachments', 'aiModel']) ?? $run;

        return response()->json([
            'session' => [
                'id' => (int) $session->id,
                'title' => (string) ($session->title ?: __('admin.ai_ops.new_session_title')),
            ],
            'run' => $runs->payload($run),
        ], 201);
    }

    /**
     * SSE（EventSource）：对 queued run 在本 HTTP 连接内持锁流式调用模型，推送 event: delta / stream_status / tool / run，终态发送 event: done。
     */
    public function stream(Request $request, int $runId, AdminAiOpsRunService $runs, AdminAiOpsChatService $chat): StreamedResponse
    {
        set_time_limit(300);
        $this->findOwnedRun($request, $runId);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $adminId = (int) $admin->id;

        $maxSeconds = (int) config('geoflow.admin_ai_ops_chat_stream_max_seconds', 900);

        return response()->stream(function () use ($runId, $adminId, $runs, $chat, $maxSeconds): void {
            $lock = Cache::lock('geoflow:admin_ai_ops_chat_stream:'.(int) $runId, $maxSeconds + 120);

            try {
                $lock->block($maxSeconds + 120);

                $run = AdminAiOpsRun::query()
                    ->where('admin_id', $adminId)
                    ->whereKey($runId)
                    ->with(['steps', 'attachments', 'aiModel'])
                    ->first();

                if (! $run instanceof AdminAiOpsRun) {
                    $this->writeAdminAiOpsSseJsonEvent('stream_error', ['message' => 'not_found']);
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $status = (string) $run->status;

                if (in_array($status, ['completed', 'failed'], true)) {
                    $this->writeAdminAiOpsSseRunEvent($runs->payload($run->fresh(['steps', 'attachments', 'aiModel']) ?? $run));
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                if ($status !== 'queued') {
                    $this->writeAdminAiOpsSseRunEvent($runs->payload($run->fresh(['steps', 'attachments', 'aiModel']) ?? $run));
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $run = $runs->updateRun($run, [
                    'status' => 'processing',
                    'started_at' => now(),
                ]);

                $this->writeAdminAiOpsSseRunEvent($runs->payload($run));

                $aiModel = $run->aiModel;
                if (! $aiModel instanceof AiModel) {
                    $run = $runs->updateRun($run->fresh() ?? $run, [
                        'status' => 'failed',
                        'error_message' => 'AI 模型不存在或已被删除。',
                        'finished_at' => now(),
                    ]);
                    $this->writeAdminAiOpsSseRunEvent($runs->payload($run));
                    $this->writeAdminAiOpsSseDoneEvent();

                    return;
                }

                $priorMessages = $chat->priorMessagesBeforeRun((int) $run->session_id, (int) $run->id);
                $currentUserMessage = trim((string) ($run->input_text ?? ''));
                $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));

                $deadline = microtime(true) + $maxSeconds;

                try {
                    $assistantText = $chat->streamAssistantReply(
                        $currentUserMessage,
                        $priorMessages,
                        $aiModel,
                        function (string $accumulated) use ($deadline): void {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('模型输出超过单连接时间上限，已中止。');
                            }
                            $this->writeAdminAiOpsSseJsonEvent('delta', ['text' => $accumulated]);
                        },
                        function (object $event): void {
                            $this->emitAdminAiOpsSseFromAiStreamEvent($event);
                        },
                        null,
                    );

                    $assistantText = trim((string) $assistantText);

                    if ($assistantText === '') {
                        throw new \RuntimeException('模型返回为空');
                    }

                    $run = $runs->updateRun($run->fresh() ?? $run, [
                        'status' => 'completed',
                        'result_summary' => $assistantText,
                        'finished_at' => now(),
                    ]);
                } catch (Throwable $e) {
                    $human = OpenAiRuntimeProvider::normalizeApiException($e, $providerUrl);
                    $run = $runs->updateRun($run->fresh() ?? $run, [
                        'status' => 'failed',
                        'error_message' => $human !== '' ? $human : trim($e->getMessage()),
                        'finished_at' => now(),
                    ]);
                }

                $this->writeAdminAiOpsSseRunEvent($runs->payload($run->fresh(['steps', 'attachments', 'aiModel']) ?? $run));
                $this->writeAdminAiOpsSseDoneEvent();
            } catch (Throwable $e) {
                $this->writeAdminAiOpsSseJsonEvent('stream_error', ['message' => trim($e->getMessage()) ?: 'stream_failed']);
                $this->writeAdminAiOpsSseDoneEvent();
            } finally {
                try {
                    $lock->release();
                } catch (Throwable) {
                    //
                }
            }
        }, 200, $this->adminAiOpsSseResponseHeaders());
    }

    /**
     * 查询当前管理员拥有的 run。
     */
    private function findOwnedRun(Request $request, int $runId): AdminAiOpsRun
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return AdminAiOpsRun::query()
            ->where('admin_id', (int) $admin->id)
            ->findOrFail($runId);
    }

    /**
     * 查询当前管理员拥有的会话。
     */
    private function findOwnedSession(Request $request, int $sessionId): AdminAiOpsSession
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return AdminAiOpsSession::query()
            ->where('admin_id', (int) $admin->id)
            ->findOrFail($sessionId);
    }

    /**
     * 构造会话列表项。
     *
     * @return array<string,mixed>
     */
    private function sessionListItem(AdminAiOpsSession $session): array
    {
        $latestRun = $session->runs->first();

        return [
            'id' => (int) $session->id,
            'title' => (string) ($session->title ?: __('admin.ai_ops.new_session_title')),
            'updated_at' => $session->updated_at?->toDateTimeString(),
            'latest_run' => $latestRun ? [
                'id' => (int) $latestRun->id,
                'status' => (string) $latestRun->status,
                'input_text' => Str::limit((string) $latestRun->input_text, 80, ''),
                'result_summary' => Str::limit((string) $latestRun->result_summary, 80, ''),
            ] : null,
        ];
    }

    /**
     * 构造会话详情响应。
     *
     * @return array<string,mixed>
     */
    private function sessionPayload(AdminAiOpsSession $session, ?AdminAiOpsRunService $runs = null): array
    {
        $session->load(['runs' => fn ($query) => $query->with(['steps', 'attachments', 'aiModel'])->oldest('id')]);
        $runs ??= app(AdminAiOpsRunService::class);

        return [
            'id' => (int) $session->id,
            'title' => (string) ($session->title ?: __('admin.ai_ops.new_session_title')),
            'created_at' => $session->created_at?->toDateTimeString(),
            'updated_at' => $session->updated_at?->toDateTimeString(),
            'runs' => $session->runs->map(fn (AdminAiOpsRun $run): array => $runs->payload($run))->values()->all(),
        ];
    }

    /**
     * 校验 AI 运维使用的模型必须是启用的聊天模型。
     */
    private function resolveAiModelId(int $modelId): int
    {
        $exists = AiModel::query()
            ->whereKey($modelId)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->exists();

        abort_if(! $exists, 422, '请选择可用的聊天模型。');

        return $modelId;
    }

    /**
     * 获取可用于 AI 运维的聊天模型。
     */
    private function availableChatModels()
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get(['id', 'name', 'model_id']);
    }

    /**
     * SSE 响应头：禁用代理缓冲以便及时下推。
     *
     * @return array<string, string>
     */
    private function adminAiOpsSseResponseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * 将 Laravel AI 流中的非文本事件映射为前端可展示的 SSE（连接、工具调用与结果）。
     */
    private function emitAdminAiOpsSseFromAiStreamEvent(object $event): void
    {
        if ($event instanceof StreamStart) {
            $this->writeAdminAiOpsSseJsonEvent('stream_status', [
                'kind' => 'connected',
                'provider' => $event->provider,
                'model' => $event->model,
            ]);

            return;
        }

        if ($event instanceof AiStreamToolCall) {
            $this->writeAdminAiOpsSseJsonEvent('tool', [
                'phase' => 'calling',
                'tool_call_id' => $event->toolCall->id,
                'tool_name' => $event->toolCall->name,
                'arguments_preview' => $this->adminAiOpsSseEncodeJsonPreview($event->toolCall->arguments, 1200),
            ]);

            return;
        }

        if ($event instanceof AiStreamToolResult) {
            $this->writeAdminAiOpsSseJsonEvent('tool', [
                'phase' => 'done',
                'tool_call_id' => $event->toolResult->id,
                'tool_name' => $event->toolResult->name,
                'successful' => $event->successful,
                'error' => $event->error,
            ]);
        }
    }

    /**
     * 将数组编码为 JSON 预览字符串并截断，避免 SSE 体积过大。
     *
     * @param  array<string, mixed>  $payload
     */
    private function adminAiOpsSseEncodeJsonPreview(array $payload, int $maxBytes): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return '';
        }
        if (strlen($json) <= $maxBytes) {
            return $json;
        }

        return substr($json, 0, $maxBytes).'…';
    }

    /**
     * 将当前 PHP 输出缓冲刷出，供 SSE 立即送达客户端。
     */
    private function flushAdminAiOpsSseOutput(): void
    {
        $levels = ob_get_level();
        for ($i = 0; $i < $levels; $i++) {
            ob_flush();
        }
        flush();
    }

    /**
     * 写入一条命名 SSE 事件（data 为 JSON 对象）。
     *
     * @param  array<string, mixed>  $data
     */
    private function writeAdminAiOpsSseJsonEvent(string $event, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        echo 'event: '.$event."\n";
        echo 'data: '.($json !== false ? $json : '{}')."\n\n";
        $this->flushAdminAiOpsSseOutput();
    }

    /**
     * 写入 event: run（payload 为 run 快照数组）。
     *
     * @param  array<string, mixed>  $runPayload
     */
    private function writeAdminAiOpsSseRunEvent(array $runPayload): void
    {
        $this->writeAdminAiOpsSseJsonEvent('run', ['run' => $runPayload]);
    }

    /**
     * 写入 event: done，通知浏览器可关闭 EventSource。
     */
    private function writeAdminAiOpsSseDoneEvent(): void
    {
        echo "event: done\n";
        echo "data: {}\n\n";
        $this->flushAdminAiOpsSseOutput();
    }
}
