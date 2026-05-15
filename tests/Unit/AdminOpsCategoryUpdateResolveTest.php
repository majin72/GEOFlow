<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Admin\AdminOps\AdminOpsAdminActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 校验 AdminOps category_update 对 id / payload.id / 仅 name 等参数的解析，避免误报「栏目不存在」。
 */
class AdminOpsCategoryUpdateResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_update_accepts_top_level_id_alias(): void
    {
        $row = Category::query()->create([
            'name' => '床车自驾',
            'slug' => 'chuangche-zijia',
            'description' => '',
            'sort_order' => 50,
        ]);

        $svc = app(AdminOpsAdminActionService::class);
        $out = $svc->execute('write', 'category_update', [
            'id' => $row->id,
            'name' => '床车自驾',
            'sort_order' => 0,
        ]);

        $this->assertTrue((bool) ($out['ok'] ?? false));
        $row->refresh();
        $this->assertSame(0, (int) $row->sort_order);
    }

    public function test_category_update_accepts_id_inside_payload(): void
    {
        $row = Category::query()->create([
            'name' => '床车自驾',
            'slug' => 'chuangche-zijia',
            'description' => '',
            'sort_order' => 10,
        ]);

        $svc = app(AdminOpsAdminActionService::class);
        $out = $svc->execute('write', 'category_update', [
            'payload' => [
                'id' => $row->id,
                'name' => '床车自驾',
                'sort_order' => 1,
            ],
        ]);

        $this->assertTrue((bool) ($out['ok'] ?? false));
        $row->refresh();
        $this->assertSame(1, (int) $row->sort_order);
    }

    public function test_category_update_resolves_by_name_when_no_id(): void
    {
        $row = Category::query()->create([
            'name' => '床车自驾',
            'slug' => 'chuangche-zijia',
            'description' => '',
            'sort_order' => 99,
        ]);

        $svc = app(AdminOpsAdminActionService::class);
        $out = $svc->execute('write', 'category_update', [
            'name' => '床车自驾',
            'sort_order' => 0,
        ]);

        $this->assertTrue((bool) ($out['ok'] ?? false));
        $row->refresh();
        $this->assertSame(0, (int) $row->sort_order);
    }
}
