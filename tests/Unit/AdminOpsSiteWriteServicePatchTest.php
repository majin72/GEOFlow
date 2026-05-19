<?php

namespace Tests\Unit;

use App\Services\Admin\AdminOps\AdminOpsSiteWriteService;
use Tests\TestCase;

class AdminOpsSiteWriteServicePatchTest extends TestCase
{
    public function test_normalize_basics_patch_keys_maps_site_title_to_site_name(): void
    {
        $service = app(AdminOpsSiteWriteService::class);

        $normalized = $service->normalizeBasicsPatchKeys([
            'site_title' => '床车旅行记',
            'site_subtitle' => '副标题',
        ]);

        $this->assertSame('床车旅行记', $normalized['site_name']);
        $this->assertArrayNotHasKey('site_title', $normalized);
        $this->assertSame('副标题', $normalized['site_subtitle']);
    }
}
