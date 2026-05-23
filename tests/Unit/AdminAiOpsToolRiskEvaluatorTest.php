<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Admin\AiOps\AdminAiOpsToolRiskEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiOpsToolRiskEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_authors_create_mirror_tool_requires_risk_label(): void
    {
        config(['geoflow.admin_ai_ops_tool_approval.enabled' => true]);

        $eval = app(AdminAiOpsToolRiskEvaluator::class);
        $label = $eval->evaluate('AdminOpsAuthorsTool', [
            'op' => 'author_create',
            'payload' => ['name' => '爱旅行'],
        ]);

        $this->assertNotNull($label);
        $this->assertStringContainsString('author_create', (string) $label);
    }

    public function test_site_patch_basics_requires_risk_label(): void
    {
        config(['geoflow.admin_ai_ops_tool_approval.enabled' => true]);

        $eval = app(AdminAiOpsToolRiskEvaluator::class);
        $label = $eval->evaluate('AdminOpsSitePatchBasicsTool', [
            'patch' => ['site_name' => 'X'],
        ]);

        $this->assertNotNull($label);
        $this->assertStringContainsString('site_patch_basics', (string) $label);
    }

    public function test_when_disabled_returns_null_even_for_write(): void
    {
        config(['geoflow.admin_ai_ops_tool_approval.enabled' => false]);

        $eval = app(AdminAiOpsToolRiskEvaluator::class);
        $label = $eval->evaluate('AdminOpsAuthorsTool', [
            'op' => 'author_create',
            'payload' => ['name' => 'x'],
        ]);

        $this->assertNull($label);
    }
}
