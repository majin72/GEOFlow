<?php

declare(strict_types=1);

namespace App\Support\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorProject;
use Illuminate\Support\Str;

/**
 * 解析后台表单中的多行/逗号分隔列表字段。
 */
final class GeoMonitorListParser
{
    /**
     * 将文本解析为去重后的字符串列表。
     *
     * @param  string|null  $value  多行或逗号分隔文本
     * @return list<string>
     */
    public static function parse(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,]+/', $value) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            $item = trim((string) $part);

            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * 将列表格式化为 textarea 展示文本。
     *
     * @param  array<int, mixed>|null  $items  列表项
     */
    public static function formatForTextarea(?array $items): string
    {
        if (! is_array($items) || $items === []) {
            return '';
        }

        $lines = [];

        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $lines[] = trim($item);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 根据名称生成唯一 slug。
     *
     * @param  string  $name  项目名称
     * @param  int  $ignoreProjectId  更新时排除的项目 ID
     */
    public static function uniqueProjectSlug(string $name, int $ignoreProjectId = 0): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'geo-monitor-project';
        }

        $slug = $base;
        $suffix = 1;

        while (GeoMonitorProject::query()
            ->when($ignoreProjectId > 0, fn ($q) => $q->where('id', '!=', $ignoreProjectId))
            ->where('slug', $slug)
            ->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
