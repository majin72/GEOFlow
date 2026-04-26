<?php

namespace Tests\Unit;

use App\Support\GeoFlow\ImageUrlNormalizer;
use Tests\TestCase;

class ImageUrlNormalizerTest extends TestCase
{
    public function test_it_prefixes_public_image_paths_with_configured_base_path(): void
    {
        config(['app.url' => 'https://example.com/wiki']);

        $this->assertSame('/wiki/storage/uploads/images/example.png', ImageUrlNormalizer::toPublicUrl('storage/uploads/images/example.png'));
        $this->assertSame('/wiki/storage/uploads/images/example.png', ImageUrlNormalizer::toPublicUrl('uploads/images/example.png'));
        $this->assertSame('/wiki/storage/uploads/images/example.png', ImageUrlNormalizer::toPublicUrl('storage/app/public/uploads/images/example.png'));
        $this->assertSame('/wiki/storage/uploads/images/example.png', ImageUrlNormalizer::toPublicUrl('public/storage/uploads/images/example.png'));
    }

    public function test_it_keeps_absolute_and_inline_image_urls_unchanged(): void
    {
        config(['app.url' => 'https://example.com/wiki']);

        $this->assertSame('https://cdn.example.com/image.png', ImageUrlNormalizer::toPublicUrl('https://cdn.example.com/image.png'));
        $this->assertSame('//cdn.example.com/image.png', ImageUrlNormalizer::toPublicUrl('//cdn.example.com/image.png'));
        $this->assertSame('data:image/png;base64,abc', ImageUrlNormalizer::toPublicUrl('data:image/png;base64,abc'));
    }
}
