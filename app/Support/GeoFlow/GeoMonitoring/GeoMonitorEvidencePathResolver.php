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
     * 路径中标识证据根的可识别片段（用于从任意绝对路径提取相对段）。
     *
     * @var list<string>
     */
    private const EVIDENCE_PATH_MARKERS = [
        'storage/app/geo-monitor/evidence/',
        'geo-monitor/evidence/',
        'evidence/sidecar/',
        'evidence/',
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
     * @param  string  $evidenceRoot  配置的证据根目录
     */
    public function resolveStoredPath(string $storedPath, string $evidenceRoot): ?string
    {
        $original = trim(str_replace('\\', '/', $storedPath));
        $relativePath = $this->normalizeStoredPath($storedPath);

        if ($relativePath !== '' && ! str_contains($relativePath, '..')) {
            foreach ($this->candidateEvidenceRoots($evidenceRoot) as $root) {
                $absolutePath = $this->joinUnderRoot($root, $relativePath);

                if ($absolutePath !== null) {
                    return $absolutePath;
                }
            }
        }

        return $this->resolveAbsoluteIfAllowed($original, $evidenceRoot);
    }

    /**
     * 将 sidecar / 历史记录中的路径统一为相对证据根的路径。
     *
     * @param  string  $storedPath  数据库或 sidecar 中的路径
     */
    public function normalizeStoredPath(string $storedPath): string
    {
        $storedPath = trim(str_replace('\\', '/', $storedPath));

        if ($storedPath === '') {
            return '';
        }

        $fromMarker = $this->extractRelativeFromMarkers($storedPath);

        if ($fromMarker !== '') {
            return $fromMarker;
        }

        if (! str_starts_with($storedPath, '/')) {
            return ltrim($storedPath, '/');
        }

        $prefixes = [
            '/var/www/html/storage/app/geo-monitor/evidence/',
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
     * 列出可能存放证据的根目录。
     *
     * @param  string  $evidenceRoot  配置的证据根目录
     * @return list<string>
     */
    public function candidateEvidenceRoots(string $evidenceRoot): array
    {
        $trimmed = rtrim(str_replace('\\', '/', $evidenceRoot), '/');
        $roots = [$trimmed];

        if (str_ends_with($trimmed, '/sidecar')) {
            $roots[] = dirname($trimmed);
        }

        $pocRoot = rtrim((string) config('geoflow.geo_monitor.novnc.poc_root', ''), '/');

        if ($pocRoot !== '') {
            $roots[] = $pocRoot.'/evidence/sidecar';
            $roots[] = $pocRoot.'/evidence';
        }

        $storageEvidence = storage_path('app/geo-monitor/evidence');

        if ($storageEvidence !== '') {
            $roots[] = $storageEvidence;
        }

        $unique = [];

        foreach ($roots as $root) {
            $root = rtrim(str_replace('\\', '/', $root), '/');

            if ($root === '' || $root === '.' || in_array($root, $unique, true)) {
                continue;
            }

            $unique[] = $root;
        }

        return $unique;
    }

    /**
     * 从路径中提取 geo-monitor/evidence 之后的相对段。
     *
     * @param  string  $storedPath  原始路径
     */
    private function extractRelativeFromMarkers(string $storedPath): string
    {
        foreach (self::EVIDENCE_PATH_MARKERS as $marker) {
            $pos = stripos($storedPath, $marker);

            if ($pos === false) {
                continue;
            }

            return ltrim(substr($storedPath, $pos + strlen($marker)), '/');
        }

        return '';
    }

    /**
     * 若数据库保存的是仍存在的绝对路径，直接校验是否在允许根目录下。
     *
     * @param  string  $path  原始绝对路径
     * @param  string  $evidenceRoot  配置的证据根
     */
    private function resolveAbsoluteIfAllowed(string $path, string $evidenceRoot): ?string
    {
        if (! str_starts_with($path, '/') || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $resolvedFile = realpath($path) ?: $path;

        foreach ($this->candidateEvidenceRoots($evidenceRoot) as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $rootBase = realpath($root) ?: rtrim(str_replace('\\', '/', $root), '/');

            if ($this->isPathUnderRoot($resolvedFile, $rootBase)) {
                return $resolvedFile;
            }
        }

        return null;
    }

    /**
     * 在指定根目录下拼接相对路径并校验可读性。
     *
     * @param  string  $root  证据根目录
     * @param  string  $relativePath  归一化后的相对路径
     */
    private function joinUnderRoot(string $root, string $relativePath): ?string
    {
        if (! is_dir($root)) {
            return null;
        }

        $rootBase = realpath($root) ?: rtrim(str_replace('\\', '/', $root), '/');
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $absolutePath = $rootBase.'/'.$relativePath;

        if (! $this->isPathUnderRoot($absolutePath, $rootBase)) {
            return null;
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        return $absolutePath;
    }

    /**
     * 判断绝对路径是否在根目录之下（防止目录穿越）。
     *
     * @param  string  $absolutePath  待检查绝对路径
     * @param  string  $rootBase  证据根目录
     */
    private function isPathUnderRoot(string $absolutePath, string $rootBase): bool
    {
        $absolutePath = str_replace('\\', '/', $absolutePath);
        $rootBase = rtrim(str_replace('\\', '/', $rootBase), '/');

        return $absolutePath === $rootBase || str_starts_with($absolutePath, $rootBase.'/');
    }
}
