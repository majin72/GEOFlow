@extends('admin.layouts.app')

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
                    <p class="mt-1 text-xs leading-relaxed text-slate-500">{{ __('admin.ai_ops.page_subtitle') }}</p>
                </div>
                <button type="button" id="ai-ops-new-session" class="inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800">
                    {{ __('admin.ai_ops.new_session') }}
                </button>
            </div>
            <div class="p-3">
                <input id="ai-ops-session-filter" type="search" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900" placeholder="{{ __('admin.ai_ops.search_sessions') }}">
            </div>
            <div id="ai-ops-session-list" class="max-h-[28rem] space-y-1.5 overflow-y-auto px-3 pb-4 lg:max-h-[calc(100vh-18rem)]"></div>
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
            <button type="button" id="ai-ops-tool-approval-close" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">{{ __('admin.ai_ops.tool_approval_later') }}</button>
            <button type="button" id="ai-ops-tool-approval-reject-btn" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-medium text-rose-800 shadow-sm hover:bg-rose-100">{{ __('admin.ai_ops.tool_reject') }}</button>
            <button type="button" id="ai-ops-tool-approval-approve-btn" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">{{ __('admin.ai_ops.tool_approve') }}</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@php
    $aiOpsText = [
        'networkError' => __('admin.ai_ops.network_error'),
        'emptyState' => __('admin.ai_ops.empty_state'),
        'modelRequired' => __('admin.ai_ops.model_required'),
        'noSession' => __('admin.ai_ops.empty_title'),
        'idle' => __('admin.ai_ops.status_idle'),
        'userInput' => __('admin.ai_ops.user_input'),
        'aiReply' => __('admin.ai_ops.ai_reply'),
        'aiReplyWaiting' => __('admin.ai_ops.ai_reply_waiting'),
        'streamPending' => __('admin.ai_ops.stream_pending'),
        'streamConnected' => __('admin.ai_ops.stream_connected'),
        'postToolsModelWait' => __('admin.ai_ops.post_tools_model_wait'),
        'toolCalled' => __('admin.ai_ops.tool_called'),
        'toolPhaseCalling' => __('admin.ai_ops.tool_phase_calling'),
        'toolPhaseDone' => __('admin.ai_ops.tool_phase_done'),
        'toolPhaseFailed' => __('admin.ai_ops.tool_phase_failed'),
        'toolArgsLabel' => __('admin.ai_ops.tool_args_label'),
        'toolResultPreview' => __('admin.ai_ops.tool_result_preview'),
        'toolPendingApprovalResult' => __('admin.ai_ops.tool_pending_approval_result_preview'),
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
    ];
