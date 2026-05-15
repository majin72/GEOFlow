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
        'toolCalled' => __('admin.ai_ops.tool_called'),
        'toolPhaseCalling' => __('admin.ai_ops.tool_phase_calling'),
        'toolPhaseDone' => __('admin.ai_ops.tool_phase_done'),
        'toolPhaseFailed' => __('admin.ai_ops.tool_phase_failed'),
        'toolArgsLabel' => __('admin.ai_ops.tool_args_label'),
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
                v = { text: v, tools: [], streamPending: false, streamConnected: false };
                state.liveByRunId[id] = v;
            } else if (!v || typeof v !== 'object' || !Array.isArray(v.tools)) {
                v = { text: '', tools: [], streamPending: true, streamConnected: false };
                state.liveByRunId[id] = v;
            }
            return v;
        }

        function normalizeLiveSnapshot(runId) {
            const raw = state.liveByRunId[String(runId)];
            if (!raw) {
                return { text: '', tools: [], streamPending: false, streamConnected: false };
            }
            if (typeof raw === 'string') {
                return { text: raw, tools: [], streamPending: false, streamConnected: false };
            }
            return {
                text: String(raw.text || ''),
                tools: Array.isArray(raw.tools) ? raw.tools : [],
                streamPending: !!raw.streamPending,
                streamConnected: !!raw.streamConnected,
            };
        }

        function buildAssistantLiveBodyHtml(st, live) {
            const chunks = [];
            if (live.tools.length) {
                chunks.push('<div class="mb-3 space-y-2 rounded-xl border border-slate-200 bg-slate-50/90 p-3 text-xs shadow-inner">');
                live.tools.forEach((t) => {
                    const isCalling = t.phase === 'calling';
                    const failed = t.phase === 'done' && t.successful === false;
                    const label = isCalling ? text.toolPhaseCalling : (failed ? text.toolPhaseFailed : text.toolPhaseDone);
                    const tone = isCalling
                        ? 'border-amber-200 bg-amber-50/90 text-amber-900'
                        : (failed ? 'border-rose-200 bg-rose-50/90 text-rose-900' : 'border-emerald-200 bg-emerald-50/90 text-emerald-900');
                    chunks.push(`<div class="rounded-lg border ${tone} px-2.5 py-2">`);
                    chunks.push('<div class="flex flex-wrap items-center gap-2 font-medium">');
                    chunks.push(`<span>${escapeHtml(text.toolCalled)}</span>`);
                    chunks.push(`<code class="rounded bg-white/80 px-1.5 py-0.5 text-[11px] text-slate-800">${escapeHtml(t.name)}</code>`);
                    chunks.push(`<span class="text-[11px] font-normal opacity-80">${escapeHtml(label)}</span>`);
                    chunks.push('</div>');
                    if (isCalling && t.preview) {
                        chunks.push(`<div class="mt-1.5 text-[11px] text-slate-600">${escapeHtml(text.toolArgsLabel)}</div>`);
                        chunks.push(`<pre class="mt-1 max-h-36 overflow-auto rounded-md bg-white/95 p-2 text-[11px] leading-snug text-slate-700">${escapeHtml(t.preview)}</pre>`);
                    }
                    if (t.phase === 'done' && t.error) {
                        chunks.push(`<div class="mt-1 text-[11px] text-rose-700">${escapeHtml(t.error)}</div>`);
                    }
                    chunks.push('</div>');
                });
                chunks.push('</div>');
            } else if (live.streamConnected) {
                chunks.push(`<p class="mb-2 text-sm text-slate-500">${escapeHtml(text.streamConnected)}</p>`);
            } else if (live.streamPending && (st === 'processing' || st === 'queued')) {
                chunks.push(`<p class="mb-2 text-sm text-slate-500">${escapeHtml(text.streamPending)}</p>`);
            }
            if (live.text) {
                chunks.push(`<div class="whitespace-pre-wrap break-words">${escapeHtml(live.text)}</div>`);
            }
            if (!chunks.length && (st === 'processing' || st === 'queued')) {
                chunks.push(`<p class="text-sm text-slate-400">${escapeHtml(text.aiReplyWaiting)}</p>`);
            }
            return chunks.join('');
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

        function openRunEventSource(runId) {
            closeRunEventSource();
            const id = String(runId);
            state.activeStreamRunId = Number(runId);
            state.liveByRunId[id] = { text: '', tools: [], streamPending: true, streamConnected: false };
            const es = new EventSource(urls.stream(id), { withCredentials: true });
            state.eventSource = es;

            es.addEventListener('delta', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    const t = String(data.text || '');
                    const live = ensureLiveStruct(id);
                    live.text = t;
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
                    if (data.kind !== 'connected') {
                        return;
                    }
                    const live = ensureLiveStruct(id);
                    live.streamConnected = true;
                    live.streamPending = false;
                    renderTranscript();
                    updateHeaderFromSession();
                } catch (err) {
                    console.warn('ai-ops sse stream_status', err);
                }
            });

            es.addEventListener('tool', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    const live = ensureLiveStruct(id);
                    if (data.phase === 'calling') {
                        live.tools.push({
                            toolCallId: String(data.tool_call_id || ''),
                            name: String(data.tool_name || ''),
                            phase: 'calling',
                            preview: String(data.arguments_preview || ''),
                        });
                    } else if (data.phase === 'done') {
                        const tid = String(data.tool_call_id || '');
                        const row = [...live.tools].reverse().find((x) => x.toolCallId === tid && x.phase === 'calling');
                        if (row) {
                            row.phase = 'done';
                            row.successful = !!data.successful;
                            row.error = data.error ? String(data.error) : '';
                        } else {
                            live.tools.push({
                                toolCallId: tid,
                                name: String(data.tool_name || ''),
                                phase: 'done',
                                successful: !!data.successful,
                                error: data.error ? String(data.error) : '',
                            });
                        }
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
                        const summaryEmpty = !String(run.result_summary || '').trim();
                        if (st === 'completed' && summaryEmpty && liveSnap.text.trim()) {
                            run = { ...run, result_summary: liveSnap.text.trim() };
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
                closeRunEventSource();
                setComposerLoading(false);
                loadSessions().catch(() => {});
                if (state.currentSession?.id) {
                    selectSession(Number(state.currentSession.id)).catch(() => {});
                }
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
                    const summary = String(run.result_summary || '').trim();
                    const fallback = summary || String(live.text || '').trim();
                    bodyInner = `<div class="whitespace-pre-wrap break-words">${escapeHtml(fallback)}</div>`;
                } else if (st === 'failed') {
                    bodyInner = `<div class="whitespace-pre-wrap break-words">${escapeHtml(String(run.error_message || '').trim() || statusLabel('failed'))}</div>`;
                } else if (live.text || live.tools.length || live.streamConnected || live.streamPending) {
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
