<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Admin\AdminOps\AdminOpsAdminActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOpsAdminActionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 校验统一后台动作服务可读仪表盘摘要。
     */
    public function test_read_dashboard_summary_returns_ok(): void
    {
        /** @var AdminOpsAdminActionService $svc */
        $svc = app(AdminOpsAdminActionService::class);
        $out = $svc->execute('read', 'dashboard_summary', []);

        $this->assertTrue((bool) ($out['ok'] ?? false));
        $this->assertArrayHasKey('stats', $out);
        $this->assertArrayHasKey('today_week', $out);
    }
}
