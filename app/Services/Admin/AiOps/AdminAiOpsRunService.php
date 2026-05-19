<?php

namespace App\Services\Admin\AiOps;

use App\Events\Admin\AdminAiOpsRunUpdated;
use App\Models\AdminAiOpsRun;
use App\Models\AdminAiOpsStep;
use App\Models\AdminAiOpsToolApproval;
use Illuminate\Support\Str;
use Throwable;

/**
 * AI 运维执行记录服务：统一负责步骤落库、状态快照和实时广播。
 */
class AdminAiOpsRunService
{
    /**
     * 写入一个执行步骤。
     *
     * @param  array<string,mixed>  $meta
     */
    public function addStep(AdminAiOpsRun $run, string $type, string $status, string $title, ?string $inputSummary = null, ?string $outputSummary = null, array $meta = []): AdminAiOpsStep
    {
        $position = ((int) AdminAiOpsStep::query()->where('run_id', (int) $run->id)->max('position')) + 1;

        $step = AdminAiOpsStep::query()->create([
            'run_id' => (int) $run->id,
            'position' => $position,
            'type' => $type,
            'status' => $status,
            'title' => $title,
            'input_summary' => $this->redact($inputSummary),
            'output_summary' => $this->redact($outputSummary),
            'meta' => $this->redactArray($meta),
            'started_at' => now(),
            'finished_at' => in_array($status, ['completed', 'failed', 'cancelled'], true) ? now() : null,
        ]);

        $this->broadcast($run->fresh() ?? $run);

        return $step;
    }

