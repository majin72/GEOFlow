@extends('admin.layouts.app')

@push('styles')
<style>
    #admin-ai-ops-page .ai-ops-md h1,
    #admin-ai-ops-page .ai-ops-md h2,
    #admin-ai-ops-page .ai-ops-md h3 {
        margin-top: 1rem;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: rgb(15 23 42);
    }
    #admin-ai-ops-page .ai-ops-md h1 { font-size: 1.125rem; }
    #admin-ai-ops-page .ai-ops-md h2 { font-size: 1rem; }
    #admin-ai-ops-page .ai-ops-md h3 { font-size: 0.9375rem; }
    #admin-ai-ops-page .ai-ops-md p { margin-bottom: 0.625rem; }
    #admin-ai-ops-page .ai-ops-md ul,
    #admin-ai-ops-page .ai-ops-md ol {
        margin: 0.5rem 0 0.75rem 1.25rem;
        list-style-position: outside;
    }
    #admin-ai-ops-page .ai-ops-md ul { list-style-type: disc; }
    #admin-ai-ops-page .ai-ops-md ol { list-style-type: decimal; }
    #admin-ai-ops-page .ai-ops-md table {
        width: 100%;
        margin: 0.75rem 0;
        border-collapse: collapse;
        font-size: 0.8125rem;
    }
    #admin-ai-ops-page .ai-ops-md th,
    #admin-ai-ops-page .ai-ops-md td {
        border: 1px solid rgb(226 232 240);
        padding: 0.375rem 0.625rem;
        text-align: left;
        vertical-align: top;
    }
    #admin-ai-ops-page .ai-ops-md th {
        background: rgb(248 250 252);
        font-weight: 600;
    }
    #admin-ai-ops-page .ai-ops-md code {
        font-size: 0.8125em;
        background: rgb(241 245 249);
        padding: 0.1em 0.35em;
        border-radius: 0.25rem;
    }
    #admin-ai-ops-page .ai-ops-md pre {
        margin: 0.5rem 0;
        overflow-x: auto;
        border-radius: 0.5rem;
        background: rgb(15 23 42);
        color: rgb(241 245 249);
        padding: 0.75rem;
        font-size: 0.75rem;
    }
    #admin-ai-ops-page .ai-ops-md pre code {
        background: transparent;
        padding: 0;
        color: inherit;
    }
    #admin-ai-ops-page .ai-ops-md blockquote {
        border-left: 3px solid rgb(199 210 254);
        padding-left: 0.75rem;
        color: rgb(71 85 105);
        margin: 0.5rem 0;
    }
    #admin-ai-ops-page .ai-ops-tool-details[open] > summary {
        border-bottom: 1px solid rgb(0 0 0 / 0.05);
    }
    #admin-ai-ops-page .ai-ops-session-search-icon svg {
        width: 1rem;
        height: 1rem;
        stroke: currentColor;
    }
</style>
@endpush

