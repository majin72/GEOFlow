<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Ai\Tools\Request;

/**
 * 从 AI 工具 Request 提取标量字段，供领域 Mirror 工具组装 payload。
 */
final class AdminOpsMirrorRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function data(Request $request): array
    {
        return $request->toArray();
    }

    public static function action(Request $request): string
    {
        return strtolower(trim((string) (self::data($request)['action'] ?? '')));
    }

    public static function string(Request $request, string $key, string $default = ''): string
    {
        $v = self::data($request)[$key] ?? $default;

        return trim((string) $v);
    }

    public static function int(Request $request, string $key, int $default = 0): int
    {
        $v = self::data($request)[$key] ?? $default;

        return (int) $v;
    }

    public static function optionalInt(Request $request, string $key): ?int
    {
        $data = self::data($request);
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        return (int) $data[$key];
    }

    /**
     * @return list<int>
     */
    public static function intList(Request $request, string $key): array
    {
        $raw = self::data($request)[$key] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($v): int => (int) $v, $raw), static fn (int $id): bool => $id > 0));
    }

    /**
     * 解析 filters_json 为数组（文章列表等）。
     *
     * @return array<string, mixed>
     */
    public static function filtersJson(Request $request): array
    {
        $raw = trim(self::string($request, 'filters_json', '{}'));
        if ($raw === '' || $raw === '{}') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 解析 payload_json 为数组（嵌套写入体）。
     *
     * @return array<string, mixed>
     */
    public static function payloadJson(Request $request): array
    {
        $raw = trim(self::string($request, 'payload_json', '{}'));
        if ($raw === '' || $raw === '{}') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
