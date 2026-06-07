@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.geo-monitoring.proxies.update', ['proxyId' => $proxyId])
        : route('admin.geo-monitoring.proxies.store');
@endphp

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        @include('admin.geo-monitoring.partials.subnav')
        <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ $formAction }}" class="rounded-lg bg-white p-6 shadow space-y-6">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_name') }}</label>
                    <input type="text" name="label" required value="{{ old('label', $form['label']) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Type</label>
                    <input type="text" name="proxy_type" value="{{ old('proxy_type', $form['proxy_type']) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_host') }}</label>
                    <input type="text" name="host" required value="{{ old('host', $form['host']) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_port') }}</label>
                    <input type="number" name="port" required min="1" max="65535" value="{{ old('port', $form['port']) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_region') }}</label>
                    <input type="text" name="region" value="{{ old('region', $form['region']) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_status') }}</label>
                    <select name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @foreach (['active', 'disabled', 'cooldown'] as $status)
                            <option value="{{ $status }}" @selected(old('status', $form['status']) === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ route('admin.geo-monitoring.proxies.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('admin.geo_monitoring.button_cancel') }}</a>
                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.geo_monitoring.button_save') }}</button>
            </div>
        </form>
    </div>
@endsection
