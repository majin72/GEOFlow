<?php

namespace Tests\Unit;

use App\Ai\Tools\AdminOpsFetchUrlTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AdminOpsFetchUrlToolTest extends TestCase
{
    public function test_returns_json_from_http_fetch(): void
    {
        Http::fake([
            'https://example.com/api' => Http::response(['hello' => 'world'], 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $tool = app(AdminOpsFetchUrlTool::class);
        $out = (string) $tool->handle(new Request(['url' => 'https://example.com/api']));
        $decoded = json_decode($out, true);

        $this->assertIsArray($decoded);
        $this->assertTrue((bool) ($decoded['ok'] ?? false));
        $this->assertSame(200, $decoded['status'] ?? null);
        $this->assertStringContainsString('world', (string) ($decoded['body_preview'] ?? ''));
    }
}
