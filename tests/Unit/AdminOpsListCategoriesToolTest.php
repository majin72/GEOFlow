<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\Tools\AdminOpsListCategoriesTool;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AdminOpsListCategoriesToolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 校验栏目只读工具返回与数据库一致的名称与总数。
     */
    public function test_lists_categories_sorted_by_sort_order_then_name(): void
    {
        Category::query()->create([
            'name' => 'Beta',
            'slug' => 'beta',
            'description' => '',
            'sort_order' => 10,
        ]);
        Category::query()->create([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'description' => 'first',
            'sort_order' => 5,
        ]);

        $tool = app(AdminOpsListCategoriesTool::class);
        $out = (string) $tool->handle(new Request([]));
        $decoded = json_decode($out, true);

        $this->assertIsArray($decoded);
        $this->assertTrue((bool) ($decoded['ok'] ?? false));
        $this->assertSame(2, (int) ($decoded['total'] ?? 0));
        $names = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $decoded['categories'] ?? []);
        $this->assertSame(['Alpha', 'Beta'], $names);
    }
}
