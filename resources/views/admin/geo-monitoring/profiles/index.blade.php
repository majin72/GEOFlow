@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        @include('admin.geo-monitoring.partials.subnav')

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
            <a href="{{ route('admin.geo-monitoring.profiles.create') }}" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.geo_monitoring.profile_create_title') }}</a>
        </div>

        @if (session('message'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('message') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="rounded-lg bg-white shadow overflow-x-auto">
            @if ($profiles->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.geo_monitoring.empty_profiles') }}</div>
            @else
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Profile key</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_platform') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_external_id') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_health') }}</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($profiles as $profile)
                            <tr>
                                <td class="px-6 py-4 text-sm font-mono text-gray-800">{{ $profile->profile_key }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $profile->account?->platform?->label ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $profile->account?->external_id ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $profile->health_status }}</td>
                                <td class="px-6 py-4 text-right text-sm space-x-3">
                                    <a href="{{ route('admin.geo-monitoring.profiles.edit', ['profileId' => $profile->id]) }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.button_edit') }}</a>
                                    <form method="post" action="{{ route('admin.geo-monitoring.profiles.delete', ['profileId' => $profile->id]) }}" class="inline" onsubmit="return confirm(@js(__('admin.geo_monitoring.confirm_delete_profile', ['name' => $profile->profile_key])));">
                                        @csrf
                                        <button type="submit" class="text-red-600 hover:text-red-800">{{ __('admin.geo_monitoring.button_delete') }}</button>
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