@section('content')
<div id="admin-ai-ops-page"
     class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
     data-chat-url="{{ route('admin.ai-ops.chat') }}"
     data-stream-url-template="{{ route('admin.ai-ops.runs.stream', ['runId' => '__RUN_ID__']) }}"
     data-sessions-url="{{ route('admin.ai-ops.sessions.index') }}"
     data-session-store-url="{{ route('admin.ai-ops.sessions.store') }}"
     data-session-url-template="{{ route('admin.ai-ops.sessions.show', ['sessionId' => '__SESSION_ID__']) }}">
    <div class="flex min-h-[calc(100vh-10rem)] flex-col lg:flex-row">
        <aside class="w-full border-b border-gray-200 bg-slate-50/90 lg:w-80 lg:border-b-0 lg:border-r">
            <div class="flex items-start justify-between gap-3 border-b border-gray-200/80 p-4 sm:items-center">
                <div class="min-w-0 flex-1 pr-1">
                    <h1 class="text-lg font-semibold tracking-tight text-slate-900">{{ __('admin.ai_ops.page_title') }}</h1>
                </div>
                <button type="button" id="ai-ops-new-session" class="inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800">
                    {{ __('admin.ai_ops.new_session') }}
                </button>
            </div>
            <div class="px-4 pb-3">
                <label for="ai-ops-session-filter" class="sr-only">{{ __('admin.ai_ops.search_sessions') }}</label>
                <div class="relative">
                    <span class="ai-ops-session-search-icon pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400" aria-hidden="true">
                        <i data-lucide="search"></i>
                    </span>
                    <input
                        id="ai-ops-session-filter"
                        type="text"
                        autocomplete="off"
                        class="block w-full rounded-xl border border-slate-200/90 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 transition focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                        placeholder="{{ __('admin.ai_ops.search_sessions') }}"
                    >
                </div>
            </div>
            <div id="ai-ops-session-list" class="max-h-[28rem] space-y-1.5 overflow-y-auto px-4 pb-4 lg:max-h-[calc(100vh-18rem)]"></div>
        </aside>

        <section class="flex min-w-0 flex-1 flex-col bg-gradient-to-b from-white to-slate-50/40">
            <header class="flex flex-col gap-3 border-b border-gray-200/80 bg-white/80 p-4 backdrop-blur-sm sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div id="ai-ops-current-title" class="truncate text-base font-semibold text-slate-900">{{ __('admin.ai_ops.empty_title') }}</div>
                    <div id="ai-ops-current-status" class="mt-1 text-xs text-slate-500">{{ __('admin.ai_ops.status_idle') }}</div>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <select id="ai-ops-model" class="rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900" @if($models->isEmpty()) disabled @endif>
                        @forelse ($models as $model)
                            <option value="{{ $model->id }}" @if($loop->first) selected @endif>{{ $model->name }} / {{ $model->model_id }}</option>
                        @empty
                            <option value="">{{ __('admin.ai_ops.model_auto') }}</option>
                        @endforelse
                    </select>
                    <button type="button" id="ai-ops-refresh" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                        {{ __('admin.ai_ops.refresh') }}
                    </button>
                </div>
            </header>

            <div id="ai-ops-messages" class="flex-1 space-y-6 overflow-y-auto p-4 lg:max-h-[calc(100vh-20rem)]">
                <div class="rounded-2xl border border-dashed border-slate-200 bg-white/60 p-10 text-center text-sm text-slate-500 shadow-inner">
                    {{ __('admin.ai_ops.empty_state') }}
                </div>
            </div>

            <form id="ai-ops-form" class="border-t border-gray-200/80 bg-white/90 p-4 backdrop-blur-sm">
                <div id="ai-ops-model-warning" class="@if($models->isNotEmpty()) hidden @endif mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {{ __('admin.ai_ops.model_required') }}
                </div>
                <div class="relative flex flex-col gap-3">
                    <div id="ai-ops-composer-loading" class="pointer-events-none absolute inset-0 z-10 hidden flex-col items-center justify-center gap-2 rounded-xl bg-white/85 backdrop-blur-[2px]" aria-live="polite" aria-busy="false">
                        <svg class="h-8 w-8 animate-spin text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-xs font-medium text-slate-600">{{ __('admin.ai_ops.composer_processing') }}</span>
                    </div>
                    @php
                        $webSearchComposerDisabled = $models->isEmpty() || ! ($webSearchKeyConfigured ?? false);
                    @endphp
                    <div class="flex flex-col gap-1.5">
                        <label class="@if($webSearchComposerDisabled) cursor-not-allowed opacity-60 @else cursor-pointer @endif inline-flex items-center gap-2 text-xs text-slate-600" for="ai-ops-network-mode" title="{{ __('admin.ai_ops.network_mode_hint') }}">
                            <input type="checkbox" id="ai-ops-network-mode" name="web_search_enabled" value="1" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-600 disabled:cursor-not-allowed disabled:opacity-50" @if($webSearchComposerDisabled) disabled @endif>
                            <span class="font-medium text-slate-700">{{ __('admin.ai_ops.network_mode') }}</span>
                        </label>
                        @unless($webSearchKeyConfigured ?? false)
                            <p id="ai-ops-network-mode-hint" class="text-xs leading-relaxed text-amber-800">
                                {{ __('admin.ai_ops.network_mode_unconfigured') }}
                                <a href="{{ $articleSearchSettingsUrl ?? route('admin.site-settings.article-search') }}" class="font-medium text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900">{{ __('admin.ai_ops.network_mode_configure_link') }}</a>
                            </p>
                        @endunless
                    </div>
                    <textarea id="ai-ops-message" name="message" rows="3" class="w-full resize-none rounded-xl border-slate-200 bg-white text-sm leading-6 shadow-sm focus:border-indigo-600 focus:ring-indigo-600" placeholder="{{ __('admin.ai_ops.placeholder') }}" @if($models->isEmpty()) disabled @endif></textarea>
                    <div class="flex justify-end">
                        <button type="submit" id="ai-ops-submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300" @if($models->isEmpty()) disabled @endif>
                            {{ __('admin.ai_ops.submit_message') }}
                        </button>
                    </div>
                </div>
            </form>
        </section>
    </div>
</div>

