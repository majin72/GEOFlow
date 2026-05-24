<?php

declare(strict_types=1);

namespace App\Services\Admin\AiOps;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * 将 Laravel AI 原生消息对象与可落库数组互转，保留 tool_call_id 与 tool result 的标准配对。
 */
final class AdminAiOpsLlmTranscriptCodec
{
    /**
     * @param  array<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    public function toArray(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $out[] = [
                    'type' => 'user',
                    'content' => (string) ($message->content ?? ''),
                ];

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $out[] = [
                    'type' => 'assistant',
                    'content' => (string) ($message->content ?? ''),
                    'tool_calls' => $message->toolCalls
                        ->map(fn (ToolCall $toolCall): array => $toolCall->toArray())
                        ->values()
                        ->all(),
                ];

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $out[] = [
                    'type' => 'tool_result',
                    'tool_results' => $message->toolResults
                        ->map(fn (ToolResult $toolResult): array => $toolResult->toArray())
                        ->values()
                        ->all(),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, Message>
     */
    public function fromArray(array $rows): array
    {
        $messages = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = (string) ($row['type'] ?? $row['role'] ?? '');
            if ($type === 'user') {
                $messages[] = new UserMessage((string) ($row['content'] ?? ''));

                continue;
            }

            if ($type === 'assistant') {
                $messages[] = new AssistantMessage(
                    (string) ($row['content'] ?? ''),
                    new Collection($this->toolCallsFromArray(is_array($row['tool_calls'] ?? null) ? $row['tool_calls'] : [])),
                );

                continue;
            }

            if ($type === 'tool_result') {
                $messages[] = new ToolResultMessage(
                    new Collection($this->toolResultsFromArray(is_array($row['tool_results'] ?? null) ? $row['tool_results'] : [])),
                );
            }
        }

        return $messages;
    }

    /**
     * 估算消息字符数，用于按 run 截断历史上下文。
     *
     * @param  array<int, Message>  $messages
     */
    public function estimatedChars(array $messages): int
    {
        $total = 0;
        foreach ($this->toArray($messages) as $row) {
            $total += mb_strlen(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
        }

        return $total;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<ToolCall>
     */
    private function toolCallsFromArray(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = new ToolCall(
                (string) ($row['id'] ?? ''),
                (string) ($row['name'] ?? ''),
                is_array($row['arguments'] ?? null) ? $row['arguments'] : [],
                isset($row['result_id']) ? (string) $row['result_id'] : null,
                isset($row['reasoning_id']) ? (string) $row['reasoning_id'] : null,
                is_array($row['reasoning_summary'] ?? null) ? $row['reasoning_summary'] : null,
            );
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<ToolResult>
     */
    private function toolResultsFromArray(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = new ToolResult(
                (string) ($row['id'] ?? ''),
                (string) ($row['name'] ?? ''),
                is_array($row['arguments'] ?? null) ? $row['arguments'] : [],
                $row['result'] ?? '',
                isset($row['result_id']) ? (string) $row['result_id'] : null,
            );
        }

        return $out;
    }
}
