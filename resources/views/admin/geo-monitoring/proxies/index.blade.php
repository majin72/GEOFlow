@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        @include('admin.geo-monitoring.partials.subnav')

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
            <a href="{{ route('admin.geo-monitoring.proxies.create') }}" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.geo_monitoring.proxy_create_title') }}</a>
        </div>

        @if (session('message'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('message') }}</div>
        @endif

        <div class="rounded-lg bg-white shadow overflow-x-auto">
            @if ($proxies->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.geo_monitoring.empty_proxies') }}</div>
            @else
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_host') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_region') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_health') }}</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($proxies as $proxy)
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-900">{{ $proxy->label }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $proxy->host }}:{{ $proxy->port }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $proxy->region ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $proxy->status }}</td>
                                <td class="px-6 py-4 text-xs text-gray-600">
                                    <div>{{ $proxy->last_health_status ?? '—' }}</div>
                                    @if ($proxy->cooldown_until && $proxy->cooldown_until->isFuture())
                                        <div class="text-amber-600">{{ __('admin.geo_monitoring.cooldown_until', ['time' => $proxy->cooldown_until->format('m-d H:i')]) }}</div>
                                    @endif
                                    <div>{{ __('admin.geo_monitoring.proxy_failures', ['count' => $proxy->failure_count]) }}</div>
                                </td>
                                <td class="px-6 py-4 text-right text-sm space-x-3">
                                    <a href="{{ route('admin.geo-monitoring.proxies.edit', ['proxyId' => $proxy->id]) }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.button_edit') }}</a>
                                    <form method="post" action="{{ route('admin.geo-monitoring.proxies.toggle', ['proxyId' => $proxy->id]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-amber-600 hover:text-amber-800">{{ __('admin.geo_monitoring.button_toggle') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection
