@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.geo_monitoring.page_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.geo_monitoring.page_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.geo-monitoring.projects.create') }}" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                {{ __('admin.geo_monitoring.button_create_project') }}
            </a>
            <a href="{{ route('admin.geo-monitoring.dashboard') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ __('admin.geo_monitoring.dashboard_title') }}
            </a>
        </div>

        @include('admin.geo-monitoring.partials.subnav')

        @if (session('message'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('message') }}</div>
        @endif

        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center gap-3">
                <div class="text-sm font-medium text-gray-500">{{ __('admin.geo_monitoring.sidecar_status') }}</div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">{{ $runtimeConfig->label() }}</span>
            </div>
            <p class="mt-2 text-xs text-gray-500">{{ $runtimeConfig->description() }}</p>
            @if ($isOperational)
                <div class="mt-2 text-sm text-green-700">{{ __('admin.geo_monitoring.sidecar_ok') }}</div>
                @if (is_array($sidecarHealth))
                    <pre class="mt-3 max-h-40 overflow-auto rounded bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($sidecarHealth, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                @endif
            @else
                <div class="mt-2 text-sm text-amber-700">{{ __('admin.geo_monitoring.sidecar_disabled') }}</div>
            @endif
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.projects_title') }}</h2>
            </div>
            @if ($projects->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.geo_monitoring.empty_projects') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_name') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_brand') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_domain') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_status') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_prompts') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_runs') }}</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($projects as $project)
                                <tr>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $project->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $project->brand_name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $project->primary_domain }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $project->status }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $project->prompts_count }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $project->runs_count }}</td>
                                    <td class="px-6 py-4 text-right text-sm space-x-3">
                                        <a href="{{ route('admin.geo-monitoring.project', ['projectId' => $project->id]) }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.button_view_project') }}</a>
                                        <a href="{{ route('admin.geo-monitoring.projects.edit', ['projectId' => $project->id]) }}" class="text-gray-600 hover:text-gray-900">{{ __('admin.geo_monitoring.button_edit') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-gray-200 px-6 py-4">
                    {{ $projects->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
