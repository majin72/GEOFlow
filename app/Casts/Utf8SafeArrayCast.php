<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\AdminAiOpsUtf8;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * 以 JSON 存库的数组字段：写入前递归清洗非法 UTF-8，避免 json_encode 抛出 Malformed UTF-8。
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null>
 */
final class Utf8SafeArrayCast implements CastsAttributes
{
    /**
     * {@inheritdoc}
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * {@inheritdoc}
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $sanitized = AdminAiOpsUtf8::sanitizeRecursive($value);
        $encoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded !== false ? $encoded : '{}';
    }
}
