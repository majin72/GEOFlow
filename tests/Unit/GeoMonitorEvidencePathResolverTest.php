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
     * sidecar 容器绝对路径应归一化并在 legacy 证据目录下解析。
     */
    public function test_resolves_sidecar_absolute_path_under_legacy_evidence_root(): void
    {
        $legacyRoot = sys_get_temp_dir().'/geo-evidence-legacy-'.uniqid();
        $relativeDir = 'yuanbao/yuanbao_account_01/20260604T120000Z/laravel-run-1';
        $fullDir = $legacyRoot.'/'.$relativeDir;
        mkdir($fullDir, 0777, true);
        $file = $fullDir.'/brand_generic.txt';
        file_put_contents($file, 'answer text');

        $configuredRoot = $legacyRoot.'/sidecar';
        mkdir($configuredRoot, 0777, true);

        $resolver = new GeoMonitorEvidencePathResolver;
        $storedPath = '/app/evidence/'.$relativeDir.'/brand_generic.txt';

        $this->assertSame(
            $relativeDir.'/brand_generic.txt',
            $resolver->normalizeStoredPath($storedPath),
        );

        $resolved = $resolver->resolveStoredPath($storedPath, $configuredRoot);

        $this->assertSame(realpath($file), $resolved);

        @unlink($file);
        @rmdir($fullDir);
        @rmdir($legacyRoot.'/yuanbao/yuanbao_account_01/20260604T120000Z');
        @rmdir($legacyRoot.'/yuanbao/yuanbao_account_01');
        @rmdir($legacyRoot.'/yuanbao');
        @rmdir($configuredRoot);
        @rmdir($legacyRoot);
    }

    /**
     * 配置的 sidecar 子目录不存在时，应回退到 legacy evidence 父目录。
     */
    public function test_resolves_when_configured_sidecar_root_missing(): void
    {
        $legacyRoot = sys_get_temp_dir().'/geo-evidence-missing-sidecar-'.uniqid();
        $relativeDir = 'yuanbao/yuanbao_account_01/20260604T120000Z/laravel-run-1';
        $fullDir = $legacyRoot.'/'.$relativeDir;
        mkdir($fullDir, 0777, true);
        $file = $fullDir.'/brand_generic.txt';
        file_put_contents($file, 'answer text');

        $missingSidecarRoot = $legacyRoot.'/sidecar';

        $resolver = new GeoMonitorEvidencePathResolver;
        $storedPath = 'yuanbao/yuanbao_account_01/20260604T120000Z/laravel-run-1/brand_generic.txt';

        $resolved = $resolver->resolveStoredPath($storedPath, $missingSidecarRoot);

        $this->assertSame(realpath($file), $resolved);

        @unlink($file);
        @rmdir($fullDir);
        @rmdir($legacyRoot.'/yuanbao/yuanbao_account_01/20260604T120000Z');
        @rmdir($legacyRoot.'/yuanbao/yuanbao_account_01');
        @rmdir($legacyRoot.'/yuanbao');
        @rmdir($legacyRoot);
    }

    /**
     * storage 绝对路径应归一化为相对路径。
     */
    public function test_normalizes_storage_absolute_path(): void
    {
        $resolver = new GeoMonitorEvidencePathResolver;
        $storedPath = '/var/www/html/storage/app/geo-monitor/evidence/yuanbao/yuanbao_account_01/run/prompt.txt';

        $this->assertSame(
            'yuanbao/yuanbao_account_01/run/prompt.txt',
            $resolver->normalizeStoredPath($storedPath),
        );
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
