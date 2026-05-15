<?php

namespace App\Events\Admin;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AI 运维执行状态实时推送事件。
 */
class AdminAiOpsRunUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public int $runId,
        public array $payload,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.ai-ops.'.$this->runId);
    }

    public function broadcastAs(): string
    {
        return 'ai-ops.run.updated';
    }

    /**
     * @return array<string,mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
