<?php

declare(strict_types=1);

namespace App\Support\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorObservation;

/**
 * 将观测记录中的证据路径解析为受控根目录下的绝对路径。
 */
final class GeoMonitorEvidencePathResolver
{
    /**
     * 证据类型与观测字段映射。
     *
     * @var array<string, string>
     */
    public const TYPE_FIELD_MAP = [
        'png' => 'screenshot_path',
        'html' => 'html_path',
        'txt' => 'raw_text_path',
        'md' => 'markdown_path',
    ];

    /**
     * 解析观测记录指定类型的证据文件绝对路径。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     * @param  string  $type  证据类型：png/html/txt/md
     * @param  string  $evidenceRoot  sidecar 证据根目录
     */
    public function resolveForObservation(
        GeoMonitorObservation $observation,
        string $type,
        string $evidenceRoot,
    ): ?string {
        $field = self::TYPE_FIELD_MAP[$type] ?? null;

        if ($field === null) {
            return null;
        }

        $storedPath = (string) ($observation->{$field} ?? '');

        if ($storedPath === '') {
            return null;
        }

        return $this->resolveStoredPath($storedPath, $evidenceRoot);
    }

    /**
     * 将数据库中的路径解析到证据根目录下，阻止目录穿越。
     *
     * @param  string  $storedPath  数据库保存的路径
     * @param  string  $evidenceRoot  sidecar 证据根目录
     */
    public function resolveStoredPath(string $storedPath, string $evidenceRoot): ?string
    {
        $root = realpath($evidenceRoot);

        if ($root === false || ! is_dir($root)) {
            return null;
        }

        $storedPath = $this->normalizeStoredPath($storedPath);

        if ($storedPath === '' || str_contains($storedPath, '..')) {
            return null;
        }

        foreach ($this->candidateAbsolutePaths($storedPath, $evidenceRoot) as $candidatePath) {
            $resolved = realpath($candidatePath);

            if ($resolved === false || ! is_file($resolved)) {
                continue;
            }

            $rootPrefix = rtrim($root, '/').'/';

            if (str_starts_with($resolved.'/', $rootPrefix) || str_starts_with($resolved.'/', rtrim(dirname($root), '/').'/')) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * 将 sidecar 返回的绝对路径转为相对证据根的路径，便于跨容器共享。
     *
     * @param  string  $storedPath  数据库或 sidecar 中的路径
     */
    public function normalizeStoredPath(string $storedPath): string
    {
        $storedPath = trim(str_replace('\\', '/', $storedPath));

        if ($storedPath === '') {
            return '';
        }

        if (! str_starts_with($storedPath, '/')) {
            return ltrim($storedPath, '/');
        }

        $prefixes = [
            '/app/evidence/sidecar/',
            '/app/evidence/',
            '/var/www/html/tools/geo-monitor-poc/evidence/sidecar/',
            '/var/www/html/tools/geo-monitor-poc/evidence/',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($storedPath, $prefix)) {
                return ltrim(substr($storedPath, strlen($prefix)), '/');
            }
        }

        return ltrim($storedPath, '/');
    }

    /**
     * 在证据根及其 legacy 父目录下尝试解析文件。
     *
     * @param  string  $relativePath  归一化后的相对路径
     * @param  string  $evidenceRoot  配置的证据根目录
     * @return list<string>
     */
    private function candidateAbsolutePaths(string $relativePath, string $evidenceRoot): array
    {
        $candidates = [
            rtrim($evidenceRoot, '/').'/'.ltrim($relativePath, '/'),
        ];

        $legacyRoot = dirname(rtrim($evidenceRoot, '/'));

        if ($legacyRoot !== rtrim($evidenceRoot, '/')) {
            $candidates[] = $legacyRoot.'/'.ltrim($relativePath, '/');
        }

        return $candidates;
    }
}
