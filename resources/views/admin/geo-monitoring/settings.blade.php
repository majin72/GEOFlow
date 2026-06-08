@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        @include('admin.geo-monitoring.partials.subnav')

        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.geo_monitoring.settings.page_title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.geo_monitoring.settings.page_subtitle') }}</p>
        </div>

        @if (session('message'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('message') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('admin.geo-monitoring.settings.update') }}" class="space-y-6">
            @csrf

            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.settings.section_schedule') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.geo_monitoring.settings.section_schedule_hint') }}</p>
                </div>
                <div class="px-6 py-5">
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <input type="checkbox" name="schedule_enabled" value="1" @checked(old('schedule_enabled', $settings['schedule_enabled'])) class="mt-0.5 rounded border-gray-300 text-blue-600">
                        <span>
                            <span class="block text-sm font-medium text-gray-900">{{ __('admin.geo_monitoring.settings.field_schedule_enabled') }}</span>
                            <span class="mt-1 block text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_schedule_enabled') }}</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.settings.section_alert') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.geo_monitoring.settings.section_alert_hint') }}</p>
                </div>
                <div class="space-y-5 px-6 py-5">
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <input type="checkbox" name="alert_enabled" value="1" @checked(old('alert_enabled', $settings['alert_enabled'])) class="mt-0.5 rounded border-gray-300 text-blue-600">
                        <span>
                            <span class="block text-sm font-medium text-gray-900">{{ __('admin.geo_monitoring.settings.field_alert_enabled') }}</span>
                            <span class="mt-1 block text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_alert_enabled') }}</span>
                        </span>
                    </label>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_dedupe_minutes') }}</label>
                            <input type="number" name="dedupe_minutes" min="5" max="1440" value="{{ old('dedupe_minutes', $settings['dedupe_minutes']) }}" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_dedupe_minutes', ['default' => $defaults['dedupe_minutes']]) }}</p>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_citation_drop_threshold') }}</label>
                            <input type="number" name="citation_drop_threshold" min="0.05" max="0.95" step="0.05" value="{{ old('citation_drop_threshold', $settings['citation_drop_threshold']) }}" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_citation_drop_threshold') }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_webhook_url') }}</label>
                        <input type="url" name="webhook_url" value="{{ old('webhook_url', $settings['webhook_url']) }}" placeholder="https://..." class="w-full rounded-md border-gray-300 font-mono text-sm shadow-sm">
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_webhook_url') }}</p>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border border-blue-100 bg-white shadow-sm">
                <div class="border-b border-blue-100 bg-blue-50/60 px-6 py-4">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.settings.section_mail') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.geo_monitoring.settings.section_mail_hint') }}</p>
                </div>
                <div class="space-y-6 px-6 py-5">
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <input type="checkbox" name="mail_enabled" value="1" @checked(old('mail_enabled', $settings['mail_enabled'])) class="mt-0.5 rounded border-gray-300 text-blue-600">
                        <span>
                            <span class="block text-sm font-medium text-gray-900">{{ __('admin.geo_monitoring.settings.field_mail_enabled') }}</span>
                            <span class="mt-1 block text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_mail_enabled') }}</span>
                        </span>
                    </label>

                    <div class="rounded-lg border border-dashed border-blue-200 bg-blue-50/40 p-4 text-xs leading-6 text-blue-900">
                        <p class="font-medium">{{ __('admin.geo_monitoring.settings.smtp_presets_title') }}</p>
                        <ul class="mt-2 list-inside list-disc space-y-1 text-blue-800/90">
                            <li>{{ __('admin.geo_monitoring.settings.smtp_preset_qq') }}</li>
                            <li>{{ __('admin.geo_monitoring.settings.smtp_preset_163') }}</li>
                            <li>{{ __('admin.geo_monitoring.settings.smtp_preset_aliyun') }}</li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('admin.geo_monitoring.settings.section_smtp') }}</h3>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_smtp_host') }}</label>
                                <input type="text" name="smtp_host" value="{{ old('smtp_host', $settings['smtp_host']) }}" placeholder="smtp.qq.com" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_smtp_host') }}</p>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_smtp_port') }}</label>
                                <input type="number" name="smtp_port" min="1" max="65535" value="{{ old('smtp_port', $settings['smtp_port'] ?: $defaults['smtp_port']) }}" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_smtp_port') }}</p>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_smtp_encryption') }}</label>
                                <select name="smtp_encryption" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    @foreach (['ssl' => __('admin.geo_monitoring.settings.encryption_ssl'), 'tls' => __('admin.geo_monitoring.settings.encryption_tls'), 'none' => __('admin.geo_monitoring.settings.encryption_none')] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('smtp_encryption', $settings['smtp_encryption'] ?: $defaults['smtp_encryption']) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_smtp_encryption') }}</p>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_smtp_username') }}</label>
                                <input type="email" name="smtp_username" value="{{ old('smtp_username', $settings['smtp_username']) }}" placeholder="your@qq.com" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_smtp_username') }}</p>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_smtp_password') }}</label>
                                <input type="password" name="smtp_password" value="" autocomplete="new-password" placeholder="{{ $settings['smtp_password_configured'] ? __('admin.geo_monitoring.settings.placeholder_smtp_password_keep') : __('admin.geo_monitoring.settings.placeholder_smtp_password_new') }}" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_smtp_password') }}</p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('admin.geo_monitoring.settings.section_sender') }}</h3>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_mail_from_name') }}</label>
                                <input type="text" name="mail_from_name" value="{{ old('mail_from_name', $settings['mail_from_name']) }}" placeholder="{{ $defaults['mail_from_name'] }}" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_mail_from_address') }}</label>
                                <input type="email" name="mail_from_address" value="{{ old('mail_from_address', $settings['mail_from_address']) }}" placeholder="monitor@example.com" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_mail_from') }}</p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('admin.geo_monitoring.settings.section_recipients') }}</h3>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.geo_monitoring.settings.field_mail_recipients') }}</label>
                        <textarea name="mail_recipients" rows="5" placeholder="ops@example.com&#10;dev@example.com" class="w-full rounded-md border-gray-300 font-mono text-sm shadow-sm">{{ old('mail_recipients', $settings['mail_recipients']) }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.help_mail_recipients') }}</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-gray-500">{{ __('admin.geo_monitoring.settings.test_mail_hint') }}</p>
                <div class="flex flex-wrap justify-end gap-3">
                    <button type="submit" formaction="{{ route('admin.geo-monitoring.settings.test-mail') }}" formmethod="post" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ __('admin.geo_monitoring.settings.button_test_mail') }}
                    </button>
                    <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-700">
                        {{ __('admin.geo_monitoring.button_save') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
@endsection
