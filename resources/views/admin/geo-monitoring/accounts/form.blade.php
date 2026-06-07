@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.geo-monitoring.accounts.update', ['accountId' => $accountId])
        : route('admin.geo-monitoring.accounts.store');
@endphp

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        @include('admin.geo-monitoring.partials.subnav')

        <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        @unless ($isEdit)
            <div class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                {{ __('admin.geo_monitoring.account_create_simple_hint') }}
            </div>
        @endunless

        <form method="post" action="{{ $formAction }}" class="rounded-lg bg-white p-6 shadow space-y-6">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            @if ($isEdit)
                <div class="grid gap-6 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_platform') }}</label>
                        <select name="platform_id" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach ($platforms as $platform)
                                <option value="{{ $platform->id }}" @selected((string) old('platform_id', $form['platform_id']) === (string) $platform->id)>{{ $platform->label }} ({{ $platform->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_account_name') }}</label>
                        <input type="text" name="label" required value="{{ old('label', $form['label']) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-500">{{ __('admin.geo_monitoring.field_internal_id') }}</label>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-600">{{ $form['external_id'] }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_status') }}</label>
                        <select name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach (['active', 'disabled', 'needs_login', 'needs_maintenance', 'cooldown'] as $status)
                                <option value="{{ $status }}" @selected(old('status', $form['status']) === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_profile_path') }}</label>
                        <input type="text" name="profile_storage_path" value="{{ old('profile_storage_path', $form['profile_storage_path']) }}" class="w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_proxy') }}</label>
                        <select name="proxy_endpoint_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">—</option>
                            @foreach ($proxies as $proxy)
                                <option value="{{ $proxy->id }}" @selected((string) old('proxy_endpoint_id', $form['proxy_endpoint_id']) === (string) $proxy->id)>{{ $proxy->label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @if ($form['create_profile'])
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="create_profile" value="1" checked class="rounded border-gray-300 text-blue-600">
                        {{ __('admin.geo_monitoring.create_profile_checkbox') }}
                    </label>
                @endif
            @else
                <div class="mx-auto max-w-md space-y-6">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_platform') }}</label>
                        <select name="platform_id" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">{{ __('admin.geo_monitoring.placeholder_select_platform') }}</option>
                            @foreach ($platforms as $platform)
                                <option value="{{ $platform->id }}" @selected((string) old('platform_id', $form['platform_id']) === (string) $platform->id)>{{ $platform->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_account_name') }}</label>
                        <input type="text" name="label" required autofocus value="{{ old('label', $form['label']) }}" placeholder="{{ __('admin.geo_monitoring.placeholder_account_name') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <a href="{{ route('admin.geo-monitoring.accounts.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('admin.geo_monitoring.button_cancel') }}</a>
                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    {{ $isEdit ? __('admin.geo_monitoring.button_save') : __('admin.geo_monitoring.button_save_and_login') }}
                </button>
            </div>
        </form>
    </div>
@endsection
