@extends('admin.layouts.app')

@section('content')
    @php
        $runs24 = $dashboard['runs_24h'] ?? [];
        $runs7d = $dashboard['runs_7d'] ?? [];
        $queue = $dashboard['queue'] ?? [];
        $accounts = $dashboard['accounts'] ?? [];
        $evidence = $dashboard['evidence'] ?? [];
        $failureDistribution = $dashboard['failure_distribution'] ?? [];
        $recentRuns = $dashboard['recent_runs'] ?? [];
        $activeSchedules = $dashboard['active_schedules'] ?? [];
        $recentAlerts = $dashboard['recent_alerts'] ?? [];
        $sidecarHealth = $dashboard['sidecar_health'] ?? null;
        $isOperational = (bool) ($dashboard['is_operational'] ?? false);
    @endphp

    <div class="space-y-8 px-4 sm:px-0">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.geo_monitoring.dashboard_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.geo_monitoring.dashboard_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.geo-monitoring.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ __('admin.geo_monitoring.button_back_projects') }}
            </a>
        </div>

        @include('admin.geo-monitoring.partials.subnav')

        @if (session('message'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('message') }}</div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.geo_monitoring.dashboard_sidecar') }}</div>
                <div class="mt-2 text-lg font-semibold {{ $isOperational ? 'text-green-700' : 'text-amber-700' }}">
                    {{ $isOperational ? __('admin.geo_monitoring.sidecar_ok') : __('admin.geo_monitoring.sidecar_disabled') }}
                </div>
                @if (is_array($sidecarHealth))
                    <pre class="mt-3 max-h-24 overflow-auto rounded bg-gray-50 p-2 text-xs text-gray-600">{{ json_encode($sidecarHealth, JSON_UNESCAPED_UNICODE) }}</pre>
                @endif
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.geo_monitoring.dashboard_runs_24h') }}</div>
                <div class="mt-2 text-3xl font-bold text-gray-900">{{ $runs24['total'] ?? 0 }}</div>
                <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-600">
                    <span class="rounded bg-green-50 px-2 py-0.5 text-green-800">{{ __('admin.geo_monitoring.dashboard_succeeded') }} {{ $runs24['succeeded'] ?? 0 }}</span>
                    <span class="rounded bg-amber-50 px-2 py-0.5 text-amber-800">{{ __('admin.geo_monitoring.dashboard_partial') }} {{ $runs24['partial'] ?? 0 }}</span>
                    <span class="rounded bg-red-50 px-2 py-0.5 text-red-800">{{ __('admin.geo_monitoring.dashboard_failed') }} {{ $runs24['failed'] ?? 0 }}</span>
                </div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.geo_monitoring.dashboard_queue') }}</div>
                <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                    <div>
                        <div class="text-2xl font-bold text-gray-900">{{ $queue['pending'] ?? 0 }}</div>
                        <div class="text-xs text-gray-500">{{ __('admin.geo_monitoring.dashboard_pending') }}</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-700">{{ $queue['running'] ?? 0 }}</div>
                        <div class="text-xs text-gray-500">{{ __('admin.geo_monitoring.dashboard_running') }}</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-red-700">{{ $queue['failed_recent'] ?? 0 }}</div>
                        <div class="text-xs text-gray-500">{{ __('admin.geo_monitoring.dashboard_failed_24h') }}</div>
                    </div>
                </div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.geo_monitoring.dashboard_accounts') }}</div>
                <div class="mt-2 text-3xl font-bold text-gray-900">{{ $accounts['active'] ?? 0 }}<span class="text-lg font-normal text-gray-400">/{{ $accounts['total'] ?? 0 }}</span></div>
                <div class="mt-2 text-xs text-gray-600">
                    {{ __('admin.geo_monitoring.dashboard_needs_maintenance') }}: {{ $accounts['needs_maintenance'] ?? 0 }} ·
                    {{ __('admin.geo_monitoring.dashboard_cooldown') }}: {{ $accounts['cooldown'] ?? 0 }}
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.dashboard_runs_7d') }}</h2>
                </div>
                <div class="px-6 py-4 text-sm text-gray-700">
                    <div class="flex flex-wrap gap-4">
                        <span>{{ __('admin.geo_monitoring.dashboard_total') }}: <strong>{{ $runs7d['total'] ?? 0 }}</strong></span>
                        <span>{{ __('admin.geo_monitoring.dashboard_succeeded') }}: <strong>{{ $runs7d['succeeded'] ?? 0 }}</strong></span>
                        <span>{{ __('admin.geo_monitoring.dashboard_partial') }}: <strong>{{ $runs7d['partial'] ?? 0 }}</strong></span>
                        <span>{{ __('admin.geo_monitoring.dashboard_failed') }}: <strong>{{ $runs7d['failed'] ?? 0 }}</strong></span>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.dashboard_evidence') }}</h2>
                </div>
                <div class="px-6 py-4 text-sm text-gray-700 space-y-1">
                    <div>{{ __('admin.geo_monitoring.dashboard_evidence_size') }}: <strong>{{ $evidence['human'] ?? '0 B' }}</strong></div>
                    <div>{{ __('admin.geo_monitoring.dashboard_evidence_files') }}: <strong>{{ $evidence['file_count'] ?? 0 }}</strong></div>
                    @if (! empty($evidence['root']))
                        <div class="truncate text-xs text-gray-400" title="{{ $evidence['root'] }}">{{ $evidence['root'] }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.report_failure_distribution') }}</h2>
                    <p class="text-xs text-gray-500">{{ __('admin.geo_monitoring.dashboard_failure_hint') }}</p>
                </div>
                @if ($failureDistribution === [])
                    <div class="px-6 py-8 text-center text-sm text-gray-500">{{ __('admin.geo_monitoring.dashboard_no_failures') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_status') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.dashboard_count') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($failureDistribution as $row)
                                    <tr>
                                        <td class="px-6 py-3 font-mono text-gray-800">{{ $row['status'] }}</td>
                                        <td class="px-6 py-3 text-right text-gray-700">{{ $row['count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.schedule.active_title') }}</h2>
                </div>
                @if ($activeSchedules === [])
                    <div class="px-6 py-8 text-center text-sm text-gray-500">{{ __('admin.geo_monitoring.schedule.empty') }}</div>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach ($activeSchedules as $schedule)
                            <div class="px-6 py-4 text-sm">
                                <div class="font-medium text-gray-900">
                                    <a href="{{ route('admin.geo-monitoring.project', ['projectId' => $schedule->project_id]) }}" class="text-blue-600 hover:text-blue-800">
                                        {{ $schedule->project?->name }}
                                    </a>
                                </div>
                                <div class="mt-1 text-gray-600">
                                    {{ __('admin.geo_monitoring.schedule.frequency_'.$schedule->frequency) }}
                                    · {{ $schedule->run_time }} ({{ $schedule->timezone }})
                                </div>
                                <div class="mt-1 text-xs text-gray-400">
                                    {{ __('admin.geo_monitoring.schedule.next_run') }}: {{ $schedule->next_run_at?->timezone($schedule->timezone)->format('Y-m-d H:i') ?? '-' }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.dashboard_recent_runs') }}</h2>
            </div>
            @if ($recentRuns === [])
                <div class="px-6 py-8 text-center text-sm text-gray-500">{{ __('admin.geo_monitoring.empty_runs') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_name') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_status') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_started') }}</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($recentRuns as $run)
                                <tr>
                                    <td class="px-6 py-3 font-mono text-gray-700">#{{ $run->id }}</td>
                                    <td class="px-6 py-3 text-gray-900">{{ $run->project?->name }}</td>
                                    <td class="px-6 py-3"><span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ $run->status }}</span></td>
                                    <td class="px-6 py-3 text-gray-600">{{ $run->started_at?->format('Y-m-d H:i') ?? '-' }}</td>
                                    <td class="px-6 py-3 text-right">
                                        <a href="{{ route('admin.geo-monitoring.run', ['runId' => $run->id]) }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.button_view_run') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.alert.recent_title') }}</h2>
            </div>
            @if ($recentAlerts === [])
                <div class="px-6 py-8 text-center text-sm text-gray-500">{{ __('admin.geo_monitoring.alert.empty') }}</div>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach ($recentAlerts as $alert)
                        <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-start sm:justify-between {{ $alert->acknowledged_at ? 'opacity-60' : '' }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $alert->severity === 'critical' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800' }}">
                                        {{ $alert->severity }}
                                    </span>
                                    <span class="font-medium text-gray-900">{{ $alert->title }}</span>
                                </div>
                                <p class="mt-1 text-sm text-gray-600">{{ $alert->message }}</p>
                                <p class="mt-1 text-xs text-gray-400">{{ $alert->created_at?->format('Y-m-d H:i') }}</p>
                            </div>
                            @if (! $alert->acknowledged_at)
                                <form method="post" action="{{ route('admin.geo-monitoring.alerts.acknowledge', ['alertId' => $alert->id]) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">
                                        {{ __('admin.geo_monitoring.alert.acknowledge') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
