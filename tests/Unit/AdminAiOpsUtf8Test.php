<?php

namespace Tests\Unit;

use App\Casts\Utf8SafeArrayCast;
use App\Models\AdminAiOpsRun;
use App\Support\AdminAiOpsUtf8;
use Tests\TestCase;

class AdminAiOpsUtf8Test extends TestCase
{
    public function test_sanitize_string_removes_invalid_utf8_bytes(): void
    {
        $bad = "hello \xFF\xFE world";
        $clean = AdminAiOpsUtf8::sanitizeString($bad);

        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $encoded = json_encode(['preview' => $clean], JSON_THROW_ON_ERROR);
        $this->assertIsString($encoded);
    }

    public function test_utf8_safe_array_cast_encodes_snapshot_with_invalid_tool_output(): void
    {
        $invalid = "body \xC3\x28 preview";
        $cast = new Utf8SafeArrayCast;
        $model = new AdminAiOpsRun;

        $json = $cast->set($model, 'plan_stream_snapshot', [
            'assistant_timeline' => [
                'segments' => [
                    [
                        'kind' => 'tools',
                        'tools' => [
                            [
                                'toolCallId' => 'tc-1',
                                'name' => 'AdminOpsFetchUrlTool',
                                'phase' => 'done',
                                'rawOutput' => $invalid,
                                'resultPreview' => $invalid,
                            ],
                        ],
                    ],
                ],
            ],
        ], []);

        $this->assertIsString($json);
        $decoded = json_decode((string) $json, true);
        $this->assertIsArray($decoded);
        $this->assertTrue(mb_check_encoding((string) $json, 'UTF-8'));
    }
}
