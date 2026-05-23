<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Author;
use App\Services\Admin\AiOps\AdminAiOpsApprovedToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiOpsApprovedToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_executes_mirror_authors_create(): void
    {
        $executor = app(AdminAiOpsApprovedToolExecutor::class);
        $json = $executor->execute('AdminOpsAuthorsTool', [
            'op' => 'author_create',
            'payload' => ['name' => '审批回放作者'],
        ]);
        $decoded = json_decode($json, true);

        $this->assertTrue((bool) ($decoded['ok'] ?? false));
        $this->assertSame('审批回放作者', Author::query()->find($decoded['author_id'])->name ?? null);
    }
}
