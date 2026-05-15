<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AiModel;
use App\Models\SiteSetting;
use App\Services\Admin\AdminOps\AdminOpsSiteWriteService;
use App\Support\AdminWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOpsSiteWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 校验合并写入可更新站点名称且保留未在 patch 中出现的必填字段。
     */
    public function test_patch_basics_updates_site_name_and_preserves_admin_path(): void
    {
        $base = AdminWeb::basePath();
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_name'],
            ['setting_value' => '旧名称']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'admin_base_path'],
            ['setting_value' => $base]
        );

        $service = app(AdminOpsSiteWriteService::class);
        $result = $service->patchBasics(['site_name' => '新名称单元测试']);

        $this->assertTrue($result['ok']);
        $this->assertSame('新名称单元测试', SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value'));
        $this->assertSame($base, SiteSetting::query()->where('setting_key', 'admin_base_path')->value('setting_value'));
    }

    /**
     * 校验主题列表非空结构（至少返回数组）。
     */
    public function test_list_installed_themes_returns_array(): void
    {
        $service = app(AdminOpsSiteWriteService::class);
        $themes = $service->listInstalledThemes();

        $this->assertIsArray($themes);
    }

    /**
     * 校验可将默认 embedding 写入 site_settings，且拒绝非 embedding 模型。
     */
    public function test_set_default_embedding_model_id_validates_model_type(): void
    {
        $embedding = AiModel::query()->create([
            'name' => 'Emb Test',
            'version' => '',
            'api_key' => '',
            'model_id' => 'text-embedding-test',
            'model_type' => 'embedding',
            'api_url' => '',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $chat = AiModel::query()->create([
            'name' => 'Chat Test',
            'version' => '',
            'api_key' => '',
            'model_id' => 'gpt-test',
            'model_type' => 'chat',
            'api_url' => '',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $service = app(AdminOpsSiteWriteService::class);

        $bad = $service->setDefaultEmbeddingModelId((int) $chat->id);
        $this->assertFalse((bool) ($bad['ok'] ?? true));

        $good = $service->setDefaultEmbeddingModelId((int) $embedding->id);
        $this->assertTrue((bool) ($good['ok'] ?? false));
        $this->assertSame((string) $embedding->id, SiteSetting::query()->where('setting_key', 'default_embedding_model_id')->value('setting_value'));

        $cleared = $service->setDefaultEmbeddingModelId(0);
        $this->assertTrue((bool) ($cleared['ok'] ?? false));
        $this->assertSame('0', SiteSetting::query()->where('setting_key', 'default_embedding_model_id')->value('setting_value'));
    }
}
