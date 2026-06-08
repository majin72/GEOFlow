<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorProject;
use App\Models\GeoMonitorRun;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 导出 GEO 监测批次/项目报表为 CSV（Excel 可直接打开）。
 */
class GeoMonitorReportExportService
{
    /**
     * @param  GeoMonitorAttributionReportService  $reportService  报表服务
     */
    public function __construct(
        private readonly GeoMonitorAttributionReportService $reportService,
    ) {}

    /**
     * 导出单批次观测明细 CSV。
     *
     * @param  GeoMonitorRun  $run  批次运行
     */
    public function streamRunCsv(GeoMonitorRun $run): StreamedResponse
    {
        $run->loadMissing([
            'project',
            'observations.platform',
            'observations.prompt',
            'observations.citations',
            'observations.mentions',
        ]);

        $filename = sprintf('geo-monitor-run-%d-%s.csv', $run->id, now()->format('Ymd-His'));

        return $this->streamCsv($filename, $this->buildRowsForRun($run));
    }

    /**
     * 导出项目最近一次已完成批次的观测明细 CSV。
     *
     * @param  GeoMonitorProject  $project  监测项目
     */
    public function streamProjectLatestCsv(GeoMonitorProject $project): StreamedResponse
    {
        $run = GeoMonitorRun::query()
            ->where('project_id', $project->id)
            ->whereIn('status', ['succeeded', 'partial', 'failed'])
            ->orderByDesc('id')
            ->first();

        if ($run === null) {
            abort(404, __('admin.geo_monitoring.export.no_run'));
        }

        return $this->streamRunCsv($run);
    }

    /**
     * @param  GeoMonitorRun  $run  批次运行
     * @return list<list<string>>
     */
    public function buildRowsForRun(GeoMonitorRun $run): array
    {
        $report = $this->reportService->buildRunReport($run);
        $runMetrics = is_array($report) ? $report : [];

        $header = [
            'project_name',
            'project_slug',
            'run_id',
            'run_status',
            'run_started_at',
            'run_finished_at',
            'geo_score',
            'platform',
            'prompt_code',
            'prompt_text',
            'observation_id',
            'status',
            'login_status',
            'citation_count',
            'own_citation_count',
            'competitor_citation_count',
            'brand_mention_count',
            'competitor_mention_count',
            'duration_ms',
            'error_message',
            'evidence_png_url',
            'evidence_html_url',
            'evidence_txt_url',
        ];

        $rows = [$header];

        foreach ($run->observations as $observation) {
            $ownCitations = $observation->citations->where('is_own_domain', true)->count();
            $competitorCitations = $observation->citations->where('is_competitor_domain', true)->count();
            $brandMentions = $observation->mentions->where('entity_type', 'brand')->count();
            $competitorMentions = $observation->mentions->where('entity_type', 'competitor')->count();

            $rows[] = [
                (string) $run->project->name,
                (string) $run->project->slug,
                (string) $run->id,
                (string) $run->status,
                $run->started_at?->format('Y-m-d H:i:s') ?? '',
                $run->finished_at?->format('Y-m-d H:i:s') ?? '',
                (string) ($runMetrics['geo_score'] ?? ''),
                (string) ($observation->platform?->code ?? ''),
                (string) ($observation->prompt?->code ?? ''),
                (string) $observation->prompt_text_snapshot,
                (string) $observation->id,
                (string) $observation->status,
                (string) $observation->login_status,
                (string) $observation->citations->count(),
                (string) $ownCitations,
                (string) $competitorCitations,
                (string) $brandMentions,
                (string) $competitorMentions,
                (string) ($observation->duration_ms ?? ''),
                (string) ($observation->error_message ?? ''),
                $this->evidenceUrl($run, $observation, 'png'),
                $this->evidenceUrl($run, $observation, 'html'),
                $this->evidenceUrl($run, $observation, 'txt'),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<list<string>>  $rows  CSV 行
     */
    private function streamCsv(string $filename, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * 生成后台证据下载链接（若证据存在）。
     *
     * @param  GeoMonitorRun  $run  批次
     * @param  GeoMonitorObservation  $observation  观测
     * @param  string  $type  证据类型 png/html/txt
     */
    private function evidenceUrl(GeoMonitorRun $run, GeoMonitorObservation $observation, string $type): string
    {
        $pathField = match ($type) {
            'png' => $observation->screenshot_path,
            'html' => $observation->html_path,
            'txt' => $observation->raw_text_path,
            default => null,
        };

        if (! is_string($pathField) || trim($pathField) === '') {
            return '';
        }

        return route('admin.geo-monitoring.observations.evidence.download', [
            'runId' => $run->id,
            'observationId' => $observation->id,
            'type' => $type,
        ]);
    }
}
