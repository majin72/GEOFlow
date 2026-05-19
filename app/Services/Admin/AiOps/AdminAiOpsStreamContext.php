<?php

namespace App\Services\Admin\AiOps;

/**
 * 单次 AI 运维首轮 SSE 周期内的可变上下文（供工具挂起审批时读取已流式输出的片段）。
 */
final class AdminAiOpsStreamContext
{
    /**
     * 当前流式周期内最近一次下发的工具调用 id（与 SSE tool/calling 一致），用于审批挂起时补发 tool/done。
     */
    public string $lastToolCallId = '';

    public AdminAiOpsAssistantTimelineRecorder $timeline;

    public function __construct(
        public int $runId,
        public int $adminId,
        public string $partialAssistantText = '',
        ?AdminAiOpsAssistantTimelineRecorder $timeline = null,
    ) {
        $this->timeline = $timeline ?? new AdminAiOpsAssistantTimelineRecorder;
    }

    /**
     * 基于已有 run 快照构造上下文（续流、审批后）。
     *
     * @param  array<string, mixed>|null  $planStreamSnapshot
     */
    public static function forRun(int $runId, int $adminId, ?array $planStreamSnapshot): self
    {
        $snapshot = is_array($planStreamSnapshot) ? $planStreamSnapshot : [];
        $partial = (string) ($snapshot['partial_assistant_text'] ?? '');

        return new self(
            runId: $runId,
            adminId: $adminId,
            partialAssistantText: $partial,
            timeline: AdminAiOpsAssistantTimelineRecorder::fromSnapshot($snapshot),
        );
    }

    /**
     * 更新当前累积的助手可见文本（与 SSE delta 一致）。
     */
    public function setPartialAssistantText(string $text): void
    {
        $this->partialAssistantText = $text;
        $this->timeline->applyDelta($text);
    }
}