<div id="ai-ops-tool-approval-modal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-900/45 p-4 backdrop-blur-[1px]" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
        <h2 class="text-lg font-semibold text-slate-900">{{ __('admin.ai_ops.tool_approval_title') }}</h2>
        <div class="mt-4 space-y-3 text-sm text-slate-700">
            <div><span class="font-medium text-slate-500">{{ __('admin.ai_ops.tool_called') }}</span> <code id="ai-ops-tool-approval-tool" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-900"></code></div>
            <div id="ai-ops-tool-approval-summary" class="leading-relaxed"></div>
            <div class="text-xs text-slate-500"><span class="font-medium">{{ __('admin.ai_ops.tool_approval_expires') }}</span> <span id="ai-ops-tool-approval-expires"></span></div>
            <div class="text-xs text-slate-500"><span class="font-medium">{{ __('admin.ai_ops.tool_approval_fingerprint') }}</span> <code id="ai-ops-tool-approval-fp" class="break-all text-[11px] text-slate-700"></code></div>
            <label class="block text-xs font-medium text-slate-600" for="ai-ops-tool-approval-reason">{{ __('admin.ai_ops.tool_reject_reason_placeholder') }}</label>
            <textarea id="ai-ops-tool-approval-reason" rows="2" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-600 focus:ring-indigo-600"></textarea>
        </div>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <button type="button" id="ai-ops-tool-approval-reject-btn" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-medium text-rose-800 shadow-sm hover:bg-rose-100">{{ __('admin.ai_ops.tool_reject') }}</button>
            <button type="button" id="ai-ops-tool-approval-approve-btn" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">{{ __('admin.ai_ops.tool_approve') }}</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@php
    $aiOpsConfig = [
        'webSearchKeyConfigured' => (bool) ($webSearchKeyConfigured ?? false),
        'articleSearchSettingsUrl' => $articleSearchSettingsUrl ?? route('admin.site-settings.article-search'),
        'urls' => [
            'chat' => route('admin.ai-ops.chat'),
            'stream' => route('admin.ai-ops.runs.stream', ['runId' => '__RUN_ID__']),
            'sessions' => route('admin.ai-ops.sessions.index'),
            'sessionStore' => route('admin.ai-ops.sessions.store'),
            'session' => route('admin.ai-ops.sessions.show', ['sessionId' => '__SESSION_ID__']),
            'sessionDestroy' => route('admin.ai-ops.sessions.destroy', ['sessionId' => '__SESSION_ID__']),
            'streamUrlTemplate' => route('admin.ai-ops.runs.stream', ['runId' => '__RUN_ID__']),
        ],
        'text' => [
            'networkError' => __('admin.ai_ops.network_error'),
            'emptyState' => __('admin.ai_ops.empty_state'),
            'modelRequired' => __('admin.ai_ops.model_required'),
            'noSession' => __('admin.ai_ops.empty_title'),
            'deleteSession' => __('admin.ai_ops.delete_session'),
            'confirmDeleteSession' => __('admin.ai_ops.confirm_delete_session'),
            'idle' => __('admin.ai_ops.status_idle'),
            'userInput' => __('admin.ai_ops.user_input'),
            'aiReply' => __('admin.ai_ops.ai_reply'),
            'aiReplyWaiting' => __('admin.ai_ops.ai_reply_waiting'),
            'streamPending' => __('admin.ai_ops.stream_pending'),
            'streamConnected' => __('admin.ai_ops.stream_connected'),
            'postToolsModelWait' => __('admin.ai_ops.post_tools_model_wait'),
            'toolCalled' => __('admin.ai_ops.tool_called'),
            'toolPhaseCalling' => __('admin.ai_ops.tool_phase_calling'),
            'toolPhaseExecuting' => __('admin.ai_ops.tool_phase_executing'),
            'toolPhaseAwaitingApproval' => __('admin.ai_ops.tool_phase_awaiting_approval'),
            'toolPhaseRejected' => __('admin.ai_ops.tool_phase_rejected'),
            'toolPhaseDone' => __('admin.ai_ops.tool_phase_done'),
            'toolPhaseFailed' => __('admin.ai_ops.tool_phase_failed'),
            'toolArgsLabel' => __('admin.ai_ops.tool_args_label'),
            'toolRawOutput' => __('admin.ai_ops.tool_raw_output'),
            'toolResultPreview' => __('admin.ai_ops.tool_result_preview'),
            'toolPendingApprovalResult' => __('admin.ai_ops.tool_pending_approval_result_preview'),
            'toolSiblingCancelledOnApproval' => __('admin.ai_ops.tool_sibling_cancelled_on_approval'),
            'toolApprovalTitle' => __('admin.ai_ops.tool_approval_title'),
            'toolApprovalBanner' => __('admin.ai_ops.tool_approval_banner'),
            'toolApprovalExpires' => __('admin.ai_ops.tool_approval_expires'),
            'toolApprovalFingerprint' => __('admin.ai_ops.tool_approval_fingerprint'),
            'toolApprovalPartial' => __('admin.ai_ops.tool_approval_partial'),
            'toolApprove' => __('admin.ai_ops.tool_approve'),
            'toolReject' => __('admin.ai_ops.tool_reject'),
            'toolApprovalOpen' => __('admin.ai_ops.tool_approval_open'),
            'statuses' => [
                'processing' => __('admin.ai_ops.status_processing'),
                'queued' => __('admin.ai_ops.status_queued'),
                'planning' => __('admin.ai_ops.status_planning'),
                'awaiting_confirmation' => __('admin.ai_ops.status_awaiting_confirmation'),
                'running' => __('admin.ai_ops.status_running'),
                'cancelling' => __('admin.ai_ops.status_cancelling'),
                'cancelled' => __('admin.ai_ops.status_cancelled'),
                'completed' => __('admin.ai_ops.status_completed'),
                'failed' => __('admin.ai_ops.status_failed'),
            ],
        ],
    ];
@endphp
<script type="application/json" id="ai-ops-config">@json($aiOpsConfig)</script>
@vite(['resources/js/admin-ai-ops.js'])
@endpush
