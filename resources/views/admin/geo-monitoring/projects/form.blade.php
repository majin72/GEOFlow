@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.geo-monitoring.projects.update', ['projectId' => $projectId])
        : route('admin.geo-monitoring.projects.store');
@endphp

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        <div>
            <a href="{{ route('admin.geo-monitoring.index') }}" class="text-sm text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.button_back_projects') }}</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
        </div>

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
                    <input type="text" name="name" required value="{{ old('name', $form['name']) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_slug') }}</label>
                    <input type="text" name="slug" value="{{ old('slug', $form['slug']) }}" placeholder="auto-from-name" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_brand') }}</label>
                    <input type="text" name="brand_name" value="{{ old('brand_name', $form['brand_name']) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_domain') }}</label>
                    <input type="text" name="primary_domain" value="{{ old('primary_domain', $form['primary_domain']) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_status') }}</label>
                    <select name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="active" @selected(old('status', $form['status']) === 'active')>{{ __('admin.geo_monitoring.status_active') }}</option>
                        <option value="inactive" @selected(old('status', $form['status']) === 'inactive')>{{ __('admin.geo_monitoring.status_inactive') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_monitoring_questions') }}</label>
                <p class="mb-2 text-xs text-gray-500">{{ __('admin.geo_monitoring.field_monitoring_questions_help') }}</p>
                <textarea name="monitoring_questions" rows="8" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('monitoring_questions', $form['monitoring_questions']) }}</textarea>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_competitor_domains') }}</label>
                <textarea name="competitor_domains" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('competitor_domains', $form['competitor_domains']) }}</textarea>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_competitor_brands') }}</label>
                <textarea name="competitor_brands" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('competitor_brands', $form['competitor_brands']) }}</textarea>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_product_keywords') }}</label>
                <textarea name="product_keywords" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('product_keywords', $form['product_keywords']) }}</textarea>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.field_notes') }}</label>
                <textarea name="notes" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('notes', $form['notes']) }}</textarea>
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ $isEdit ? route('admin.geo-monitoring.project', ['projectId' => $projectId]) : route('admin.geo-monitoring.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('admin.geo_monitoring.button_cancel') }}</a>
                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.geo_monitoring.button_save') }}</button>
            </div>
        </form>
    </div>
@endsection
