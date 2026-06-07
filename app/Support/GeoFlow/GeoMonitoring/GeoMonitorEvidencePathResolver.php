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

        $storedPath = trim(str_replace('\\', '/', $storedPath));

        if ($storedPath === '' || str_contains($storedPath, '..')) {
            return null;
        }

        $candidatePath = $storedPath;

        if (! str_starts_with($storedPath, '/')) {
            $candidatePath = $root.'/'.ltrim($storedPath, '/');
        }

        $resolved = realpath($candidatePath);

        if ($resolved === false || ! is_file($resolved)) {
            return null;
        }

        $rootPrefix = rtrim($root, '/').'/';

        if (! str_starts_with($resolved.'/', $rootPrefix) && $resolved !== rtrim($root, '/')) {
            return null;
        }

        return $resolved;
    }
}
