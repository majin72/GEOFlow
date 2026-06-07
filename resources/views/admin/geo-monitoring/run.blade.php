@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('admin.geo-monitoring.project', ['projectId' => $run->project_id]) }}" class="text-sm text-blue-600 hover:text-blue-800">{{ $run->project->name }}</a>
                <h1 class="mt-2 text-2xl font-bold text-gray-900">{{ __('admin.geo_monitoring.run_title', ['id' => $run->id]) }}</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __('admin.geo_monitoring.field_status') }}:
                    <span class="font-medium">{{ $run->status }}</span>
                    · {{ __('admin.geo_monitoring.field_success') }}: {{ $run->success_count }}/{{ $run->observation_count }}
                    @if ($run->started_at)
                        · {{ __('admin.geo_monitoring.field_started') }}: {{ $run->started_at->format('Y-m-d H:i') }}
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($canRetryFailed)
                    <form method="post" action="{{ route('admin.geo-monitoring.runs.retry-failed', ['runId' => $run->id]) }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100">
                            {{ __('admin.geo_monitoring.button_retry_failed') }}
                        </button>
                    </form>
                @endif
                @if ($canCancelRun)
                    <form method="post" action="{{ route('admin.geo-monitoring.runs.cancel', ['runId' => $run->id]) }}" onsubmit="return confirm(@json(__('admin.geo_monitoring.confirm_cancel_run')))">
                        @csrf
                        <button type="submit" class="rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                            {{ __('admin.geo_monitoring.button_cancel_run') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        @if (session('message'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('message') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @include('admin.geo-monitoring.partials.report', ['runReport' => $runReport])

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.observations_title') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_platform') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_resource') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_prompt') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_status') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_mentions') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_citations') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_answer') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_duration') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_evidence') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach ($run->observations->sortBy('id') as $observation)
                            @php
                                $evidenceTypes = $evidenceTypesByObservation[$observation->id] ?? [];
                            @endphp
                            <tr class="{{ in_array($observation->status, ['failed', 'captcha_required', 'needs_login', 'selector_miss'], true) ? 'bg-red-50/40' : '' }}">
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-500">#{{ $observation->id }}</td>
                                <td class="px-4 py-4 text-sm text-gray-900">{{ $observation->platform->label }}</td>
                                <td class="max-w-[10rem] px-4 py-4 text-xs text-gray-600">
                                    @php $assignment = $observation->resourceAssignment; @endphp
                                    @if ($assignment)
                                        <div class="font-mono">{{ $assignment->account?->external_id ?? '—' }}</div>
                                        <div class="mt-1 text-gray-500">{{ $assignment->scheduler_strategy ?? '—' }}</div>
                                        @if ($assignment->proxyEndpoint)
                                            <div class="mt-1">{{ $assignment->proxyEndpoint->label }}</div>
                                        @endif
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="max-w-xs px-4 py-4 text-sm text-gray-800">
                                    <div class="line-clamp-3">{{ $observation->prompt_text_snapshot }}</div>
                                    @if ($observation->retried_from_observation_id)
                                        <div class="mt-1 text-xs text-amber-700">
                                            {{ __('admin.geo_monitoring.retry_from', ['id' => $observation->retried_from_observation_id]) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-sm">
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium
                                        @if (in_array($observation->status, ['success', 'partial'], true)) bg-emerald-100 text-emerald-800
                                        @elseif (in_array($observation->status, ['pending', 'running'], true)) bg-blue-100 text-blue-800
                                        @elseif ($observation->status === 'cancelled') bg-gray-100 text-gray-600
                                        @else bg-red-100 text-red-800 @endif">
                                        {{ $observation->status }}
                                    </span>
                                    @if ($observation->error_message)
                                        <div class="mt-1 max-w-xs text-xs text-red-600">{{ $observation->error_message }}</div>
                                    @endif
                                </td>
                                <td class="max-w-xs px-4 py-4 text-sm text-gray-600">
                                    @forelse ($observation->mentions as $mention)
                                        <span class="mb-1 mr-1 inline-flex rounded-full bg-emerald-50 px-2 py-1 text-xs text-emerald-700">{{ $mention->entity_name }}</span>
                                    @empty
                                        <span class="text-gray-400">{{ __('admin.geo_monitoring.report_none') }}</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-600">{{ $observation->citations->count() }}</td>
                                <td class="max-w-sm px-4 py-4 text-sm text-gray-600">
                                    @if ($observation->answer_text)
                                        <div class="line-clamp-4">{{ $observation->answer_text }}</div>
                                    @else
                                        <span class="text-gray-400">{{ __('admin.geo_monitoring.report_none') }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-600">
                                    {{ $observation->duration_ms ? $observation->duration_ms.' ms' : '—' }}
                                </td>
                                <td class="max-w-xs px-4 py-4 text-xs text-gray-600">
                                    @if ($evidenceTypes !== [])
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($evidenceTypes as $type)
                                                <a
                                                    href="{{ route('admin.geo-monitoring.observations.evidence', ['runId' => $run->id, 'observationId' => $observation->id, 'type' => $type]) }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="inline-flex rounded border border-gray-200 bg-white px-2 py-1 text-xs text-blue-700 hover:bg-blue-50"
                                                >
                                                    {{ strtoupper($type) }}
                                                </a>
                                                <a
                                                    href="{{ route('admin.geo-monitoring.observations.evidence.download', ['runId' => $run->id, 'observationId' => $observation->id, 'type' => $type]) }}"
                                                    class="inline-flex rounded border border-gray-200 bg-gray-50 px-1.5 py-1 text-xs text-gray-500 hover:bg-gray-100"
                                                    title="{{ __('admin.geo_monitoring.button_download_evidence') }}"
                                                >↓</a>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-gray-400">{{ __('admin.geo_monitoring.evidence_unavailable') }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm">
                                    @if (in_array($observation->status, ['failed', 'partial', 'captcha_required', 'needs_login', 'selector_miss'], true) && ! $observation->retries->whereIn('status', ['pending', 'running'])->count())
                                        <form method="post" action="{{ route('admin.geo-monitoring.observations.retry', ['runId' => $run->id, 'observationId' => $observation->id]) }}">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium text-amber-700 hover:text-amber-900">
                                                {{ __('admin.geo_monitoring.button_retry_observation') }}
                                            </button>
                                        </form>
                                    @elseif ($observation->retries->whereIn('status', ['pending', 'running'])->count())
                                        <span class="text-xs text-blue-600">{{ __('admin.geo_monitoring.retry_in_progress') }}</span>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.run_logs_title') }}</h2>
            </div>
            <div class="px-6 py-4">
                @forelse ($runLogs as $log)
                    <div class="flex gap-3 border-b border-gray-100 py-3 text-sm last:border-0">
                        <span class="shrink-0 font-mono text-xs text-gray-400">{{ $log['at'] ?? '' }}</span>
                        <span class="shrink-0 uppercase
                            @if (($log['level'] ?? '') === 'error') text-red-600
                            @elseif (($log['level'] ?? '') === 'warning') text-amber-600
                            @else text-gray-500 @endif">
                            {{ $log['level'] ?? 'info' }}
                        </span>
                        <span class="text-gray-700">{{ $log['message'] ?? '' }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('admin.geo_monitoring.run_logs_empty') }}</p>
                @endforelse
            </div>
        </div>
    </div>
@endsection
