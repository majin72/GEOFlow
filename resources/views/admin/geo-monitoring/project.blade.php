@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        @include('admin.geo-monitoring.partials.subnav')

        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <a href="{{ route('admin.geo-monitoring.index') }}" class="text-sm text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.button_back_projects') }}</a>
                <h1 class="mt-2 text-2xl font-bold text-gray-900">{{ $project->name }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ $project->brand_name }} · {{ $project->primary_domain }} · {{ $project->status }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.geo-monitoring.projects.edit', ['projectId' => $project->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('admin.geo_monitoring.button_edit_project') }}</a>
                @if ($project->status === 'active')
                    <form method="post" action="{{ route('admin.geo-monitoring.projects.deactivate', ['projectId' => $project->id]) }}" onsubmit="return confirm('{{ __('admin.geo_monitoring.button_deactivate_project') }}?');">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 hover:bg-amber-100">{{ __('admin.geo_monitoring.button_deactivate_project') }}</button>
                    </form>
                @endif
            </div>
        </div>

        @if (session('message'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('message') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        @if ($projectReport)
            <div class="rounded-lg border border-blue-100 bg-gradient-to-r from-blue-50 to-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.project_report_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ $projectReport['conclusion'] }}</p>
                        <p class="mt-1 text-xs text-gray-400">
                            {{ __('admin.geo_monitoring.project_report_latest_run', ['id' => $projectReport['run_id'], 'time' => $projectReport['finished_at'] ?? '-']) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-6">
                        <div class="text-center">
                            <div class="text-xs text-gray-500">{{ __('admin.geo_monitoring.report_geo_score') }}</div>
                            <div class="text-3xl font-bold text-blue-900">{{ number_format($projectReport['geo_score'], 1) }}</div>
                        </div>
                        <div class="text-center">
                            <div class="text-xs text-gray-500">{{ __('admin.geo_monitoring.report_brand_mention_rate') }}</div>
                            <div class="text-xl font-semibold text-gray-900">{{ $projectReport['brand_mention_rate'] }}%</div>
                        </div>
                        <div class="text-center">
                            <div class="text-xs text-gray-500">{{ __('admin.geo_monitoring.report_own_citation_rate') }}</div>
                            <div class="text-xl font-semibold text-gray-900">{{ $projectReport['own_citation_rate'] }}%</div>
                        </div>
                        <a href="{{ route('admin.geo-monitoring.run', ['runId' => $projectReport['run_id']]) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">
                            {{ __('admin.geo_monitoring.button_view_run') }}
                        </a>
                    </div>
                </div>
            </div>
        @endif

        <form method="post" action="{{ route('admin.geo-monitoring.runs.store', ['projectId' => $project->id]) }}" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            <div class="text-sm font-medium text-gray-900">{{ __('admin.geo_monitoring.button_run') }}</div>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.geo_monitoring.platforms_label') }}</p>
            <div class="mt-4 flex flex-wrap gap-4">
                @foreach ($platforms as $platform)
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="platforms[]" value="{{ $platform->code }}" class="rounded border-gray-300 text-blue-600">
                        {{ $platform->label }} ({{ $platform->code }})
                    </label>
                @endforeach
            </div>
            <button type="submit" @disabled(! $isOperational) class="mt-6 inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('admin.geo_monitoring.button_run') }}
            </button>
        </form>

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.prompts_title') }}</h2>
                <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.prompts_edit_hint') }}</p>
            </div>
            @if ($project->prompts->isEmpty())
                <div class="px-6 py-8 text-sm text-gray-500">{{ __('admin.geo_monitoring.empty_prompts') }}</div>
            @else
                <ol class="list-decimal divide-y divide-gray-200 px-8 py-4">
                    @foreach ($project->prompts as $prompt)
                        <li class="py-3 text-sm text-gray-800">{{ $prompt->prompt_text }}</li>
                    @endforeach
                </ol>
            @endif
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.runs_title') }}</h2>
            </div>
            @if ($runs->isEmpty())
                <div class="px-6 py-8 text-sm text-gray-500">{{ __('admin.geo_monitoring.empty_runs') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_status') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_success') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_started') }}</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($runs as $run)
                                <tr>
                                    <td class="px-6 py-4 text-sm text-gray-900">#{{ $run->id }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $run->status }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $run->success_count }}/{{ $run->observation_count }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $run->started_at?->format('Y-m-d H:i') }}</td>
                                    <td class="px-6 py-4 text-right text-sm">
                                        <a href="{{ route('admin.geo-monitoring.run', ['runId' => $run->id]) }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.button_view_run') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
