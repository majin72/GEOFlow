<?php

namespace App\Support\GeoFlow;

/**
 * 统一图片素材的公开访问路径，兼容历史数据中的 uploads/... 路径。
 */
final class ImageUrlNormalizer
{
    /**
     * 将图片路径归一化为可公开访问的 URL。
     */
    public static function toPublicUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            return '';
        }

        if (
            str_starts_with($normalized, 'http://')
            || str_starts_with($normalized, 'https://')
            || str_starts_with($normalized, '//')
            || str_starts_with($normalized, 'data:')
        ) {
            return $normalized;
        }

        $withoutLeadingSlash = ltrim($normalized, '/');

        if (str_starts_with($withoutLeadingSlash, 'storage/app/public/')) {
            $withoutLeadingSlash = substr($withoutLeadingSlash, strlen('storage/app/public/'));
        }

        if (str_starts_with($withoutLeadingSlash, 'public/storage/')) {
            $withoutLeadingSlash = substr($withoutLeadingSlash, strlen('public/storage/'));
        }

        if (str_starts_with($withoutLeadingSlash, 'storage/')) {
            return self::toConfiguredPublicPath($withoutLeadingSlash);
        }

        if (str_starts_with($withoutLeadingSlash, 'uploads/')) {
            return self::toConfiguredPublicPath('storage/'.$withoutLeadingSlash);
        }

        return self::toConfiguredPublicPath($withoutLeadingSlash);
    }

    /**
     * 清理图片 alt 文案，避免把文件名直接展示给读者。
     */
    public static function readableAlt(string $alt): string
    {
        $alt = trim($alt);

        return preg_match('/^[^\/\\\\]+\.(?:png|jpe?g|gif|webp|svg|avif)$/iu', $alt) === 1 ? '' : $alt;
    }

    /**
     * 根据 APP_URL 的路径部分生成公开路径，避免将域名写入文章内容。
     */
    private static function toConfiguredPublicPath(string $path): string
    {
        $publicPath = ltrim($path, '/');
        $configuredPath = parse_url((string) config('app.url', ''), PHP_URL_PATH);
        $basePath = trim(is_string($configuredPath) ? $configuredPath : '', '/');

        return '/'.($basePath !== '' ? $basePath.'/' : '').$publicPath;
    }
}
