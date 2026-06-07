@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        @include('admin.geo-monitoring.partials.subnav')

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.geo_monitoring.accounts_pool_hint') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="post" action="{{ route('admin.geo-monitoring.accounts.sync-sidecar') }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.geo_monitoring.button_sync_sidecar_accounts') }}</button>
                </form>
                <form method="post" action="{{ route('admin.geo-monitoring.accounts.clear-stale-locks') }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100">{{ __('admin.geo_monitoring.button_clear_stale_locks') }}</button>
                </form>
                <a href="{{ route('admin.geo-monitoring.accounts.create') }}" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.geo_monitoring.account_create_title') }}</a>
            </div>
        </div>

        @if (session('message'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('message') }}</div>
        @endif

        <div class="rounded-lg bg-white shadow overflow-x-auto">
            @if ($accounts->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.geo_monitoring.empty_accounts') }}</div>
            @else
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_platform') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_account_name') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_status') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_health') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_quota') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_schedulable') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($accounts as $account)
                            @php
                                $stats = $runtimeStats[$account->id] ?? [];
                            @endphp
                            <tr class="{{ ($stats['schedulable'] ?? false) ? '' : 'bg-amber-50/40' }}">
                                <td class="px-4 py-4 text-sm text-gray-900">{{ $account->platform->label }}</td>
                                <td class="px-4 py-4 text-sm text-gray-900">
                                    <div class="font-medium">{{ $account->label }}</div>
                                    <div class="mt-0.5 font-mono text-xs text-gray-400">{{ $account->external_id }}</div>
                                </td>
                                <td class="px-4 py-4 text-sm">
                                    <span class="rounded-full px-2 py-1 text-xs font-medium
                                        @if ($account->status === 'active') bg-emerald-100 text-emerald-800
                                        @elseif (in_array($account->status, ['needs_login', 'needs_maintenance'], true)) bg-red-100 text-red-800
                                        @else bg-gray-100 text-gray-700 @endif">
                                        {{ $account->status }}
                                    </span>
                                    @if ($account->cooldown_until && $account->cooldown_until->isFuture())
                                        <div class="mt-1 text-xs text-amber-600">{{ __('admin.geo_monitoring.cooldown_until', ['time' => $account->cooldown_until->format('m-d H:i')]) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-xs text-gray-600">
                                    <div>Profile: {{ $account->browserProfile?->health_status ?? '—' }}</div>
                                    <div>Proxy: {{ $account->proxyEndpoint?->status ?? '—' }}</div>
                                    @if ($account->last_error_message)
                                        <div class="mt-1 line-clamp-2 text-red-600">{{ $account->last_error_message }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-xs text-gray-600">
                                    <div>{{ __('admin.geo_monitoring.quota_hourly', ['used' => $stats['hourly_usage'] ?? 0, 'limit' => $account->hourly_quota ?? '∞']) }}</div>
                                    <div>{{ __('admin.geo_monitoring.quota_daily', ['used' => $stats['daily_usage'] ?? 0, 'limit' => $account->daily_quota ?? '∞']) }}</div>
                                </td>
                                <td class="px-4 py-4 text-xs text-gray-600">
                                    @if ($stats['schedulable'] ?? false)
                                        <span class="text-emerald-700">{{ __('admin.geo_monitoring.schedulable_yes') }}</span>
                                    @else
                                        <span class="text-amber-700">{{ __('admin.geo_monitoring.schedulable_no') }}</span>
                                    @endif
                                    @if (($stats['running_observations'] ?? 0) > 0)
                                        <div class="text-blue-600">{{ __('admin.geo_monitoring.running_observations', ['count' => $stats['running_observations']]) }}</div>
                                    @elseif ($stats['lock_orphaned'] ?? false)
                                        <div class="text-amber-600">{{ __('admin.geo_monitoring.account_lock_orphan') }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-right text-sm space-x-3">
                                    <a href="{{ route('admin.geo-monitoring.accounts.maintenance', ['accountId' => $account->id]) }}" class="text-amber-700 hover:text-amber-900">{{ __('admin.geo_monitoring.maintenance_link') }}</a>
                                    <a href="{{ route('admin.geo-monitoring.accounts.edit', ['accountId' => $account->id]) }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.button_edit') }}</a>
                                    <form method="post" action="{{ route('admin.geo-monitoring.accounts.toggle', ['accountId' => $account->id]) }}" class="inline">
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
