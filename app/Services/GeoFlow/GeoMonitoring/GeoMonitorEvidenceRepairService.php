<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorObservation;
use App\Support\GeoFlow\GeoMonitoring\GeoMonitorEvidencePathResolver;

/**
 * 当数据库缺少证据路径或路径无法解析时，从 storage 目录扫描并回写。
 */
class GeoMonitorEvidenceRepairService
{
    /**
     * @param  GeoMonitorConfig  $config  GEO 监测配置
     * @param  GeoMonitorEvidencePathResolver  $pathResolver  路径解析器
     */
    public function __construct(
        private readonly GeoMonitorConfig $config,
        private readonly GeoMonitorEvidencePathResolver $pathResolver,
    ) {}

    /**
     * 尝试从磁盘发现并回写观测证据路径。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     */
    public function repairObservation(GeoMonitorObservation $observation): bool
    {
        $observation->loadMissing(['platform', 'prompt', 'account']);

        $discovered = $this->discoverEvidencePaths($observation);

        if ($discovered === []) {
            return false;
        }

        $updates = [];

        foreach (GeoMonitorEvidencePathResolver::TYPE_FIELD_MAP as $type => $field) {
            if (! isset($discovered[$type])) {
                continue;
            }

            $updates[$field] = $discovered[$type];
        }

        if ($updates === []) {
            return false;
        }

        $observation->fill($updates);
        $observation->save();

        return true;
    }

    /**
     * 按 run 批次目录名在证据根下扫描 sidecar 写入的文件。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     * @return array<string, string> 类型 => 相对证据根路径
     */
    private function discoverEvidencePaths(GeoMonitorObservation $observation): array
    {
        $platformCode = (string) ($observation->platform?->code ?? '');
        $promptCode = (string) ($observation->prompt?->code ?? '');
        $accountExternalId = (string) ($observation->account?->external_id ?? '');

        if ($platformCode === '' || $promptCode === '') {
            return [];
        }

        $runSegment = $this->runEvidenceSegment($observation);
        $extensionByType = array_flip(GeoMonitorEvidencePathResolver::TYPE_FIELD_MAP);
        $discovered = [];

        foreach ($this->pathResolver->candidateEvidenceRoots($this->config->evidenceRoot) as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $rootBase = realpath($root) ?: rtrim(str_replace('\\', '/', $root), '/');
            $accountPattern = $accountExternalId !== '' ? $accountExternalId : '*';

            foreach (array_keys(GeoMonitorEvidencePathResolver::TYPE_FIELD_MAP) as $type) {
                if (isset($discovered[$type])) {
                    continue;
                }

                $pattern = $rootBase.'/'.$platformCode.'/'.$accountPattern.'/*/'.$runSegment.'/'.$promptCode.'.'.$type;

                foreach (glob($pattern) ?: [] as $absolutePath) {
                    if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                        continue;
                    }

                    $relative = $this->toRelativeEvidencePath($absolutePath, $rootBase);

                    if ($relative !== '') {
                        $discovered[$type] = $relative;
                    }
                }
            }

            if (count($discovered) === count(GeoMonitorEvidencePathResolver::TYPE_FIELD_MAP)) {
                break;
            }
        }

        return $discovered;
    }

    /**
     * 与 ProcessGeoMonitorProbeJob::evidenceSubdir 保持一致。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     */
    private function runEvidenceSegment(GeoMonitorObservation $observation): string
    {
        if ($observation->retried_from_observation_id !== null) {
            return 'laravel-run-'.$observation->run_id.'-retry-'.$observation->id;
        }

        return 'laravel-run-'.$observation->run_id;
    }

    /**
     * 将磁盘绝对路径转为相对证据根的路径。
     *
     * @param  string  $absolutePath  文件绝对路径
     * @param  string  $rootBase  证据根目录
     */
    private function toRelativeEvidencePath(string $absolutePath, string $rootBase): string
    {
        $rootBase = rtrim(str_replace('\\', '/', $rootBase), '/');
        $file = realpath($absolutePath) ?: str_replace('\\', '/', $absolutePath);
        $prefix = $rootBase.'/';

        if (str_starts_with($file, $prefix)) {
            return ltrim(substr($file, strlen($prefix)), '/');
        }

        return $this->pathResolver->normalizeStoredPath($absolutePath);
    }
}
