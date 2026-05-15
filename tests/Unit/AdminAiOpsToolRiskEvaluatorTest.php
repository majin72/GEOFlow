<?php

namespace Tests\Unit;

use App\Services\Admin\AiOps\AdminAiOpsToolRiskEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiOpsToolRiskEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_admin_action_requires_risk_label(): void
    {
        config(['geoflow.admin_ai_ops_tool_approval.enabled' => true]);

        $eval = new AdminAiOpsToolRiskEvaluator;
        $label = $eval->evaluate('AdminOpsAdminActionTool', [
            'kind' => 'write',
            'op' => 'category_create',
            'payload' => [],
        ]);

        $this->assertNotNull($label);
        $this->assertStringContainsString('category_create', (string) $label);
    }

    public function test_site_patch_basics_requires_risk_label(): void
    {
        config(['geoflow.admin_ai_ops_tool_approval.enabled' => true]);

        $eval = new AdminAiOpsToolRiskEvaluator;
        $label = $eval->evaluate('AdminOpsSitePatchBasicsTool', [
            'patch' => ['site_name' => 'X'],
        ]);

        $this->assertNotNull($label);
        $this->assertStringContainsString('site_patch_basics', (string) $label);
        $this->assertStringContainsString('site_name', (string) $label);
    }

    public function test_read_admin_action_does_not_require_approval(): void
    {
        config(['geoflow.admin_ai_ops_tool_approval.enabled' => true]);

        $eval = new AdminAiOpsToolRiskEvaluator;
        $label = $eval->evaluate('AdminOpsAdminActionTool', [
            'kind' => 'read',
            'op' => 'dashboard_summary',
            'payload' => [],
        ]);

        $this->assertNull($label);
    }

    public function test_when_disabled_returns_null_even_for_write(): void
    {
        config(['geoflow.admin_ai_ops_tool_approval.enabled' => false]);

        $eval = new AdminAiOpsToolRiskEvaluator;
        $label = $eval->evaluate('AdminOpsAdminActionTool', [
            'kind' => 'write',
            'op' => 'x',
            'payload' => [],
        ]);

        $this->assertNull($label);
    }
}
