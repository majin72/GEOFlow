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
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as AiStreamToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as AiStreamToolResult;

/**
 * 按 Laravel AI 流事件记录标准 transcript：user、assistant(tool_calls)、tool_result、assistant。
 */
final class AdminAiOpsLlmTranscriptRecorder
{
    /** @var array<int, Message> */
    private array $messages = [];

    /** @var list<ToolCall> */
    private array $openToolCalls = [];

    /** @var list<ToolResult> */
    private array $openToolResults = [];

    private string $assistantBuffer = '';

    private bool $assistantToolCallMessageFlushed = false;

    public function __construct(
        private readonly AdminAiOpsLlmTranscriptCodec $codec,
    ) {}

    /**
     * 开始一次 run transcript，写入本轮用户输入。
     */
    public function start(string $userMessage): void
    {
        $userMessage = trim($userMessage);
        if ($userMessage !== '') {
            $this->messages[] = new UserMessage($userMessage);
        }
    }

    /**
     * 消费 Laravel AI 流事件并维护标准消息序列。
     */
    public function record(object $event): void
    {
        if ($event instanceof TextDelta) {
            $this->flushOpenToolResults();
            $this->assistantBuffer .= $event->delta;

            return;
        }

        if ($event instanceof AiStreamToolCall) {
            $this->flushOpenToolResults();
            $this->openToolCalls[] = $event->toolCall;

            return;
        }

        if ($event instanceof AiStreamToolResult) {
            $this->flushAssistantToolCallMessage();
            $this->openToolResults[] = $event->toolResult;
        }
    }

    /**
     * 完成 transcript 并返回 Laravel AI 原生消息列表。
     *
     * @return array<int, Message>
     */
    public function finish(): array
    {
        $this->flushOpenToolResults();
        $this->flushAssistantTextMessage();

        return $this->messages;
    }

    /**
     * 完成 transcript 并返回可写入 JSON 的数组。
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->codec->toArray($this->finish());
    }

    /**
     * 将当前 assistant tool_calls flush 为标准 AssistantMessage。
     */
    private function flushAssistantToolCallMessage(): void
    {
        if ($this->assistantToolCallMessageFlushed || $this->openToolCalls === []) {
            return;
        }

        $this->messages[] = new AssistantMessage(
            $this->assistantBuffer,
            new Collection($this->openToolCalls),
        );
        $this->assistantBuffer = '';
        $this->assistantToolCallMessageFlushed = true;
    }

    /**
     * 将当前 tool results flush 为标准 ToolResultMessage。
     */
    private function flushOpenToolResults(): void
    {
        if ($this->openToolResults === []) {
            return;
        }

        $this->messages[] = new ToolResultMessage(new Collection($this->openToolResults));
        $this->openToolResults = [];
        $this->openToolCalls = [];
        $this->assistantToolCallMessageFlushed = false;
    }

    /**
     * 将当前普通 assistant 文本 flush 为 AssistantMessage。
     */
    private function flushAssistantTextMessage(): void
    {
        $text = trim($this->assistantBuffer);
        if ($text === '') {
            return;
        }

        $this->messages[] = new AssistantMessage($this->assistantBuffer);
        $this->assistantBuffer = '';
    }
}
