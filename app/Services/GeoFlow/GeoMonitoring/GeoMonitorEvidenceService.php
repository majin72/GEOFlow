<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorObservation;
use App\Support\GeoFlow\GeoMonitoring\GeoMonitorEvidencePathResolver;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * 后台受控访问 sidecar 证据文件。
 */
class GeoMonitorEvidenceService
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
     * 返回证据文件的绝对路径（已通过根目录校验）。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     * @param  string  $type  证据类型
     */
    public function resolveReadablePath(GeoMonitorObservation $observation, string $type): ?string
    {
        return $this->pathResolver->resolveForObservation(
            $observation,
            $type,
            $this->config->evidenceRoot,
        );
    }

    /**
     * 构建证据下载/预览响应。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     * @param  string  $type  证据类型
     * @param  bool  $download  是否强制下载
     */
    public function buildFileResponse(
        GeoMonitorObservation $observation,
        string $type,
        bool $download = false,
    ): BinaryFileResponse {
        $absolutePath = $this->resolveReadablePath($observation, $type);

        if ($absolutePath === null) {
            throw new InvalidArgumentException(__('admin.geo_monitoring.message.evidence_not_found'));
        }

        $response = new BinaryFileResponse($absolutePath);
        $disposition = $download ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE;

        $response->setContentDisposition(
            $disposition,
            basename($absolutePath),
        );

        return $response;
    }

    /**
     * 列出观测记录可用的证据类型。
     *
     * @param  GeoMonitorObservation  $observation  观测记录
     * @return list<string>
     */
    public function availableTypes(GeoMonitorObservation $observation): array
    {
        $available = [];

        foreach (array_keys(GeoMonitorEvidencePathResolver::TYPE_FIELD_MAP) as $type) {
            if ($this->resolveReadablePath($observation, $type) !== null) {
                $available[] = $type;
            }
        }

        return $available;
    }
}
