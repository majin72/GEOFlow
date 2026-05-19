<?php

namespace App\Services\Admin\AiOps;

/**
 * 按 SSE 事件顺序记录助手输出：交替的 text 段与 tools 段（不靠正文下标插入）。
 */
final class AdminAiOpsAssistantTimelineRecorder
{
    /**
     * @var array<int, array{segments: array<int, array<string, mixed>>}>
     */
    private array $completedRounds = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $segments = [];

    private bool $textLocked = false;

    private string $preToolTextSnapshot = '';

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function fromSnapshot(?array $snapshot): self
    {
        $recorder = new self;
        if (! is_array($snapshot)) {
            return $recorder;
        }
        $timeline = $snapshot['assistant_timeline'] ?? null;
        if (! is_array($timeline)) {
            return $recorder;
        }
        $recorder->hydrateFromArray($timeline);

        return $recorder;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function hydrateFromArray(array $data): void
    {
        if (isset($data['segments']) && is_array($data['segments'])) {
            $this->segments = $data['segments'];
            $this->completedRounds = is_array($data['completedRounds'] ?? null) ? $data['completedRounds'] : [];

            return;
        }

        $this->hydrateLegacyWavesFormat($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'completedRounds' => $this->completedRounds,
            'segments' => $this->segments,
        ];
    }

    /**
     * 累积正文 delta（全量替换当前 text 段，或工具之后的新 text 段）。
     */
    public function applyDelta(string $accumulatedText): void
    {
        $t = (string) $accumulatedText;

        if ($this->textLocked) {
            if ($this->isStalePreToolDelta($t)) {
                return;
            }
            $this->textLocked = false;
            $this->appendOrUpdateTrailingTextSegment($t, true);

            return;
        }

        $this->appendOrUpdateTrailingTextSegment($t, $this->hasToolsInSegments());
    }

    /**
     * 记录工具 calling（按事件顺序追加 tools 段，并冻结此前的 text）。
     */
    public function recordToolCalling(string $toolCallId, string $toolName, string $argumentsPreview): void
    {
        if (! $this->hasOpenToolsSegment()) {
            $this->preToolTextSnapshot = $this->plainTextFromSegments();
            $this->textLocked = true;
            $this->segments[] = [
                'kind' => 'tools',
                'tools' => [],
            ];
        }

        $this->appendToolToOpenSegment($toolCallId, $toolName, $argumentsPreview, 'calling');
    }

    /**
     * 记录工具 done（按 tool_call_id 更新）。
     */
    public function recordToolDone(
        string $toolCallId,
        string $toolName,
        bool $successful,
        string $error,
        ?string $resultPreview,
        ?int $durationMs = null,
        ?string $rawOutput = null,
    ): void {
        if (! $this->updateToolByCallId($toolCallId, $successful, $error, $resultPreview, $durationMs, $rawOutput)) {
            if (! $this->hasOpenToolsSegment()) {
                $this->segments[] = ['kind' => 'tools', 'tools' => []];
            }
            $this->appendToolToOpenSegment($toolCallId, $toolName, '', 'done', $successful, $error, $resultPreview);
        }

        if (! $this->hasCallingTools()) {
            $this->textLocked = false;
        }
    }

    /**
     * 审批挂起：将仍为 calling 的工具标记为 awaiting_approval（勿显示为已完成）。
     */
    public function markCallingToolsAwaitingApproval(?string $resultPreview): void
    {
        foreach ($this->segments as &$segment) {
            if (($segment['kind'] ?? '') !== 'tools' || ! is_array($segment['tools'] ?? null)) {
                continue;
            }
            foreach ($segment['tools'] as &$tool) {
                if (($tool['phase'] ?? '') === 'calling') {
                    $tool['phase'] = 'awaiting_approval';
                    $tool['successful'] = false;
                    if ($resultPreview !== null && $resultPreview !== '') {
                        $tool['resultPreview'] = $resultPreview;
                    }
                }
            }
        }
        unset($segment, $tool);
    }

    /**
     * 用户拒绝审批：按 tool_call_id 将待确认工具标为 rejected。
     */
    public function markToolRejectedByCallId(string $toolCallId, ?string $reason): void
    {
        $tid = trim($toolCallId);
        if ($tid === '') {
            return;
        }

        foreach ($this->segments as &$segment) {
            if (($segment['kind'] ?? '') !== 'tools' || ! is_array($segment['tools'] ?? null)) {
                continue;
            }
            foreach ($segment['tools'] as &$tool) {
                if (($tool['toolCallId'] ?? '') !== $tid) {
                    continue;
                }
                $tool['phase'] = 'rejected';
                $tool['successful'] = false;
                if ($reason !== null && trim($reason) !== '') {
                    $tool['error'] = trim($reason);
                }

                return;
            }
        }
        unset($segment, $tool);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrateLegacyWavesFormat(array $data): void
    {
        $this->completedRounds = is_array($data['completedRounds'] ?? null) ? $data['completedRounds'] : [];
        $text = (string) ($data['text'] ?? '');
        $waves = is_array($data['waves'] ?? null) ? $data['waves'] : [];
        $activeWave = is_array($data['activeWave'] ?? null) ? $data['activeWave'] : null;
        if ($activeWave !== null) {
            $waves[] = $activeWave;
        }

        $this->segments = [];
        $offset = 0;
        $len = mb_strlen($text, 'UTF-8');

        foreach ($waves as $wave) {
            $end = min($len, max(0, (int) ($wave['end'] ?? 0)));
            if ($end > $offset) {
                $slice = mb_substr($text, $offset, $end - $offset, 'UTF-8');
                if (trim($slice) !== '') {
                    $this->segments[] = ['kind' => 'text', 'text' => $slice];
                }
            }
            $this->segments[] = [
                'kind' => 'tools',
                'tools' => is_array($wave['tools'] ?? null) ? $wave['tools'] : [],
            ];
            $offset = $end;
        }

        if ($offset < $len) {
            $tail = mb_substr($text, $offset, null, 'UTF-8');
            if (trim($tail) !== '') {
                $this->segments[] = ['kind' => 'text', 'text' => $tail];
            }
        }
    }

    private function isStalePreToolDelta(string $accumulated): bool
    {
        if (! $this->textLocked || ! $this->hasCallingTools()) {
            return false;
        }

        $snap = $this->preToolTextSnapshot;

        return $snap !== '' && str_starts_with($accumulated, $snap);
    }

    private function appendOrUpdateTrailingTextSegment(string $text, bool $afterTools = false): void
    {
        if ($afterTools) {
            $text = $this->stripPreToolPrefix($text);
        }

        if ($this->segments !== [] && ($this->segments[array_key_last($this->segments)]['kind'] ?? '') === 'text') {
            $this->segments[array_key_last($this->segments)]['text'] = $text;

            return;
        }

        $this->segments[] = [
            'kind' => 'text',
            'text' => $text,
        ];
    }

    private function stripPreToolPrefix(string $accumulated): string
    {
        $snap = $this->preToolTextSnapshot;
        if ($snap === '' || ! str_starts_with($accumulated, $snap)) {
            return $accumulated;
        }

        $rest = mb_substr($accumulated, mb_strlen($snap, 'UTF-8'), null, 'UTF-8');

        return ltrim($rest, " \t\n\r");
    }

    private function hasToolsInSegments(): bool
    {
        foreach ($this->segments as $segment) {
            if (($segment['kind'] ?? '') === 'tools') {
                return true;
            }
        }

        return false;
    }

    private function hasOpenToolsSegment(): bool
    {
        if ($this->segments === []) {
            return false;
        }

        return ($this->segments[array_key_last($this->segments)]['kind'] ?? '') === 'tools';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function openToolsSegment(): ?array
    {
        if (! $this->hasOpenToolsSegment()) {
            return null;
        }

        return $this->segments[array_key_last($this->segments)];
    }

    private function appendToolToOpenSegment(
        string $toolCallId,
        string $toolName,
        string $argumentsPreview,
        string $phase,
        bool $successful = true,
        string $error = '',
        ?string $resultPreview = null,
    ): void {
        $segment = $this->openToolsSegment();
        if ($segment === null) {
            return;
        }

        $tool = [
            'toolCallId' => $toolCallId,
            'name' => $toolName,
            'phase' => $phase,
            'preview' => $argumentsPreview,
        ];
        if ($phase === 'done') {
            $tool['successful'] = $successful;
            $tool['error'] = $error;
            if ($resultPreview !== null && $resultPreview !== '') {
                $tool['resultPreview'] = $resultPreview;
            }
        }

        $this->segments[array_key_last($this->segments)]['tools'][] = $tool;
    }

    private function updateToolByCallId(
        string $toolCallId,
        bool $successful,
        string $error,
        ?string $resultPreview,
        ?int $durationMs,
        ?string $rawOutput,
    ): bool {
        foreach ($this->segments as &$segment) {
            if (($segment['kind'] ?? '') !== 'tools' || ! is_array($segment['tools'] ?? null)) {
                continue;
            }
            foreach ($segment['tools'] as &$tool) {
                if (($tool['toolCallId'] ?? '') !== $toolCallId) {
                    continue;
                }
                $tool['phase'] = 'done';
                $tool['successful'] = $successful;
                $tool['error'] = $error;
                if ($resultPreview !== null && $resultPreview !== '') {
                    $tool['resultPreview'] = $resultPreview;
                }
                if ($durationMs !== null) {
                    $tool['durationMs'] = $durationMs;
                }
                if ($rawOutput !== null && $rawOutput !== '') {
                    $tool['rawOutput'] = $rawOutput;
                }

                return true;
            }
        }

        return false;
    }

    private function plainTextFromSegments(): string
    {
        $parts = [];
        foreach ($this->segments as $segment) {
            if (($segment['kind'] ?? '') === 'text') {
                $parts[] = (string) ($segment['text'] ?? '');
            }
        }

        return implode("\n\n", array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }

    private function hasCallingTools(): bool
    {
        foreach ($this->segments as $segment) {
            if (($segment['kind'] ?? '') !== 'tools' || ! is_array($segment['tools'] ?? null)) {
                continue;
            }
            foreach ($segment['tools'] as $tool) {
                if (($tool['phase'] ?? '') === 'calling') {
                    return true;
                }
            }
        }

        return false;
    }
}
