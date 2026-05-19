<?php

declare(strict_types=1);

namespace App\Support;

/**
 * AI 运维：清洗非法 UTF-8，避免 Eloquent JSON 列与 json_encode 写入失败。
 */
final class AdminAiOpsUtf8
{
    /**
     * 递归清洗数组中的字符串键值（非法 UTF-8 字节将被剔除）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitizeRecursive(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $safeKey = is_string($key) ? self::sanitizeString($key) : $key;
            if (is_string($value)) {
                $out[$safeKey] = self::sanitizeString($value);
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$safeKey] = self::sanitizeRecursive($value);
            } else {
                $out[$safeKey] = $value;
            }
        }

        return $out;
    }

    /**
     * 将字符串规范为合法 UTF-8（剔除无效字节序列）。
     */
    public static function sanitizeString(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $clean = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if ($clean !== false) {
            return $clean;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return is_string($converted) ? $converted : '';
    }
}
