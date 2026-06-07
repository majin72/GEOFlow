<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorPlatform;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorPrompt;
use App\Models\GeoMonitorRun;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorEvidenceRepairService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorEvidenceService;
use App\Support\GeoFlow\GeoMonitoring\GeoMonitorEvidencePathResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoMonitorEvidenceRepairTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 磁盘有文件但数据库无路径时，打开 run 页应自动修复并展示证据。
     */
    public function test_repair_service_discovers_files_on_disk(): void
    {
        $root = sys_get_temp_dir().'/geo-evidence-repair-'.uniqid();
        $observation = $this->seedObservationWithoutPaths();
        $relativeDir = 'yuanbao/yuanbao_account_01/20260604T120000Z/laravel-run-'.$observation->run_id;
        $fullDir = $root.'/'.$relativeDir;
        mkdir($fullDir, 0777, true);
        file_put_contents($fullDir.'/brand_generic.txt', 'answer on disk');
        file_put_contents($fullDir.'/brand_generic.png', 'png');

        config(['geoflow.geo_monitor.evidence_root' => $root]);

        $repaired = app(GeoMonitorEvidenceRepairService::class)->repairObservation($observation);

        $this->assertTrue($repaired);

        $observation->refresh();

        $this->assertSame(
            $relativeDir.'/brand_generic.txt',
            $observation->raw_text_path,
        );

        $types = app(GeoMonitorEvidenceService::class)->availableTypes($observation);

        $this->assertContains('txt', $types);
        $this->assertContains('png', $types);

        @unlink($fullDir.'/brand_generic.txt');
        @unlink($fullDir.'/brand_generic.png');
        @rmdir($fullDir);
        @rmdir($root.'/yuanbao/yuanbao_account_01/20260604T120000Z');
        @rmdir($root.'/yuanbao/yuanbao_account_01');
        @rmdir($root.'/yuanbao');
        @rmdir($root);
    }

    /**
     * 非标准宿主机绝对路径应能通过 marker 提取相对段。
     */
    public function test_normalizes_nonstandard_host_absolute_path(): void
    {
        $root = sys_get_temp_dir().'/geo-evidence-host-'.uniqid();
        $relativeDir = 'deepseek/deepseek_account_01/run-1';
        $fullDir = $root.'/'.$relativeDir;
        mkdir($fullDir, 0777, true);
        $file = $fullDir.'/prompt.txt';
        file_put_contents($file, 'ok');

        config(['geoflow.geo_monitor.evidence_root' => $root]);

        $resolver = new GeoMonitorEvidencePathResolver;
        $storedPath = '/www/wwwroot/geoflow/storage/app/geo-monitor/evidence/'.$relativeDir.'/prompt.txt';

        $this->assertSame($relativeDir.'/prompt.txt', $resolver->normalizeStoredPath($storedPath));
        $this->assertSame(realpath($file), $resolver->resolveStoredPath($storedPath, $root));

        @unlink($file);
        @rmdir($fullDir);
        @rmdir($root.'/deepseek/deepseek_account_01');
        @rmdir($root.'/deepseek');
        @rmdir($root);
    }

    private function seedObservationWithoutPaths(): GeoMonitorObservation
    {
        $platform = GeoMonitorPlatform::query()->where('code', 'yuanbao')->firstOrFail();

        $account = GeoMonitorAccount::query()->create([
            'platform_id' => $platform->id,
            'external_id' => 'yuanbao_account_01',
            'label' => '元宝测试',
            'status' => 'active',
            'profile_storage_path' => 'profiles/yuanbao_account_01',
        ]);

        $project = GeoMonitorProject::query()->create([
            'name' => 'Repair Test',
            'slug' => 'repair-test',
            'brand_name' => '测试',
            'primary_domain' => 'example.com',
            'status' => 'active',
        ]);

        $prompt = GeoMonitorPrompt::query()->create([
            'project_id' => $project->id,
            'code' => 'brand_generic',
            'prompt_text' => '北京租车',
            'intent' => 'generic',
            'is_enabled' => true,
        ]);

        $run = GeoMonitorRun::query()->create([
            'project_id' => $project->id,
            'status' => 'partial',
            'observation_count' => 1,
            'success_count' => 0,
            'started_at' => now(),
        ]);

        return GeoMonitorObservation::query()->create([
            'run_id' => $run->id,
            'project_id' => $project->id,
            'prompt_id' => $prompt->id,
            'platform_id' => $platform->id,
            'account_id' => $account->id,
            'prompt_text_snapshot' => $prompt->prompt_text,
            'status' => 'partial',
            'login_status' => 'logged_in',
            'answer_text' => '有回答',
            'probed_at' => now(),
        ]);
    }
}
