{{-- 文章联网搜索配置页：读写 site_settings 中的 article_search_* 系列键。 --}}
@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.article_search.page_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.article_search.page_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.site-settings.index') }}"
               class="inline-flex w-fit items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                {{ __('admin.article_search.back') }}
            </a>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.article_search.section_main') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.article_search.section_main_desc') }}</p>
            </div>

            <div class="px-6 py-6">
                <form method="POST" action="{{ route('admin.site-settings.article-search.update') }}" class="space-y-6">
                    @csrf

                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 hover:border-blue-200">
                        <input type="checkbox" name="enabled" value="1" @checked($settings['enabled'])
                               class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-gray-900">{{ __('admin.article_search.field_enabled') }}</span>
                            <span class="mt-1 block text-xs leading-5 text-gray-500">{{ __('admin.article_search.help_enabled') }}</span>
                        </span>
                    </label>

                    <div>
                        <label for="article_search_endpoint" class="mb-2 block text-sm font-medium text-gray-700">
                            {{ __('admin.article_search.field_endpoint') }}
                        </label>
                        <input type="url" name="endpoint" id="article_search_endpoint"
                               value="{{ old('endpoint', $settings['endpoint']) }}"
                               placeholder="{{ $defaults['endpoint'] }}"
                               class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_search.help_endpoint') }}</p>
                    </div>

                    <div>
                        <label for="article_search_api_key" class="mb-2 block text-sm font-medium text-gray-700">
                            {{ __('admin.article_search.field_api_key') }}
                        </label>
                        <input type="password" name="api_key" id="article_search_api_key"
                               value="{{ old('api_key', $settings['api_key']) }}"
                               autocomplete="off"
                               class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_search.help_api_key') }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                        <div>
                            <label for="article_search_timeout" class="mb-2 block text-sm font-medium text-gray-700">
                                {{ __('admin.article_search.field_timeout') }}
                            </label>
                            <input type="number" name="timeout" id="article_search_timeout"
                                   min="1" max="120" step="1"
                                   value="{{ old('timeout', $settings['timeout']) }}"
                                   class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_search.help_timeout', ['default' => $defaults['timeout']]) }}</p>
                        </div>

                        <div>
                            <label for="article_search_max_results" class="mb-2 block text-sm font-medium text-gray-700">
                                {{ __('admin.article_search.field_max_results') }}
                            </label>
                            <input type="number" name="max_results" id="article_search_max_results"
                                   min="1" max="20" step="1"
                                   value="{{ old('max_results', $settings['max_results']) }}"
                                   class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_search.help_max_results') }}</p>
                        </div>

                        <div>
                            <label for="article_search_depth" class="mb-2 block text-sm font-medium text-gray-700">
                                {{ __('admin.article_search.field_search_depth') }}
                            </label>
                            <select name="search_depth" id="article_search_depth"
                                    class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="basic" @selected(old('search_depth', $settings['search_depth']) === 'basic')>{{ __('admin.article_search.depth_basic') }}</option>
                                <option value="advanced" @selected(old('search_depth', $settings['search_depth']) === 'advanced')>{{ __('admin.article_search.depth_advanced') }}</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_search.help_search_depth') }}</p>
                        </div>
                    </div>

                    <div>
                        <label for="article_search_include_domains" class="mb-2 block text-sm font-medium text-gray-700">
                            {{ __('admin.article_search.field_include_domains') }}
                        </label>
                        <textarea name="include_domains" id="article_search_include_domains" rows="3"
                                  class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                  placeholder="example.com,docs.example.com">{{ old('include_domains', $settings['include_domains']) }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_search.help_include_domains') }}</p>
                    </div>

                    <div>
                        <label for="article_search_cache_ttl" class="mb-2 block text-sm font-medium text-gray-700">
                            {{ __('admin.article_search.field_cache_ttl') }}
                        </label>
                        <input type="number" name="cache_ttl" id="article_search_cache_ttl"
                               min="0" max="604800" step="60"
                               value="{{ old('cache_ttl', $settings['cache_ttl']) }}"
                               class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_search.help_cache_ttl', ['default' => $defaults['cache_ttl']]) }}</p>
                    </div>

                    <div class="flex justify-end border-t border-gray-200 pt-2">
                        <button type="submit"
                                class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-6 py-3 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            <i data-lucide="save" class="mr-2 h-5 w-5"></i>
                            {{ __('admin.article_search.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