    /**
     * 就地更新一条步骤（用于同一卡片从「执行中」过渡到「已完成」，避免遗留 running 记录）。
     *
     * @param  array<string, mixed>  $attributes  可含：status, title, input_summary, output_summary, error_message, meta, started_at, finished_at
     */
    public function updateStep(AdminAiOpsStep $step, array $attributes): AdminAiOpsStep
    {
        $allowedKeys = ['status', 'title', 'input_summary', 'output_summary', 'error_message', 'meta', 'started_at', 'finished_at'];
        $fill = [];
        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }
            $fill[$key] = $attributes[$key];
        }

        if (array_key_exists('input_summary', $fill)) {
            $fill['input_summary'] = $fill['input_summary'] === null ? null : $this->redact((string) $fill['input_summary']);
        }
        if (array_key_exists('output_summary', $fill)) {
            $fill['output_summary'] = $fill['output_summary'] === null ? null : $this->redact((string) $fill['output_summary']);
        }
        if (array_key_exists('error_message', $fill)) {
            $fill['error_message'] = $fill['error_message'] === null ? null : $this->redact((string) $fill['error_message']);
        }
        if (array_key_exists('meta', $fill) && is_array($fill['meta'])) {
            $fill['meta'] = $this->redactArray($fill['meta']);
        }

        if (isset($fill['status']) && in_array((string) $fill['status'], ['completed', 'failed', 'cancelled'], true)) {
            if (! array_key_exists('finished_at', $fill) || $fill['finished_at'] === null) {
                $fill['finished_at'] = now();
            }
        }

        $step->forceFill($fill)->save();

        $run = AdminAiOpsRun::query()->with(['steps', 'attachments', 'aiModel'])->find((int) $step->run_id);
        if ($run) {
            $this->broadcast($run);
        }

        return $step->fresh() ?? $step;
    }

    /**
     * 更新执行状态并广播。
     *
     * @param  array<string,mixed>  $attributes
     */
    public function updateRun(AdminAiOpsRun $run, array $attributes): AdminAiOpsRun
    {
        if (isset($attributes['error_message'])) {
            $attributes['error_message'] = $this->redact((string) $attributes['error_message']);
        }
        if (isset($attributes['result_summary'])) {
            $attributes['result_summary'] = $this->redact((string) $attributes['result_summary']);
        }

        $run->forceFill($attributes)->save();
        $run->session()->touch();
        $run = $run->fresh(['steps', 'attachments', 'aiModel']) ?? $run;
        $this->broadcast($run);

        return $run;
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(AdminAiOpsRun $run): array
    {
        $run->loadMissing(['steps', 'attachments', 'aiModel']);

        $aiModelPayload = null;
        if ($run->ai_model_id && $run->aiModel) {
            $aiModelPayload = [
                'id' => (int) $run->aiModel->id,
                'name' => trim((string) ($run->aiModel->name ?? '')),
                'model_id' => trim((string) ($run->aiModel->model_id ?? '')),
            ];
        }

        $approvalPending = null;
        $assistantPartialPreview = null;
        if ((string) $run->status === 'awaiting_confirmation') {
            $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
            $partial = trim((string) ($snapshot['partial_assistant_text'] ?? ''));
            if ($partial !== '') {
                $assistantPartialPreview = Str::limit($partial, 8000, '…');
            }

            $pendingRow = AdminAiOpsToolApproval::query()
                ->where('run_id', (int) $run->id)
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->first();

            if ($pendingRow instanceof AdminAiOpsToolApproval) {
                $approvalPending = [
                    'id' => (string) $pendingRow->id,
                    'tool_name' => (string) $pendingRow->tool_name,
                    'tool_call_id' => trim((string) ($snapshot['last_tool_call_id'] ?? '')),
                    'summary' => $this->approvalSummaryLine($pendingRow),
                    'expires_at' => $pendingRow->expires_at?->toIso8601String(),
                    'risk_label' => $pendingRow->risk_label ? (string) $pendingRow->risk_label : null,
                    'args_fingerprint' => (string) $pendingRow->args_fingerprint,
                ];
            }
        }

        $assistantTimeline = null;
        $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
        $timelineRaw = $snapshot['assistant_timeline'] ?? null;
        if (is_array($timelineRaw) && $this->timelinePayloadHasDisplayableContent($timelineRaw)) {
            $assistantTimeline = $timelineRaw;
        }

        return [
            'id' => (int) $run->id,
            'session_id' => (int) $run->session_id,
            'status' => (string) $run->status,
            'ai_model_id' => $run->ai_model_id ? (int) $run->ai_model_id : null,
            'ai_model' => $aiModelPayload,
            'input_text' => $this->redact((string) ($run->input_text ?? '')),
            'plan' => $this->redactArray(is_array($run->plan) ? $run->plan : []),
            'result_summary' => (string) ($run->result_summary ?? ''),
            'error_message' => (string) ($run->error_message ?? ''),
            'can_cancel' => in_array((string) $run->status, ['queued', 'processing'], true),
            'approval_pending' => $approvalPending,
            'assistant_partial_preview' => $assistantPartialPreview,
            'assistant_timeline' => $assistantTimeline,
            'steps' => $run->steps->map(fn (AdminAiOpsStep $step): array => [
                'id' => (int) $step->id,
                'position' => (int) $step->position,
                'type' => (string) $step->type,
                'status' => (string) $step->status,
                'title' => (string) $step->title,
                'input_summary' => (string) ($step->input_summary ?? ''),
                'output_summary' => (string) ($step->output_summary ?? ''),
                'error_message' => (string) ($step->error_message ?? ''),
                'meta' => is_array($step->meta) ? $this->redactArray($step->meta) : [],
                'started_at' => $step->started_at?->toDateTimeString(),
                'finished_at' => $step->finished_at?->toDateTimeString(),
            ])->values()->all(),
            'attachments' => $run->attachments->map(fn ($attachment): array => [
                'id' => (int) $attachment->id,
                'original_name' => (string) $attachment->original_name,
                'mime_type' => (string) $attachment->mime_type,
                'size' => (int) $attachment->size,
            ])->values()->all(),
        ];
    }

    /**
     * 将助手时间线写入 plan_stream_snapshot（刷新后可还原工具卡片）。
     *
     * @param  array<string, mixed>  $timeline
     */
    public function persistAssistantTimeline(AdminAiOpsRun $run, array $timeline): AdminAiOpsRun
    {
        $snapshot = is_array($run->plan_stream_snapshot) ? $run->plan_stream_snapshot : [];
        $snapshot['assistant_timeline'] = $timeline;

        $run->forceFill(['plan_stream_snapshot' => $snapshot])->save();
        $run->session()->touch();

        return $run->fresh(['steps', 'attachments', 'aiModel']) ?? $run;
    }

    public function broadcast(AdminAiOpsRun $run): void
    {
        try {
            broadcast(new AdminAiOpsRunUpdated((int) $run->id, $this->payload($run)));
        } catch (Throwable) {
            // 实时推送失败不应影响后台执行，前端仍可通过轮询恢复。
        }
    }

    private function redact(?string $value): string
    {
        $value = (string) ($value ?? '');
        if ($value === '') {
            return '';
        }

        return preg_replace('/(api[_-]?key|token|password|secret)(["\':=\s]+)([^,"\'}\s]+)/iu', '$1$2******', $value) ?: $value;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function redactArray(array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || $encoded === '') {
            return $payload;
        }

        $decoded = json_decode($this->redact($encoded), true);

        return is_array($decoded) ? $decoded : $payload;
    }

    /**
     * 供前端 Modal 展示的一行审批摘要（不含敏感参数原文）。
     */
    private function approvalSummaryLine(AdminAiOpsToolApproval $row): string
    {
        $chunks = ['需确认的写操作'];
        if (trim((string) ($row->risk_label ?? '')) !== '') {
            $chunks[] = trim((string) $row->risk_label);
        }
        $chunks[] = (string) $row->tool_name;

        return Str::limit(implode(' · ', array_filter($chunks)), 220, '…');
    }

    /**
     * 时间线是否含可展示段（避免下发空壳 assistant_timeline 阻断前端回退到 result_summary）。
     *
     * @param  array<string, mixed>  $timeline
     */
    private function timelinePayloadHasDisplayableContent(array $timeline): bool
    {
        if ($this->segmentListHasDisplayableContent(is_array($timeline['segments'] ?? null) ? $timeline['segments'] : [])) {
            return true;
        }

        $rounds = is_array($timeline['completedRounds'] ?? null) ? $timeline['completedRounds'] : [];
        foreach ($rounds as $round) {
            if (! is_array($round)) {
                continue;
            }
            if ($this->segmentListHasDisplayableContent(is_array($round['segments'] ?? null) ? $round['segments'] : [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     */
    private function segmentListHasDisplayableContent(array $segments): bool
    {
        foreach ($segments as $segment) {
            $kind = (string) ($segment['kind'] ?? '');
            if ($kind === 'text' && trim((string) ($segment['text'] ?? '')) !== '') {
                return true;
            }
            if ($kind === 'tools' && is_array($segment['tools'] ?? null) && $segment['tools'] !== []) {
                return true;
            }
        }

        return false;
    }
}
