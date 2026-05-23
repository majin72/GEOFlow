<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\Tools\AdminOpsAuthorsTool;
use App\Models\Author;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AdminOpsAuthorsToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_author_with_name_field(): void
    {
        config(['geoflow.admin_ai_ops_tool_approval.enabled' => false]);

        $tool = app(AdminOpsAuthorsTool::class);
        $out = (string) $tool->handle(new Request([
            'action' => 'create',
            'name' => '爱旅行',
        ]));
        $decoded = json_decode($out, true);

        $this->assertTrue((bool) ($decoded['ok'] ?? false));
        $this->assertSame('爱旅行', Author::query()->find($decoded['author_id'])->name ?? null);
    }

    public function test_list_authors(): void
    {
        Author::query()->create(['name' => 'A', 'email' => '', 'bio' => '', 'website' => '', 'social_links' => '']);

        $tool = app(AdminOpsAuthorsTool::class);
        $out = (string) $tool->handle(new Request(['action' => 'list']));
        $decoded = json_decode($out, true);

        $this->assertTrue((bool) ($decoded['ok'] ?? false));
        $this->assertGreaterThanOrEqual(1, count($decoded['data']['items'] ?? []));
    }
}
