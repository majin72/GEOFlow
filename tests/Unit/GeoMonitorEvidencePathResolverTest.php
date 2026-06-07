<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GeoMonitorObservation;
use App\Support\GeoFlow\GeoMonitoring\GeoMonitorEvidencePathResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoMonitorEvidencePathResolverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 合法相对路径应解析到证据根目录下。
     */
    public function test_resolves_relative_path_under_evidence_root(): void
    {
        $root = sys_get_temp_dir().'/geo-evidence-'.uniqid();
        $subdir = $root.'/platform/account/ts';
        mkdir($subdir, 0777, true);
        $file = $subdir.'/answer.txt';
        file_put_contents($file, 'hello evidence');

        $resolver = new GeoMonitorEvidencePathResolver;
        $observation = new GeoMonitorObservation([
            'raw_text_path' => 'platform/account/ts/answer.txt',
        ]);

        $resolved = $resolver->resolveForObservation($observation, 'txt', $root);

        $this->assertSame(realpath($file), $resolved);

        @unlink($file);
        @rmdir($subdir);
        @rmdir($root.'/platform/account');
        @rmdir($root.'/platform');
        @rmdir($root);
    }

    /**
     * 目录穿越路径应被拒绝。
     */
    public function test_rejects_path_traversal(): void
    {
        $root = sys_get_temp_dir().'/geo-evidence-safe-'.uniqid();
        mkdir($root, 0777, true);

        $resolver = new GeoMonitorEvidencePathResolver;

        $this->assertNull($resolver->resolveStoredPath('../etc/passwd', $root));

        @rmdir($root);
    }

    /**
     * 根目录外的绝对路径应被拒绝。
     */
    public function test_rejects_absolute_path_outside_root(): void
    {
        $root = sys_get_temp_dir().'/geo-evidence-outside-'.uniqid();
        mkdir($root, 0777, true);

        $outside = sys_get_temp_dir().'/geo-evidence-outside-file-'.uniqid().'.txt';
        file_put_contents($outside, 'outside');

        $resolver = new GeoMonitorEvidencePathResolver;

        $this->assertNull($resolver->resolveStoredPath($outside, $root));

        @unlink($outside);
        @rmdir($root);
    }
}
