<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoMonitoring;

use App\Models\GeoMonitorAccount;
use App\Models\GeoMonitorAlert;
use App\Models\GeoMonitorObservation;
use App\Models\GeoMonitorRun;
use App\Models\GeoMonitorSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * 组装 GEO 监测产品化运行面板数据。
 */
class GeoMonitorDashboardService
{
    /**
     * @param  GeoMonitorRunService  $runService  批次与 sidecar 摘要
     * @param  GeoMonitorAlertService  $alertService  告警评估
     */
    public function __construct(
        private readonly GeoMonitorRunService $runService,
        private readonly GeoMonitorAlertService $alertService,
    ) {}

    /**
     * 构建运行面板完整数据包。
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $sidecarHealth = $this->runService->sidecarHealth();
        $this->alertService->evaluateSidecarHealth($sidecarHealth);

        return [
            'is_operational' => $this->runService->isOperational(),
            'sidecar_health' => $sidecarHealth,
            'runs_24h' => $this->runStats(now()->subDay()),
            'runs_7d' => $this->runStats(now()->subDays(7)),
            'queue' => $this->queueStats(),
            'accounts' => $this->accountStats(),
            'evidence' => $this->evidenceStats(),
            'failure_distribution' => $this->failureDistribution(now()->subDays(7)),
            'recent_runs' => $this->recentRuns(),
            'active_schedules' => $this->activeSchedules(),
            'recent_alerts' => $this->recentAlerts(),
        ];
    }

    /**
     * @param  Carbon  $since  起始时间
     * @return array{total: int, succeeded: int, partial: int, failed: int, running: int}
     */
    private function runStats(Carbon $since): array
    {
        $runs = GeoMonitorRun::query()
            ->where('created_at', '>=', $since)
            ->get(['status']);

        return [
            'total' => $runs->count(),
            'succeeded' => $runs->where('status', 'succeeded')->count(),
            'partial' => $runs->where('status', 'partial')->count(),
            'failed' => $runs->where('status', 'failed')->count(),
            'running' => $runs->whereIn('status', ['pending', 'running', 'cancelling'])->count(),
        ];
    }

    /**
     * @return array{pending: int, running: int, failed_recent: int}
     */
    private function queueStats(): array
    {
        return [
            'pending' => GeoMonitorObservation::query()->where('status', 'pending')->count(),
            'running' => GeoMonitorObservation::query()->where('status', 'running')->count(),
            'failed_recent' => GeoMonitorObservation::query()
                ->whereIn('status', ['failed', 'blocked', 'needs_login', 'captcha_required'])
                ->where('updated_at', '>=', now()->subDay())
                ->count(),
        ];
    }

    /**
     * @return array{total: int, active: int, needs_maintenance: int, cooldown: int}
     */
    private function accountStats(): array
    {
        $accounts = GeoMonitorAccount::query()->get(['status', 'cooldown_until']);

        return [
            'total' => $accounts->count(),
            'active' => $accounts->where('status', 'active')->count(),
            'needs_maintenance' => $accounts->whereIn('status', [
                'needs_login',
                'needs_manual_maintenance',
                'captcha_required',
            ])->count(),
            'cooldown' => $accounts->filter(
                fn (GeoMonitorAccount $account): bool => $account->cooldown_until !== null && $account->cooldown_until->isFuture(),
            )->count(),
        ];
    }

    /**
     * @return array{root: string, bytes: int, human: string, file_count: int}
     */
    private function evidenceStats(): array
    {
        $root = rtrim((string) config('geoflow.geo_monitor.evidence_root', ''), '/');
        $bytes = 0;
        $fileCount = 0;

        if ($root !== '' && is_dir($root)) {
            foreach (File::allFiles($root) as $file) {
                $bytes += $file->getSize();
                $fileCount++;
            }
        }

        return [
            'root' => $root,
            'bytes' => $bytes,
            'human' => $this->formatBytes($bytes),
            'file_count' => $fileCount,
        ];
    }

    /**
     * @param  Carbon  $since  起始时间
     * @return list<array{status: string, count: int}>
     */
    private function failureDistribution(Carbon $since): array
    {
        return GeoMonitorObservation::query()
            ->where('updated_at', '>=', $since)
            ->whereNotIn('status', ['success', 'partial', 'pending', 'running', 'cancelled'])
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn ($row): array => [
                'status' => (string) $row->status,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<GeoMonitorRun>
     */
    private function recentRuns(): array
    {
        return GeoMonitorRun::query()
            ->with('project:id,name,slug')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->all();
    }

    /**
     * @return list<GeoMonitorSchedule>
     */
    private function activeSchedules(): array
    {
        return GeoMonitorSchedule::query()
            ->with('project:id,name,slug')
            ->where('is_enabled', true)
            ->whereIn('frequency', ['daily', 'weekly'])
            ->orderBy('next_run_at')
            ->limit(20)
            ->get()
            ->all();
    }

    /**
     * @return list<GeoMonitorAlert>
     */
    private function recentAlerts(): array
    {
        return GeoMonitorAlert::query()
            ->with(['project:id,name', 'run:id,status'])
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->all();
    }

    /**
     * @param  int  $bytes  字节数
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }
}
