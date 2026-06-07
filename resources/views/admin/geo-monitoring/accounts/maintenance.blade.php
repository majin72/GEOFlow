@extends('admin.layouts.app')

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        @include('admin.geo-monitoring.partials.subnav')

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $guide['runtime_description'] }}</p>
                <p class="mt-2 inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">{{ $guide['runtime_label'] }}</p>
            </div>
            <a href="{{ route('admin.geo-monitoring.accounts.index') }}" class="text-sm text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.maintenance_back_accounts') }}</a>
        </div>

        @if (session('message'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('message') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-5">
                    <h2 class="text-sm font-semibold text-amber-900">{{ __('admin.geo_monitoring.maintenance_account_summary') }}</h2>
                    <dl class="mt-3 grid gap-2 text-sm text-amber-900/90 sm:grid-cols-2">
                        <div><dt class="font-medium">{{ __('admin.geo_monitoring.field_platform') }}</dt><dd>{{ $account->platform->label }} ({{ $account->platform->code }})</dd></div>
                        <div><dt class="font-medium">{{ __('admin.geo_monitoring.field_external_id') }}</dt><dd class="font-mono">{{ $account->external_id }}</dd></div>
                        <div><dt class="font-medium">{{ __('admin.geo_monitoring.field_status') }}</dt><dd>{{ $account->status }}</dd></div>
                        <div><dt class="font-medium">{{ __('admin.geo_monitoring.field_profile_path') }}</dt><dd class="font-mono text-xs">{{ $guide['profile_path'] }}</dd></div>
                        @if ($guide['proxy_label'])
                            <div><dt class="font-medium">{{ __('admin.geo_monitoring.maintenance_proxy') }}</dt><dd>{{ $guide['proxy_label'] }}</dd></div>
                        @endif
                        @if ($account->last_error_message)
                            <div class="sm:col-span-2"><dt class="font-medium">{{ __('admin.geo_monitoring.maintenance_last_error') }}</dt><dd class="text-red-700">{{ $account->last_error_message }}</dd></div>
                        @endif
                    </dl>
                </div>

                @if ($guide['supports_interactive'] ?? false)
                    <div class="rounded-lg border-2 border-blue-200 bg-gradient-to-br from-blue-50 to-white p-6 shadow-sm">
                        @php($isNovncInteractive = ($guide['runtime_mode'] ?? '') === 'headless_linux')
                        <h2 class="text-lg font-semibold text-gray-900">
                            {{ $isNovncInteractive
                                ? __('admin.geo_monitoring.maintenance_interactive_title_novnc')
                                : __('admin.geo_monitoring.maintenance_interactive_title') }}
                        </h2>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ $isNovncInteractive
                                ? __('admin.geo_monitoring.maintenance_interactive_desc_novnc')
                                : __('admin.geo_monitoring.maintenance_interactive_desc') }}
                        </p>

                        @if ($isNovncInteractive && ! empty($guide['ssh_tunnel_command']))
                            <div class="mt-4 space-y-3 rounded-md border border-blue-200 bg-white p-4">
                                <div>
                                    <div class="text-sm font-medium text-gray-800">{{ __('admin.geo_monitoring.maintenance_ssh_tunnel_label') }}</div>
                                    <pre class="mt-2 overflow-x-auto rounded-md bg-gray-900 p-3 text-xs text-gray-100"><code>{{ $guide['ssh_tunnel_command'] }}</code></pre>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-800">{{ __('admin.geo_monitoring.maintenance_novnc_url') }}</div>
                                    <a href="{{ $guide['novnc_local_url'] ?? '#' }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block font-mono text-sm text-blue-700 underline hover:text-blue-900">
                                        {{ $guide['novnc_local_url'] ?? '' }}
                                    </a>
                                    <p class="mt-2 text-xs text-gray-500">{{ __('admin.geo_monitoring.maintenance_security_hint') }}</p>
                                </div>
                            </div>
                        @endif

                        <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm text-gray-700">
                            @foreach ($guide['steps'] as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ol>

                        @if (is_array($interactiveSession) && ! empty($interactiveSession['session_id']))
                            <div class="mt-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                <div class="font-medium">{{ __('admin.geo_monitoring.maintenance_browser_open') }}</div>
                                <div class="mt-1 text-xs text-emerald-800">
                                    {{ $isNovncInteractive
                                        ? __('admin.geo_monitoring.maintenance_browser_open_hint_novnc')
                                        : __('admin.geo_monitoring.maintenance_browser_open_hint') }}
                                </div>
                                @if (! empty($interactiveSession['chat_url']))
                                    <div class="mt-2 font-mono text-xs break-all">{{ $interactiveSession['chat_url'] }}</div>
                                @endif
                            </div>
                            <form method="post" action="{{ route('admin.geo-monitoring.accounts.maintenance.save-browser', ['accountId' => $account->id]) }}" class="mt-4">
                                @csrf
                                <input type="hidden" name="session_id" value="{{ $interactiveSession['session_id'] }}">
                                <button type="submit" class="w-full rounded-md bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">
                                    {{ __('admin.geo_monitoring.maintenance_save_profile_button') }}
                                </button>
                            </form>
                        @else
                            <form method="post" action="{{ route('admin.geo-monitoring.accounts.maintenance.launch-browser', ['accountId' => $account->id]) }}" class="mt-5">
                                @csrf
                                <input type="hidden" name="trigger_reason" value="{{ $guide['trigger_reason'] }}">
                                <button type="submit" class="w-full rounded-md bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                                    {{ __('admin.geo_monitoring.maintenance_launch_browser_button') }}
                                </button>
                            </form>
                        @endif
                    </div>
                @else
                    <div class="rounded-lg bg-white p-6 shadow">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.geo_monitoring.maintenance_steps_title') }}</h2>
                        <ol class="mt-4 list-decimal space-y-4 pl-5 text-sm text-gray-700">
                            @foreach ($guide['steps'] as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ol>
                    </div>
                @endif

                @if (! ($guide['supports_interactive'] ?? false))
                    @foreach ($guide['command_blocks'] as $label => $command)
                        <div class="rounded-lg bg-white p-5 shadow">
                            <div class="mb-2 text-sm font-medium text-gray-700">{{ $label }}</div>
                            <pre class="overflow-x-auto rounded-md bg-gray-900 p-4 text-xs text-gray-100"><code>{{ $command }}</code></pre>
                        </div>
                    @endforeach
                @endif

                <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                    @if (($guide['runtime_mode'] ?? '') === 'headless_linux' && ! ($guide['supports_interactive'] ?? false))
                        <div class="font-medium">{{ __('admin.geo_monitoring.maintenance_novnc_url') }}</div>
                        <div class="mt-1 font-mono">{{ $guide['novnc_local_url'] ?? '' }}</div>
                        <div class="mt-2 text-xs text-blue-800">{{ __('admin.geo_monitoring.maintenance_security_hint') }}</div>
                    @elseif (($guide['runtime_mode'] ?? '') !== 'headless_linux')
                        <div class="font-medium">{{ __('admin.geo_monitoring.maintenance_headed_hint_title') }}</div>
                        <div class="mt-2 text-xs text-blue-800">{{ __('admin.geo_monitoring.maintenance_headed_hint') }}</div>
                    @endif
                    <div class="mt-3 text-xs text-blue-800">{{ $guide['sync_accounts_hint'] }}</div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-lg bg-white p-5 shadow">
                    <h2 class="text-base font-semibold text-gray-900">{{ __('admin.geo_monitoring.maintenance_actions') }}</h2>
                    <div class="mt-4 space-y-3">
                        <form method="post" action="{{ route('admin.geo-monitoring.accounts.maintenance.start', ['accountId' => $account->id]) }}">
                            @csrf
                            <input type="hidden" name="trigger_reason" value="{{ $guide['trigger_reason'] }}">
                            <button type="submit" class="w-full rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                                {{ __('admin.geo_monitoring.maintenance_begin_button') }}
                            </button>
                        </form>
                        <form method="post" action="{{ route('admin.geo-monitoring.accounts.maintenance.health', ['accountId' => $account->id]) }}">
                            @csrf
                            <button type="submit" class="w-full rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                {{ __('admin.geo_monitoring.maintenance_health_button') }}
                            </button>
                        </form>
                        <form method="post" action="{{ route('admin.geo-monitoring.accounts.maintenance.complete', ['accountId' => $account->id]) }}" class="space-y-3 border-t border-gray-100 pt-3">
                            @csrf
                            <p class="text-xs text-gray-500">{{ __('admin.geo_monitoring.maintenance_complete_requires_health') }}</p>
                            <label class="block text-sm text-gray-700">
                                <span class="font-medium">{{ __('admin.geo_monitoring.maintenance_complete_notes') }}</span>
                                <textarea name="notes" rows="3" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                            </label>
                            <button type="submit" class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                {{ __('admin.geo_monitoring.maintenance_complete_button') }}
                            </button>
                        </form>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-5 shadow">
                    <h2 class="text-base font-semibold text-gray-900">{{ __('admin.geo_monitoring.maintenance_events_title') }}</h2>
                    @if ($events->isEmpty())
                        <p class="mt-3 text-sm text-gray-500">{{ __('admin.geo_monitoring.maintenance_events_empty') }}</p>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100">
                            @foreach ($events as $event)
                                <li class="py-3 text-xs text-gray-600">
                                    <div class="font-medium text-gray-800">{{ $event->trigger_reason }} · {{ $event->status }} · {{ $event->maintenance_via }}</div>
                                    <div>{{ $event->started_at?->format('Y-m-d H:i') }} @if ($event->finished_at) → {{ $event->finished_at->format('H:i') }} @endif</div>
                                    @if ($event->notes)
                                        <div class="mt-1 text-gray-500">{{ $event->notes }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