@endphp
<script>
    (function () {
        const root = document.getElementById('admin-ai-ops-page');
        if (!root) return;

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const text = @json($aiOpsText);

        const urls = {
            chat: root.dataset.chatUrl,
            stream: (id) => root.dataset.streamUrlTemplate.replace('__RUN_ID__', id),
            sessions: root.dataset.sessionsUrl,
            sessionStore: root.dataset.sessionStoreUrl,
            session: (id) => root.dataset.sessionUrlTemplate.replace('__SESSION_ID__', id),
        };

        const els = {
            sessions: document.getElementById('ai-ops-session-list'),
            filter: document.getElementById('ai-ops-session-filter'),
            newSession: document.getElementById('ai-ops-new-session'),
            refresh: document.getElementById('ai-ops-refresh'),
            title: document.getElementById('ai-ops-current-title'),
            status: document.getElementById('ai-ops-current-status'),
            composerLoading: document.getElementById('ai-ops-composer-loading'),
            messages: document.getElementById('ai-ops-messages'),
            model: document.getElementById('ai-ops-model'),
            form: document.getElementById('ai-ops-form'),
            message: document.getElementById('ai-ops-message'),
            submit: document.getElementById('ai-ops-submit'),
        };

        let state = {
            sessions: [],
            currentSession: null,
            eventSource: null,
            activeStreamRunId: null,
            liveByRunId: {},
        };

        function ensureLiveStruct(runId) {
            const id = String(runId);
            let v = state.liveByRunId[id];
            if (typeof v === 'string') {
                v = {
                    text: v,
                    waves: [],
                    activeWave: null,
                    completedRounds: [],
                    streamPending: false,
                    streamConnected: false,
                    awaitingModelAfterTools: false,
                };
                state.liveByRunId[id] = v;
            } else if (!v || typeof v !== 'object' || !Array.isArray(v.waves)) {
                v = {
                    text: '',
                    waves: [],
                    activeWave: null,
                    completedRounds: [],
                    streamPending: true,
                    streamConnected: false,
                    awaitingModelAfterTools: false,
                };
                state.liveByRunId[id] = v;
            } else if (!Array.isArray(v.completedRounds)) {
                v.completedRounds = [];
            }
            return v;
        }

        function normalizeLiveSnapshot(runId) {
            const raw = state.liveByRunId[String(runId)];
            if (!raw) {
                return {
                    text: '',
                    waves: [],
                    activeWave: null,
                    completedRounds: [],
                    streamPending: false,
                    streamConnected: false,
                    awaitingModelAfterTools: false,
                };
            }
            if (typeof raw === 'string') {
                return {
                    text: raw,
                    waves: [],
                    activeWave: null,
                    completedRounds: [],
                    streamPending: false,
                    streamConnected: false,
                    awaitingModelAfterTools: false,
                };
            }
            return {
                text: String(raw.text || ''),
                waves: Array.isArray(raw.waves) ? raw.waves.map((w) => ({
                    end: Number(w.end) || 0,
                    tools: Array.isArray(w.tools) ? w.tools.map((t) => ({ ...t })) : [],
                })) : [],
                activeWave: raw.activeWave && typeof raw.activeWave === 'object'
                    ? {
                        end: Number(raw.activeWave.end) || 0,
                        tools: Array.isArray(raw.activeWave.tools)
                            ? raw.activeWave.tools.map((t) => ({ ...t }))
                            : [],
                    }
                    : null,
                completedRounds: Array.isArray(raw.completedRounds)
                    ? raw.completedRounds.map((seg) => ({
                        text: String(seg.text || ''),
                        waves: Array.isArray(seg.waves) ? seg.waves.map((w) => ({
                            end: Number(w.end) || 0,
                            tools: Array.isArray(w.tools) ? w.tools.map((t) => ({ ...t })) : [],
                        })) : [],
                    }))
                    : [],
                streamPending: !!raw.streamPending,
                streamConnected: !!raw.streamConnected,
                awaitingModelAfterTools: !!raw.awaitingModelAfterTools,
            };
        }

        /**
         * 收集当前缓冲区内所有工具行（含已完成波次与进行中的波次），用于状态判断。
         *
         * @param {object} live
         * @returns {Array<object>}
         */
        function collectLiveToolsFlat(live) {
            const out = [];
            (live.waves || []).forEach((w) => {
                (w.tools || []).forEach((t) => out.push(t));
            });
            if (live.activeWave && Array.isArray(live.activeWave.tools)) {
                live.activeWave.tools.forEach((t) => out.push(t));
            }
            return out;
        }

        /**
         * 渲染一组工具卡片（同一轮次内可多个并行工具）。
         *
         * @param {Array<object>} tools
         * @returns {string}
         */
        function renderAiOpsToolGroupHtml(tools) {
            if (!tools.length) {
                return '';
            }
            const parts = ['<div class="mb-3 space-y-2 rounded-xl border border-slate-200 bg-slate-50/90 p-3 text-xs shadow-inner">'];
            tools.forEach((t) => {
                const isCalling = t.phase === 'calling';
                const failed = t.phase === 'done' && t.successful === false;
                const label = isCalling ? text.toolPhaseCalling : (failed ? text.toolPhaseFailed : text.toolPhaseDone);
                const tone = isCalling
                    ? 'border-amber-200 bg-amber-50/90 text-amber-900'
                    : (failed ? 'border-rose-200 bg-rose-50/90 text-rose-900' : 'border-emerald-200 bg-emerald-50/90 text-emerald-900');
                parts.push(`<div class="rounded-lg border ${tone} px-2.5 py-2">`);
                parts.push('<div class="flex flex-wrap items-center gap-2 font-medium">');
                parts.push(`<span>${escapeHtml(text.toolCalled)}</span>`);
                parts.push(`<code class="rounded bg-white/80 px-1.5 py-0.5 text-[11px] text-slate-800">${escapeHtml(t.name)}</code>`);
                parts.push(`<span class="text-[11px] font-normal opacity-80">${escapeHtml(label)}</span>`);
                parts.push('</div>');
                const preview = String(t.preview || '');
                if (preview) {
                    parts.push(`<div class="mt-1.5 text-[11px] text-slate-600">${escapeHtml(text.toolArgsLabel)}</div>`);
                    parts.push(`<pre class="mt-1 max-h-36 overflow-auto rounded-md bg-white/95 p-2 text-[11px] leading-snug text-slate-700">${escapeHtml(preview)}</pre>`);
                }
                if (t.phase === 'done' && t.error) {
                    parts.push(`<div class="mt-1 text-[11px] text-rose-700">${escapeHtml(t.error)}</div>`);
                }
                const rp = String(t.resultPreview || '');
                if (t.phase === 'done' && rp) {
                    parts.push(`<div class="mt-1.5 text-[11px] font-medium text-slate-600">${escapeHtml(text.toolResultPreview)}</div>`);
                    parts.push(`<pre class="mt-1 max-h-40 overflow-auto rounded-md bg-slate-100/95 p-2 text-[11px] leading-snug text-slate-800">${escapeHtml(rp)}</pre>`);
                }
                parts.push('</div>');
            });
            parts.push('</div>');
            return parts.join('');
        }

        /**
         * 深拷贝一波工具元数据（归档多段输出时用）。
         *
         * @param {object} w
         * @returns {object} 含 end:number 与 tools:object[]
         */
        function cloneAiOpsWave(w) {
            return {
                end: Number(w.end) || 0,
                tools: Array.isArray(w.tools) ? w.tools.map((t) => ({ ...t })) : [],
            };
        }

        /**
         * 续流前若内存缓冲丢失，用会话里已落库的 partial 预览兜底，避免首轮正文整块消失。
         *
         * @param {number|string} runId
         * @returns {Array<{ text: string, waves: Array<object> }>}
         */
        function seedCompletedRoundsFromSessionRun(runId) {
            const run = state.currentSession?.runs?.find((r) => Number(r.id) === Number(runId));
            if (!run) {
                return [];
            }
            const partial = String(run.assistant_partial_preview || '').trim();
            if (!partial) {
                return [];
            }
            return [{ text: partial, waves: [] }];
        }

        /**
         * 将上一轮 EventSource 缓冲（含 waves / activeWave / completedRounds）转为续流用的 completedRounds 初始值。
         *
         * @param {object|null} prevLive
         * @returns {Array<{ text: string, waves: Array<object> }>}
         */
        function buildCompletedRoundsFromPriorLive(prevLive) {
            if (!prevLive || typeof prevLive !== 'object') {
                return [];
            }
            const out = [];
            if (Array.isArray(prevLive.completedRounds)) {
                prevLive.completedRounds.forEach((seg) => {
                    out.push({
                        text: String(seg.text || ''),
                        waves: (seg.waves || []).map((w) => cloneAiOpsWave(w)),
                    });
                });
            }
            const wavesSnap = (prevLive.waves || []).map((w) => cloneAiOpsWave(w));
            if (prevLive.activeWave && prevLive.activeWave.tools && prevLive.activeWave.tools.length) {
                wavesSnap.push(cloneAiOpsWave(prevLive.activeWave));
            }
            const txt = String(prevLive.text || '');
            if (txt.trim() || wavesSnap.length) {
                out.push({ text: txt, waves: wavesSnap });
            }
            return out;
        }

        /**
         * 根据单段全文与 waves（可选当前 activeWave）生成时间线 HTML：正文片段 → 工具组 → 尾部正文。
         *
         * @param {string} fullText
         * @param {Array<object>} waves
         * @param {object|null} activeWave
         * @returns {string}
         */
        function renderAiOpsTimelineInnerHtml(fullText, waves, activeWave) {
            const chunks = [];
            const ft = String(fullText || '');
            const wv = Array.isArray(waves) ? waves : [];
            const aw = activeWave && typeof activeWave === 'object' ? activeWave : null;

            let pos = 0;
            wv.forEach((w) => {
                const end = Number(w.end) || 0;
                const slice = ft.slice(pos, end);
                if (slice.trim()) {
                    chunks.push(`<div class="mb-2 whitespace-pre-wrap break-words text-sm leading-relaxed">${escapeHtml(slice)}</div>`);
                }
                chunks.push(renderAiOpsToolGroupHtml(w.tools || []));
                pos = end;
            });

            if (aw && Array.isArray(aw.tools) && aw.tools.length) {
                const end = Number(aw.end) || 0;
                const slice = ft.slice(pos, end);
                if (slice.trim()) {
                    chunks.push(`<div class="mb-2 whitespace-pre-wrap break-words text-sm leading-relaxed">${escapeHtml(slice)}</div>`);
                }
                chunks.push(renderAiOpsToolGroupHtml(aw.tools));
                pos = end;
            }

            const tail = ft.slice(pos);
            if (tail.trim()) {
                chunks.push(`<div class="whitespace-pre-wrap break-words text-sm leading-relaxed">${escapeHtml(tail)}</div>`);
            }

            return chunks.join('');
        }

        /**
         * 合并已归档轮次与当前缓冲中的助手纯文本（用于 run 落库兜底）。
         *
         * @param {object} live
         * @returns {string}
         */
        function liveFullAssistantPlainText(live) {
            const parts = [];
            (live.completedRounds || []).forEach((seg) => {
                const s = String(seg.text || '').trim();
                if (s) {
                    parts.push(s);
                }
            });
            const cur = String(live.text || '').trim();
            if (cur) {
                parts.push(cur);
            }
            return parts.join('\n\n');
        }

        /**
         * 当检测到新的流式正文段（非前缀延续）时，将当前段与工具时间线归档，避免后一轮顶掉前几轮。
         *
         * @param {object} live
         * @param {string} prevText
         */
        function archiveAssistantSegmentIfNeeded(live, prevText) {
            const prev = String(prevText || '');
            if (prev === '') {
                return;
            }
            const wavesSnap = (live.waves || []).map((w) => cloneAiOpsWave(w));
            if (live.activeWave && live.activeWave.tools && live.activeWave.tools.length) {
                wavesSnap.push(cloneAiOpsWave(live.activeWave));
            }
            if (!Array.isArray(live.completedRounds)) {
                live.completedRounds = [];
            }
            live.completedRounds.push({ text: prev, waves: wavesSnap });
            live.waves = [];
            live.activeWave = null;
        }

        /**
         * 按时间线渲染：助手文本片段 → 紧随其后的工具组 → 后续文本（避免工具块全部堆在顶部）。
         *
         * @param {string} st
         * @param {object} live
         * @returns {string}
         */
        function buildAssistantLiveBodyHtml(st, live) {
            const chunks = [];
            (live.completedRounds || []).forEach((seg) => {
                const inner = renderAiOpsTimelineInnerHtml(seg.text, seg.waves, null);
                if (inner.trim()) {
                    chunks.push(`<div class="mb-5 border-b border-slate-100 pb-5 last:mb-0 last:border-b-0 last:pb-0">${inner}</div>`);
                }
            });

            const curInner = renderAiOpsTimelineInnerHtml(live.text, live.waves, live.activeWave);
            if (curInner.trim()) {
                chunks.push(curInner);
            }

            const flatTools = collectLiveToolsFlat(live);
            const allToolsDone = flatTools.length > 0 && flatTools.every((t) => t.phase === 'done');
            if (
                live.awaitingModelAfterTools &&
                allToolsDone &&
                (st === 'processing' || st === 'queued')
            ) {
                chunks.push(
                    `<p class="mt-2 border-l-2 border-indigo-200 pl-3 text-[11px] leading-relaxed text-slate-600">${escapeHtml(text.postToolsModelWait)}</p>`,
                );
            }

            if (!chunks.length && live.streamConnected) {
                chunks.push(`<p class="mb-2 text-sm text-slate-500">${escapeHtml(text.streamConnected)}</p>`);
            } else if (!chunks.length && live.streamPending && (st === 'processing' || st === 'queued' || st === 'awaiting_confirmation')) {
                chunks.push(`<p class="mb-2 text-sm text-slate-500">${escapeHtml(text.streamPending)}</p>`);
            }

            if (!chunks.length && (st === 'processing' || st === 'queued')) {
                chunks.push(`<p class="text-sm text-slate-400">${escapeHtml(text.aiReplyWaiting)}</p>`);
            }

            return chunks.join('');
        }

        /**
         * 判断当前 run 的实时缓冲是否仍有可展示内容（用于 transcript 分支）。
         *
         * @param {object} live
         */
        function liveHasAssistantStreamContent(live) {
            if ((live.completedRounds || []).length) {
                return true;
            }
            if (String(live.text || '').trim()) {
                return true;
            }
            if ((live.waves || []).length) {
                return true;
            }
            if (live.activeWave && live.activeWave.tools && live.activeWave.tools.length) {
                return true;
            }
            if (live.streamConnected || live.streamPending) {
                return true;
            }
            return false;
        }

        /**
         * 关闭当前 EventSource。不在此处删除 liveByRunId：避免在终态 run 事件尚未合并前进来
         * 的 onerror/重连逻辑把已流式累积的正文清空；终端状态由 run 监听器删除对应缓冲。
         */
        function closeRunEventSource() {
            if (state.eventSource) {
                state.eventSource.close();
                state.eventSource = null;
            }
            state.activeStreamRunId = null;
        }

        const aiOpsHttp = (() => {
            const tpl = root.dataset.streamUrlTemplate || '';
            const m = tpl.match(/^(.*)\/runs\/__RUN_ID__\/stream$/);
            const base = m ? m[1] : '';
            return {
                approveUrl(runId, approvalId) {
                    return base ? `${base}/runs/${runId}/tool-approvals/${approvalId}/approve` : '';
                },
                rejectUrl(runId, approvalId) {
                    return base ? `${base}/runs/${runId}/tool-approvals/${approvalId}/reject` : '';
                },
            };
        })();

        let pendingApprovalCtx = null;

        function showToolApprovalModal(runId, payload) {
            const approvalId = String(payload.id || payload.approval_id || '');
            pendingApprovalCtx = {
                runId: Number(runId),
                approvalId,
                toolName: String(payload.tool_name || ''),
                summary: String(payload.summary || ''),
                expiresAt: String(payload.expires_at || ''),
                fingerprint: String(payload.fingerprint || payload.args_fingerprint || ''),
            };
            const toolEl = document.getElementById('ai-ops-tool-approval-tool');
            if (toolEl) toolEl.textContent = pendingApprovalCtx.toolName;
            const sum = document.getElementById('ai-ops-tool-approval-summary');
            if (sum) sum.textContent = pendingApprovalCtx.summary;
            const ex = document.getElementById('ai-ops-tool-approval-expires');
            if (ex) ex.textContent = pendingApprovalCtx.expiresAt || '—';
            const fp = document.getElementById('ai-ops-tool-approval-fp');
            if (fp) fp.textContent = pendingApprovalCtx.fingerprint || '—';
            const ta = document.getElementById('ai-ops-tool-approval-reason');
            if (ta) ta.value = '';
            const modal = document.getElementById('ai-ops-tool-approval-modal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                modal.setAttribute('aria-hidden', 'false');
            }
        }

        function hideToolApprovalModal() {
            pendingApprovalCtx = null;
            const modal = document.getElementById('ai-ops-tool-approval-modal');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                modal.setAttribute('aria-hidden', 'true');
            }
        }

        function onAiOpsSseFinished() {
            closeRunEventSource();
            setComposerLoading(false);
            loadSessions().catch(() => {});
            if (state.currentSession?.id) {
                selectSession(Number(state.currentSession.id)).catch(() => {});
            }
        }

        /**
         * 审批挂起时 Laravel AI 可能不发 tool/done：将仍为 calling 的卡片收尾，避免界面永久停在「调用中」。
         *
         * @param {object} live
         * @param {string} previewText
         */
        function markCallingToolsAsAwaitingApprovalDone(live, previewText) {
            const pv = String(previewText || '').trim();
            const mark = (t) => {
                if (t && t.phase === 'calling') {
                    t.phase = 'done';
                    t.successful = true;
                    if (pv) {
                        t.resultPreview = pv;
                    }
                }
            };
            (live.waves || []).forEach((w) => (w.tools || []).forEach(mark));
            if (live.activeWave && Array.isArray(live.activeWave.tools)) {
                live.activeWave.tools.forEach(mark);
                if (live.activeWave.tools.length && live.activeWave.tools.every((x) => x.phase === 'done')) {
                    live.waves.push(live.activeWave);
                    live.activeWave = null;
                }
            }
            renderTranscript();
            updateHeaderFromSession();
        }

        function bindAiOpsSseHandlers(es, id) {
            es.addEventListener('approval_required', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    markCallingToolsAsAwaitingApprovalDone(ensureLiveStruct(id), text.toolPendingApprovalResult);
                    showToolApprovalModal(Number(id), {
                        id: data.approval_id,
                        tool_name: data.tool_name,
                        summary: data.summary,
                        expires_at: data.expires_at,
                        fingerprint: data.fingerprint,
                    });
                } catch (err) {
                    console.warn('ai-ops sse approval_required', err);
                }
            });

            es.addEventListener('delta', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    const t = String(data.text || '');
                    const live = ensureLiveStruct(id);
                    const prev = String(live.text || '');
                    const shrinks = t.length < prev.length;
                    const breaksPrefix = prev !== '' && t !== '' && !t.startsWith(prev);
                    const suffixTruncate = shrinks && t !== '' && prev.startsWith(t);
                    const blankBetweenSegments = shrinks && t === '' && prev !== '';
                    const newSegmentAfterTools = breaksPrefix && !suffixTruncate;
                    if ((newSegmentAfterTools || blankBetweenSegments) && prev !== '') {
                        archiveAssistantSegmentIfNeeded(live, prev);
                    }
                    live.text = t;
                    if (live.activeWave && live.activeWave.tools.some((x) => x.phase === 'calling')) {
                        live.activeWave.end = live.text.length;
                    }
                    live.awaitingModelAfterTools = false;
                    if (t) {
                        live.streamPending = false;
                    }
                    renderTranscript();
                    updateHeaderFromSession();
                } catch (err) {
                    console.warn('ai-ops sse delta', err);
                }
            });

            es.addEventListener('stream_status', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    const live = ensureLiveStruct(id);
                    if (data.kind === 'connected') {
                        live.streamConnected = true;
                        live.streamPending = false;
                        live.awaitingModelAfterTools = false;
                        renderTranscript();
                        updateHeaderFromSession();
                        return;
                    }
                    if (data.kind === 'post_tool_model_round') {
                        live.lastSseServerTick = Date.now();
                        renderTranscript();
                    }
                } catch (err) {
                    console.warn('ai-ops sse stream_status', err);
                }
            });

            es.addEventListener('tool', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    const live = ensureLiveStruct(id);
                    if (!Array.isArray(live.waves)) {
                        live.waves = [];
                    }
                    if (data.phase === 'calling') {
                        live.awaitingModelAfterTools = false;
                        if (!live.activeWave) {
                            live.activeWave = { end: live.text.length, tools: [] };
                        }
                        live.activeWave.tools.push({
                            toolCallId: String(data.tool_call_id || ''),
                            name: String(data.tool_name || ''),
                            phase: 'calling',
                            preview: String(data.arguments_preview || ''),
                        });
                    } else if (data.phase === 'done') {
                        const tid = String(data.tool_call_id || '');
                        const rp = data.result_preview != null ? String(data.result_preview) : '';
                        let row = null;
                        if (live.activeWave && Array.isArray(live.activeWave.tools)) {
                            row = [...live.activeWave.tools].reverse().find((x) => x.toolCallId === tid && x.phase === 'calling');
                        }
                        if (!row) {
                            for (let wi = live.waves.length - 1; wi >= 0; wi -= 1) {
                                const w = live.waves[wi];
                                row = [...(w.tools || [])].reverse().find((x) => x.toolCallId === tid && x.phase === 'calling');
                                if (row) {
                                    break;
                                }
                            }
                        }
                        if (row) {
                            row.phase = 'done';
                            row.successful = !!data.successful;
                            row.error = data.error ? String(data.error) : '';
                            if (rp) {
                                row.resultPreview = rp;
                            }
                        } else if (live.activeWave) {
                            live.activeWave.tools.push({
                                toolCallId: tid,
                                name: String(data.tool_name || ''),
                                phase: 'done',
                                successful: !!data.successful,
                                error: data.error ? String(data.error) : '',
                                resultPreview: rp,
                            });
                        }
                        if (live.activeWave && live.activeWave.tools.length
                            && live.activeWave.tools.every((x) => x.phase === 'done')) {
                            live.waves.push(live.activeWave);
                            live.activeWave = null;
                        }
                        const run = state.currentSession?.runs?.find((r) => String(r.id) === String(id));
                        const st = run ? String(run.status || '') : '';
                        const anyCalling = !!(live.activeWave && live.activeWave.tools.some((x) => x.phase === 'calling'));
                        live.awaitingModelAfterTools =
                            !anyCalling && (st === 'processing' || st === 'queued');
                    }
                    renderTranscript();
                    updateHeaderFromSession();
                } catch (err) {
                    console.warn('ai-ops sse tool', err);
                }
            });

            es.addEventListener('run', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    let run = data.run;
                    if (!run || !state.currentSession || !Array.isArray(state.currentSession.runs)) return;
                    const st = String(run.status || '');
                    if (st === 'completed' || st === 'failed') {
                        const liveSnap = normalizeLiveSnapshot(run.id);
                        if (st === 'completed') {
                            const mergedPlain = liveFullAssistantPlainText(liveSnap).trim();
                            const mergedHtml = buildAssistantLiveBodyHtml(st, liveSnap).trim();
                            const serverPlain = String(run.result_summary || '').trim();
                            const bestPlain = mergedPlain.length >= serverPlain.length ? mergedPlain : serverPlain;
                            if (bestPlain) {
                                run = { ...run, result_summary: bestPlain };
                            }
                            if (mergedHtml) {
                                run = { ...run, client_ai_ops_body_html: mergedHtml };
                            }
                        }
                        delete state.liveByRunId[String(run.id)];
                    }
                    const runs = [...state.currentSession.runs];
                    const idx = runs.findIndex((r) => Number(r.id) === Number(run.id));
                    if (idx >= 0) runs[idx] = run;
                    else runs.push(run);
                    state.currentSession.runs = runs;
                    renderTranscript();
                    updateHeaderFromSession();
                } catch (err) {
                    console.warn('ai-ops sse run', err);
                }
            });

            es.addEventListener('done', () => {
                onAiOpsSseFinished();
            });

            es.addEventListener('stream_error', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    if (data.message) {
                        alert(data.message);
                    }
                } catch (_) {
                    //
                }
                setComposerLoading(false);
                if (state.currentSession?.id) {
                    selectSession(Number(state.currentSession.id)).catch(() => {});
                }
            });

            es.onerror = () => {
                if (state.eventSource !== es) {
                    return;
                }
                if (es.readyState === EventSource.CLOSED) {
                    setComposerLoading(false);
                    if (state.currentSession?.id) {
                        selectSession(Number(state.currentSession.id)).catch(() => {});
                    }
                    return;
                }
                closeRunEventSource();
                setComposerLoading(false);
                if (state.currentSession?.id) {
                    selectSession(Number(state.currentSession.id)).catch(() => {});
                }
            };
        }

        function openRunEventSource(runId) {
            closeRunEventSource();
            const id = String(runId);
            state.activeStreamRunId = Number(runId);
            state.liveByRunId[id] = {
                text: '',
                waves: [],
                activeWave: null,
                completedRounds: [],
                streamPending: true,
                streamConnected: false,
                awaitingModelAfterTools: false,
            };
            const es = new EventSource(urls.stream(id), { withCredentials: true });
            state.eventSource = es;
            bindAiOpsSseHandlers(es, id);
        }

        function openResumeEventSourceFromUrl(fullUrl, runId) {
            const id = String(runId);
            const prior = state.liveByRunId[id];
            let seeded = buildCompletedRoundsFromPriorLive(prior && typeof prior === 'object' ? prior : null);
            if (!seeded.length) {
                seeded = seedCompletedRoundsFromSessionRun(runId);
            }

            closeRunEventSource();
            state.activeStreamRunId = Number(runId);
            state.liveByRunId[id] = {
                text: '',
                waves: [],
                activeWave: null,
                completedRounds: seeded,
                streamPending: true,
                streamConnected: false,
                awaitingModelAfterTools: false,
            };
            setComposerLoading(true);
            const es = new EventSource(fullUrl, { withCredentials: true });
            state.eventSource = es;
            bindAiOpsSseHandlers(es, id);
        }

        function request(url, options = {}) {
            const headers = {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                ...(options.headers || {}),
            };
            if (!(options.body instanceof FormData)) {
                headers['Content-Type'] = 'application/json';
            }
            return fetch(url, { credentials: 'same-origin', ...options, headers }).then(async (response) => {
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    throw new Error(data.message || text.networkError);
                }
                return response.status === 204 ? null : response.json();
            });
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function statusLabel(status) {
            return text.statuses[status] || status || '-';
        }

        function setComposerLoading(on) {
            if (!els.composerLoading) return;
            els.composerLoading.classList.toggle('hidden', !on);
            els.composerLoading.setAttribute('aria-busy', on ? 'true' : 'false');
            if (els.submit) els.submit.disabled = on || els.model?.disabled;
            if (els.message) els.message.disabled = on || els.model?.disabled;
        }

        function scrollMessagesToBottom() {
            if (!els.messages) return;
            els.messages.scrollTop = els.messages.scrollHeight;
        }

        function renderSessionList() {
            if (!els.sessions) return;
            const q = (els.filter?.value || '').trim().toLowerCase();
            const items = state.sessions.filter((s) => !q || String(s.title || '').toLowerCase().includes(q));
            els.sessions.innerHTML = items.map((s) => {
                const active = state.currentSession && Number(state.currentSession.id) === Number(s.id);
                return `<button type="button" data-session-id="${s.id}" class="flex w-full flex-col rounded-xl border px-3 py-2.5 text-left text-sm transition ${active ? 'border-indigo-300 bg-indigo-50/90 shadow-sm' : 'border-transparent bg-white/70 hover:border-slate-200 hover:bg-white'}">
                    <span class="truncate font-medium text-slate-900">${escapeHtml(s.title)}</span>
                    <span class="mt-0.5 truncate text-xs text-slate-500">${escapeHtml(s.updated_at || '')}</span>
                </button>`;
            }).join('') || `<div class="px-1 py-6 text-center text-xs text-slate-400">${escapeHtml(text.emptyState)}</div>`;

            els.sessions.querySelectorAll('[data-session-id]').forEach((btn) => {
                btn.addEventListener('click', () => selectSession(Number(btn.getAttribute('data-session-id'))));
            });
        }

        function renderTranscript() {
            if (!els.messages) return;
            const sess = state.currentSession;
            if (!sess || !Array.isArray(sess.runs) || sess.runs.length === 0) {
                els.messages.innerHTML = `<div class="rounded-2xl border border-dashed border-slate-200 bg-white/60 p-10 text-center text-sm text-slate-500 shadow-inner">${escapeHtml(text.emptyState)}</div>`;
                scrollMessagesToBottom();
                return;
            }
            const parts = [];
            for (const run of sess.runs) {
                const u = String(run.input_text || '').trim();
                if (u) {
                    parts.push(`<div class="flex justify-end">
                        <div class="max-w-[min(100%,42rem)] rounded-2xl rounded-br-md bg-indigo-600 px-4 py-3 text-sm leading-relaxed text-white shadow-md">
                            <div class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-indigo-100/90">${escapeHtml(text.userInput)}</div>
                            <div class="whitespace-pre-wrap break-words">${escapeHtml(u)}</div>
                        </div>
                    </div>`);
                }
                const st = String(run.status || '');
                const live = normalizeLiveSnapshot(run.id);
                let bodyInner = '';
                if (st === 'completed') {
                    if (run.client_ai_ops_body_html) {
                        bodyInner = run.client_ai_ops_body_html;
                    } else {
                        const summary = String(run.result_summary || '').trim();
                        const fallback = summary || String(live.text || '').trim();
                        bodyInner = `<div class="whitespace-pre-wrap break-words">${escapeHtml(fallback)}</div>`;
                    }
                } else if (st === 'failed') {
                    bodyInner = `<div class="whitespace-pre-wrap break-words">${escapeHtml(String(run.error_message || '').trim() || statusLabel('failed'))}</div>`;
                } else if (st === 'awaiting_confirmation') {
                    const chunks = [];
                    if (run.approval_pending) {
                        const ap = run.approval_pending;
                        chunks.push(`<div class="mb-3 rounded-xl border border-amber-200 bg-amber-50/95 p-3 text-xs text-amber-950 shadow-inner">
                            <div class="font-semibold">${escapeHtml(text.toolApprovalBanner)}</div>
                            <div class="mt-1.5 leading-relaxed">${escapeHtml(String(ap.summary || ''))}</div>
                            <div class="mt-2 text-[11px] text-amber-900/80">${escapeHtml(text.toolApprovalExpires)}：${escapeHtml(String(ap.expires_at || '—'))}</div>
                            <div class="mt-2">
                                <button type="button" class="ai-ops-open-tool-approval inline-flex items-center rounded-lg bg-amber-900 px-3 py-1.5 text-[11px] font-semibold text-white shadow-sm hover:bg-amber-950" data-run-id="${run.id}">${escapeHtml(text.toolApprovalOpen)}</button>
                            </div>
                        </div>`);
                    }
                    if (run.assistant_partial_preview) {
                        chunks.push(`<details class="mb-2 rounded-lg border border-slate-200 bg-slate-50/80 p-2 text-[11px] text-slate-700">
                            <summary class="cursor-pointer font-medium text-slate-600">${escapeHtml(text.toolApprovalPartial)}</summary>
                            <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-words text-[11px] leading-snug">${escapeHtml(String(run.assistant_partial_preview))}</pre>
                        </details>`);
                    }
                    const liveBody = buildAssistantLiveBodyHtml(st, live);
                    if (liveBody) {
                        chunks.push(liveBody);
                    }
                    if (!chunks.length) {
                        chunks.push(`<div class="whitespace-pre-wrap break-words">${escapeHtml(statusLabel(st))}</div>`);
                    }
                    bodyInner = chunks.join('');
                } else if (liveHasAssistantStreamContent(live)) {
                    bodyInner = buildAssistantLiveBodyHtml(st, live);
                } else {
                    bodyInner = `<div class="whitespace-pre-wrap break-words">${escapeHtml(statusLabel(st))}</div>`;
                }
                parts.push(`<div class="flex justify-start">
                    <div class="max-w-[min(100%,42rem)] rounded-2xl rounded-bl-md border border-slate-200/80 bg-white px-4 py-3 text-sm leading-relaxed text-slate-800 shadow-sm">
                        <div class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">${escapeHtml(text.aiReply)} · ${escapeHtml(statusLabel(st))}</div>
                        ${bodyInner}
                    </div>
                </div>`);
            }
            els.messages.innerHTML = `<div class="mx-auto max-w-4xl space-y-5">${parts.join('')}</div>`;
            scrollMessagesToBottom();
        }

        function updateHeaderFromSession() {
            const sess = state.currentSession;
            if (!sess) {
                els.title.textContent = text.noSession;
                els.status.textContent = text.idle;
                return;
            }
            els.title.textContent = sess.title || text.noSession;
            const last = Array.isArray(sess.runs) && sess.runs.length ? sess.runs[sess.runs.length - 1] : null;
            els.status.textContent = last ? `${statusLabel(String(last.status))}` : text.idle;
        }

        async function loadSessions() {
            const data = await request(urls.sessions);
            state.sessions = Array.isArray(data.items) ? data.items : [];
            renderSessionList();
        }

        async function selectSession(id) {
            const data = await request(urls.session(id));
            const prevRuns = state.currentSession && Number(state.currentSession.id) === Number(id)
                ? state.currentSession.runs
                : null;
            if (Array.isArray(prevRuns) && Array.isArray(data.runs)) {
                data.runs = data.runs.map((r) => {
                    const old = prevRuns.find((x) => Number(x.id) === Number(r.id));
                    if (!old) {
                        return r;
                    }
                    const merged = { ...r };
                    if (old.client_ai_ops_body_html && !r.client_ai_ops_body_html) {
                        merged.client_ai_ops_body_html = old.client_ai_ops_body_html;
                    }
                    const oSum = String(old.result_summary || '').trim();
                    const nSum = String(r.result_summary || '').trim();
                    if (oSum.length > nSum.length) {
                        merged.result_summary = old.result_summary;
                    }
                    return merged;
                });
            }
            state.currentSession = data;
            renderSessionList();
            renderTranscript();
            updateHeaderFromSession();
        }

        async function createEmptySession() {
            const data = await request(urls.sessionStore, { method: 'POST', body: JSON.stringify({}) });
            state.currentSession = { ...data, runs: data.runs || [] };
            await loadSessions();
            renderSessionList();
            renderTranscript();
            updateHeaderFromSession();
        }

        els.newSession?.addEventListener('click', () => {
            createEmptySession().catch((e) => alert(e.message));
        });

        els.refresh?.addEventListener('click', () => {
            const p = state.currentSession
                ? selectSession(Number(state.currentSession.id))
                : loadSessions();
            p.catch((e) => alert(e.message));
        });

        els.filter?.addEventListener('input', () => renderSessionList());

        els.messages?.addEventListener('click', (ev) => {
            const btn = ev.target.closest('.ai-ops-open-tool-approval');
            if (!btn) return;
            const rid = Number(btn.getAttribute('data-run-id'));
            const run = state.currentSession?.runs?.find((r) => Number(r.id) === rid);
            if (run?.approval_pending) {
                showToolApprovalModal(rid, run.approval_pending);
            }
        });

        document.getElementById('ai-ops-tool-approval-close')?.addEventListener('click', () => {
            hideToolApprovalModal();
        });

        document.getElementById('ai-ops-tool-approval-approve-btn')?.addEventListener('click', () => {
            if (!pendingApprovalCtx) return;
            const url = aiOpsHttp.approveUrl(pendingApprovalCtx.runId, pendingApprovalCtx.approvalId);
            if (!url) {
                alert(text.networkError);
                return;
            }
            const rid = pendingApprovalCtx.runId;
            request(url, { method: 'POST', body: JSON.stringify({}) })
                .then((res) => {
                    hideToolApprovalModal();
                    if (res && res.run && state.currentSession?.runs) {
                        const runs = [...state.currentSession.runs];
                        const idx = runs.findIndex((r) => Number(r.id) === Number(res.run.id));
                        if (idx >= 0) runs[idx] = res.run;
                        state.currentSession.runs = runs;
                        renderTranscript();
                        updateHeaderFromSession();
                    }
                    const streamUrl = res && res.resume_stream_url ? String(res.resume_stream_url) : '';
                    if (streamUrl) {
                        openResumeEventSourceFromUrl(streamUrl, rid);
                    } else if (state.currentSession?.id) {
                        selectSession(Number(state.currentSession.id)).catch(() => {});
                    }
                })
                .catch((e) => alert(e.message));
        });

        document.getElementById('ai-ops-tool-approval-reject-btn')?.addEventListener('click', () => {
            if (!pendingApprovalCtx) return;
            const url = aiOpsHttp.rejectUrl(pendingApprovalCtx.runId, pendingApprovalCtx.approvalId);
            if (!url) {
                alert(text.networkError);
                return;
            }
            const rid = pendingApprovalCtx.runId;
            const reason = (document.getElementById('ai-ops-tool-approval-reason')?.value || '').trim();
            request(url, { method: 'POST', body: JSON.stringify({ reason }) })
                .then((res) => {
                    hideToolApprovalModal();
                    if (res && res.run && state.currentSession?.runs) {
                        const runs = [...state.currentSession.runs];
                        const idx = runs.findIndex((r) => Number(r.id) === Number(res.run.id));
                        if (idx >= 0) runs[idx] = res.run;
                        state.currentSession.runs = runs;
                        renderTranscript();
                        updateHeaderFromSession();
                    }
                    const streamUrl = res && res.reject_resume_stream_url ? String(res.reject_resume_stream_url) : '';
                    if (streamUrl) {
                        openResumeEventSourceFromUrl(streamUrl, rid);
                    } else if (state.currentSession?.id) {
                        selectSession(Number(state.currentSession.id)).catch(() => {});
                    }
                })
                .catch((e) => alert(e.message));
        });

        els.form?.addEventListener('submit', (ev) => {
            ev.preventDefault();
            const msg = (els.message?.value || '').trim();
            if (!msg) return;
            const modelId = Number(els.model?.value || 0);
            if (!modelId) {
                alert(text.modelRequired);
                return;
            }
            const sessionId = state.currentSession ? Number(state.currentSession.id) : null;
            setComposerLoading(true);
            request(urls.chat, {
                method: 'POST',
                body: JSON.stringify({
                    message: msg,
                    ai_model_id: modelId,
                    session_id: sessionId || undefined,
                }),
            }).then((data) => {
                if (data.session) {
                    if (!state.currentSession || Number(state.currentSession.id) !== Number(data.session.id)) {
                        state.currentSession = { id: data.session.id, title: data.session.title, runs: [] };
                    }
                    state.currentSession.title = data.session.title;
                    const runs = Array.isArray(state.currentSession.runs) ? [...state.currentSession.runs] : [];
                    const rid = Number(data.run?.id);
                    const idx = runs.findIndex((r) => Number(r.id) === rid);
                    if (idx >= 0) runs[idx] = data.run;
                    else runs.push(data.run);
                    state.currentSession.runs = runs;
                }
                els.message.value = '';
                renderTranscript();
                updateHeaderFromSession();
                loadSessions().catch(() => {});
                const rid = Number(data.run?.id);
                if (rid) {
                    openRunEventSource(rid);
                } else {
                    setComposerLoading(false);
                }
            }).catch((e) => {
                alert(e.message);
                setComposerLoading(false);
            });
        });

        loadSessions().then(() => {
            if (state.sessions.length) {
                return selectSession(Number(state.sessions[0].id));
            }
            state.currentSession = null;
            renderTranscript();
            updateHeaderFromSession();
            return null;
        }).catch((e) => alert(e.message));
    })();
</script>
@endpush
