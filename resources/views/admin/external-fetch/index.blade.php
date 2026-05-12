{{-- 外部浏览器抓取（External Fetch）配置页：读写 site_settings 中的 external_fetch_* 系列键。 --}}
@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.external_fetch.page_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.external_fetch.page_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.site-settings.index') }}"
               class="inline-flex w-fit items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                {{ __('admin.external_fetch.back') }}
            </a>
        </div>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.external_fetch.section_main') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.external_fetch.section_main_desc') }}</p>
            </div>

            <div class="px-6 py-6">
                <form method="POST" action="{{ route('admin.site-settings.external-fetch.update') }}" class="space-y-6">
                    @csrf

                    {{-- 总开关：关闭时所有 URL 仍走原有直连逻辑，与现有行为完全一致。 --}}
                    <label class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 cursor-pointer hover:border-blue-200">
                        <input type="checkbox" name="enabled" value="1" @checked($settings['enabled'])
                               class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-gray-900">{{ __('admin.external_fetch.field_enabled') }}</span>
                            <span class="mt-1 block text-xs leading-5 text-gray-500">{{ __('admin.external_fetch.help_enabled') }}</span>
                        </span>
                    </label>

                    {{-- Bridge 端点：本地浏览器 HTTP 包装器的入口 URL。 --}}
                    <div>
                        <label for="external_fetch_endpoint" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('admin.external_fetch.field_endpoint') }}
                        </label>
                        <input type="url" name="endpoint" id="external_fetch_endpoint"
                               value="{{ old('endpoint', $settings['endpoint']) }}"
                               placeholder="{{ __('admin.external_fetch.placeholder_endpoint') }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.external_fetch.help_endpoint') }}</p>
                    </div>

                    {{-- Bearer Token：与 bridge .env 的 BRIDGE_TOKEN 必须一致；保存表单时回填明文方便确认。 --}}
                    <div>
                        <label for="external_fetch_token" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('admin.external_fetch.field_token') }}
                        </label>
                        <input type="password" name="token" id="external_fetch_token"
                               value="{{ old('token', $settings['token']) }}"
                               autocomplete="off"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.external_fetch.help_token') }}</p>
                    </div>

                    {{-- 超时：单次抓取的最长等待秒数。 --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="external_fetch_timeout" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('admin.external_fetch.field_timeout') }}
                            </label>
                            <input type="number" name="timeout" id="external_fetch_timeout"
                                   min="1" max="600" step="1"
                                   value="{{ old('timeout', $settings['timeout']) }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <p class="mt-1 text-xs text-gray-500">
                                {{ __('admin.external_fetch.help_timeout', ['default' => $defaults['timeout']]) }}
                            </p>
                        </div>
                    </div>

                    {{-- 域名白名单：命中即首选外部抓取（不需要先尝试直连）。 --}}
                    <div>
                        <label for="external_fetch_domains" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('admin.external_fetch.field_domains') }}
                        </label>
                        <textarea name="domains" id="external_fetch_domains" rows="3"
                                  placeholder="{{ $defaults['domains'] }}"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm">{{ old('domains', $settings['domains']) }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.external_fetch.help_domains') }}</p>
                    </div>

                    {{-- 回退状态码：直连抓取拿到这些 HTTP 状态时回退到外部浏览器。 --}}
                    <div>
                        <label for="external_fetch_retry_on_status" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('admin.external_fetch.field_retry_on_status') }}
                        </label>
                        <input type="text" name="retry_on_status" id="external_fetch_retry_on_status"
                               value="{{ old('retry_on_status', $settings['retry_on_status']) }}"
                               placeholder="{{ $defaults['retry_on_status'] }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.external_fetch.help_retry_on_status') }}</p>
                    </div>

                    <div class="flex justify-end pt-2 border-t border-gray-200">
                        <button type="submit"
                                class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            <i data-lucide="save" class="w-5 h-5 mr-2"></i>
                            {{ __('admin.external_fetch.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
