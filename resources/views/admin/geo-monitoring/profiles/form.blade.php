@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.geo-monitoring.profiles.update', ['profileId' => $profileId])
        : route('admin.geo-monitoring.profiles.store');
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
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_external_id') }}</label>
                    <select name="account_id" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" @disabled($isEdit)>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected((string) old('account_id', $form['account_id']) === (string) $account->id)>
                                {{ $account->platform->label }} / {{ $account->external_id }}
                            </option>
                        @endforeach
                    </select>
                    @if ($isEdit)
                        <input type="hidden" name="account_id" value="{{ $form['account_id'] }}">
                    @endif
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Profile key</label>
                    <input type="text" name="profile_key" required value="{{ old('profile_key', $form['profile_key']) }}" class="w-full rounded-md border-gray-300 font-mono shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_profile_path') }}</label>
                    <input type="text" name="storage_path" required value="{{ old('storage_path', $form['storage_path']) }}" class="w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_health') }}</label>
                    <select name="health_status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @foreach (['unknown', 'healthy', 'degraded', 'maintenance', 'corrupted'] as $health)
                            <option value="{{ $health }}" @selected(old('health_status', $form['health_status']) === $health)>{{ $health }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ route('admin.geo-monitoring.profiles.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('admin.geo_monitoring.button_cancel') }}</a>
                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.geo_monitoring.button_save') }}</button>
            </div>
        </form>
    </div>
@endsection
