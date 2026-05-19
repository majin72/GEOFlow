/**
 * 后台 AI 运维对话：会话列表、SSE 流式正文、可折叠工具卡片、Markdown 报告渲染。
 */
import DOMPurify from 'dompurify';
import { marked } from 'marked';

marked.setOptions({ gfm: true });

/**
 * @param {object} config
 */
export function initAdminAiOps(config) {
const root = document.getElementById('admin-ai-ops-page');
        if (!root) {
            return;
        }

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const text = config.text || {};

        const rawUrls = config.urls || {};
        const urls = {
            chat: rawUrls.chat,
            sessions: rawUrls.sessions,
            sessionStore: rawUrls.sessionStore,
            stream: (id) => String(rawUrls.streamUrlTemplate || rawUrls.stream || '').replace('__RUN_ID__', String(id)),
            session: (id) => String(rawUrls.session || '').replace('__SESSION_ID__', String(id)),
            sessionDestroy: (id) => String(rawUrls.sessionDestroy || '').replace('__SESSION_ID__', String(id)),
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
            networkMode: document.getElementById('ai-ops-network-mode'),
        };

        
        const webSearchKeyConfigured = config.webSearchKeyConfigured === true;
        const NETWORK_MODE_STORAGE_KEY = 'geoflow_ai_ops_network_mode';
        if (els.networkMode) {
            if (!webSearchKeyConfigured) {
                els.networkMode.checked = false;
                els.networkMode.disabled = true;
                localStorage.removeItem(NETWORK_MODE_STORAGE_KEY);
            } else {
                const saved = localStorage.getItem(NETWORK_MODE_STORAGE_KEY);
                els.networkMode.checked = saved === '1';
                els.networkMode.addEventListener('change', () => {
                    localStorage.setItem(NETWORK_MODE_STORAGE_KEY, els.networkMode.checked ? '1' : '0');
                });
            }
        }
        /**
         * @returns {boolean}
         */
        function isNetworkModeEnabled() {
            return webSearchKeyConfigured && !!els.networkMode?.checked;
        }

        let state = {
            sessions: [],
            currentSession: null,
            eventSource: null,
            activeStreamRunId: null,
            liveByRunId: {},
        };

        /**
         * @returns {object}
         */
        function createEmptyLiveStruct(streamPending = true) {
            return {
                segments: [],
                completedRounds: [],
                textLocked: false,
                preToolTextSnapshot: '',
                streamPending,
                streamConnected: false,
                awaitingModelAfterTools: false,
            };
        }

        /**
         * @param {object} seg
         * @returns {object}
         */
        function cloneTimelineSegment(seg) {
            if (seg.kind === 'tools') {
                return {
                    kind: 'tools',
                    tools: Array.isArray(seg.tools) ? seg.tools.map((t) => ({ ...t })) : [],
                };
            }
            return { kind: 'text', text: String(seg.text || '') };
        }

        /**
         * @param {Array<object>} segments
         * @returns {Array<object>}
         */
        function cloneTimelineSegments(segments) {
            return (segments || []).map((s) => cloneTimelineSegment(s));
        }

        /**
         * @param {string} text
         * @param {Array<object>} waves
         * @returns {Array<object>}
         */
        function legacyTextWavesToSegments(text, waves) {
            const segments = [];
            const ft = String(text || '');
            let pos = 0;
            (waves || []).forEach((w) => {
                const end = Number(w.end) || 0;
                const slice = ft.slice(pos, end);
                if (slice.trim()) {
                    segments.push({ kind: 'text', text: slice });
                }
                segments.push({
                    kind: 'tools',
                    tools: Array.isArray(w.tools) ? w.tools.map((t) => ({ ...t })) : [],
                });
                pos = end;
            });
            const tail = ft.slice(pos);
            if (tail.trim()) {
                segments.push({ kind: 'text', text: tail });
            }
            return segments;
        }

        /**
         * @param {object} raw
         * @returns {object}
         */
        function migrateLegacyLiveSnapshot(raw) {
            const waves = [...(raw.waves || [])];
            if (raw.activeWave && raw.activeWave.tools && raw.activeWave.tools.length) {
                waves.push(raw.activeWave);
            }
            const completedRounds = (raw.completedRounds || []).map((round) => {
                if (Array.isArray(round.segments)) {
                    return { segments: cloneTimelineSegments(round.segments) };
                }
                return {
                    segments: legacyTextWavesToSegments(round.text, round.waves),
                };
            });
            return {
                segments: legacyTextWavesToSegments(raw.text, waves),
                completedRounds,
                textLocked: false,
                preToolTextSnapshot: '',
                streamPending: !!raw.streamPending,
                streamConnected: !!raw.streamConnected,
                awaitingModelAfterTools: !!raw.awaitingModelAfterTools,
            };
        }

        function ensureLiveStruct(runId) {
            const id = String(runId);
            let v = state.liveByRunId[id];
            if (typeof v === 'string') {
                const legacyText = v;
                v = createEmptyLiveStruct(false);
                v.segments = [{ kind: 'text', text: legacyText }];
                state.liveByRunId[id] = v;
            } else if (!v || typeof v !== 'object') {
                v = createEmptyLiveStruct(true);
                state.liveByRunId[id] = v;
            } else if (!Array.isArray(v.segments)) {
                v = migrateLegacyLiveSnapshot(v);
                state.liveByRunId[id] = v;
            }
            if (!Array.isArray(v.completedRounds)) {
                v.completedRounds = [];
            }
            return v;
        }

        /**
         * 从服务端持久化的 assistant_timeline 还原为与 live 缓冲一致的结构。
         *
         * @param {object} run
         * @returns {object|null}
         */
        function assistantTimelineFromRun(run) {
            const tl = run?.assistant_timeline;
            if (!tl || typeof tl !== 'object') {
                return null;
            }
            if (Array.isArray(tl.segments)) {
                return {
                    segments: cloneTimelineSegments(tl.segments),
                    completedRounds: Array.isArray(tl.completedRounds)
                        ? tl.completedRounds.map((round) => {
                            if (Array.isArray(round.segments)) {
                                return { segments: cloneTimelineSegments(round.segments) };
                            }
                            return {
                                segments: legacyTextWavesToSegments(round.text, round.waves),
                            };
                        })
                        : [],
                    textLocked: false,
                    preToolTextSnapshot: '',
                    streamPending: false,
                    streamConnected: false,
                    awaitingModelAfterTools: false,
                };
            }
            return migrateLegacyLiveSnapshot({
                ...tl,
                streamPending: false,
                streamConnected: false,
                awaitingModelAfterTools: false,
            });
        }

        function normalizeLiveSnapshot(runId) {
            const raw = state.liveByRunId[String(runId)];
            if (!raw) {
                return createEmptyLiveStruct(false);
            }
            if (typeof raw === 'string') {
                const live = createEmptyLiveStruct(false);
                live.segments = [{ kind: 'text', text: raw }];
                return live;
            }
            if (!Array.isArray(raw.segments)) {
                return migrateLegacyLiveSnapshot(raw);
            }
            return {
                segments: cloneTimelineSegments(raw.segments),
                completedRounds: Array.isArray(raw.completedRounds)
                    ? raw.completedRounds.map((round) => ({
                        segments: cloneTimelineSegments(round.segments),
                    }))
                    : [],
                textLocked: !!raw.textLocked,
                preToolTextSnapshot: String(raw.preToolTextSnapshot || ''),
                streamPending: !!raw.streamPending,
                streamConnected: !!raw.streamConnected,
                awaitingModelAfterTools: !!raw.awaitingModelAfterTools,
            };
        }

        /**
         * 将助手 Markdown 转为经 DOMPurify 净化的 HTML。
         *
         * @param {string} markdown
         * @returns {string}
         */
        function renderAssistantMarkdownHtml(markdown) {
            const src = String(markdown || '').trim();
            if (!src) {
                return '';
            }
            const rawHtml = marked.parse(src, { async: false });
            const safe = DOMPurify.sanitize(rawHtml, { USE_PROFILES: { html: true } });
            return `<div class="ai-ops-md mb-2 text-sm leading-relaxed">${safe}</div>`;
        }

        /**
         * @param {Array<object>} segments
         * @returns {string}
         */
        function plainTextFromSegments(segments) {
            return (segments || [])
                .filter((s) => s.kind === 'text')
                .map((s) => String(s.text || ''))
                .filter((p) => p.trim() !== '')
                .join('\n\n');
        }

        /**
         * @param {object} live
         * @returns {boolean}
         */
        function hasToolsInLiveSegments(live) {
            return (live.segments || []).some((s) => s.kind === 'tools');
        }

        /**
         * @param {object} live
         * @param {string} accumulated
         * @returns {boolean}
         */
        function hasCallingToolsInLive(live) {
            return collectLiveToolsFlat(live).some((t) => t.phase === 'calling');
        }

        function isStalePreToolDelta(live, accumulated) {
            if (!live.textLocked || !hasCallingToolsInLive(live)) {
                return false;
            }
            const snap = String(live.preToolTextSnapshot || '');
            return snap !== '' && accumulated.startsWith(snap);
        }

        /**
         * @param {object} live
         * @param {string} accumulated
         * @returns {string}
         */
        function stripPreToolPrefix(live, accumulated) {
            const snap = String(live.preToolTextSnapshot || '');
            if (!snap || !accumulated.startsWith(snap)) {
                return accumulated;
            }
            return accumulated.slice(snap.length).replace(/^[\s\n\r]+/, '');
        }

        /**
         * @param {object} live
         * @param {string} text
         * @param {boolean} afterTools
         */
        function appendOrUpdateTrailingTextSegment(live, text, afterTools = false) {
            let txt = afterTools ? stripPreToolPrefix(live, text) : text;
            const segs = live.segments;
            if (segs.length && segs[segs.length - 1].kind === 'text') {
                segs[segs.length - 1].text = txt;
                return;
            }
            segs.push({ kind: 'text', text: txt });
        }

        /**
         * @param {object} live
         * @param {string} accumulatedText
         */
        function applyDeltaToLive(live, accumulatedText) {
            const t = String(accumulatedText || '');
            if (live.textLocked) {
                if (isStalePreToolDelta(live, t)) {
                    return;
                }
                live.textLocked = false;
                appendOrUpdateTrailingTextSegment(live, t, true);
                return;
            }
            appendOrUpdateTrailingTextSegment(live, t, hasToolsInLiveSegments(live));
        }

        /**
         * @param {object} live
         * @returns {object|null}
         */
        function openToolsSegment(live) {
            const segs = live.segments || [];
            if (!segs.length || segs[segs.length - 1].kind !== 'tools') {
                return null;
            }
            return segs[segs.length - 1];
        }

        /**
         * @param {object} live
         * @param {object} data
         */
        function recordToolCallingToLive(live, data) {
            if (!openToolsSegment(live)) {
                live.preToolTextSnapshot = plainTextFromSegments(live.segments);
                live.textLocked = true;
                live.segments.push({ kind: 'tools', tools: [] });
            }
            openToolsSegment(live).tools.push({
                toolCallId: String(data.tool_call_id || ''),
                name: String(data.tool_name || ''),
                phase: 'calling',
                preview: String(data.arguments_preview || ''),
                calledAt: Date.now(),
            });
        }

        /**
         * @param {object} live
         * @param {string} toolCallId
         * @returns {object|null}
         */
        function findToolRowByCallId(live, toolCallId) {
            const tid = String(toolCallId || '');
            if (!tid) {
                return null;
            }

            const searchSegments = (segments) => {
                for (let si = (segments || []).length - 1; si >= 0; si -= 1) {
                    const seg = segments[si];
                    if (seg.kind !== 'tools' || !Array.isArray(seg.tools)) {
                        continue;
                    }
                    const row = [...seg.tools].reverse().find((x) => x.toolCallId === tid);
                    if (row) {
                        return row;
                    }
                }
                return null;
            };

            const inCurrent = searchSegments(live.segments);
            if (inCurrent) {
                return inCurrent;
            }

            const rounds = live.completedRounds || [];
            for (let ri = rounds.length - 1; ri >= 0; ri -= 1) {
                const inRound = searchSegments(rounds[ri].segments);
                if (inRound) {
                    return inRound;
                }
            }

            return null;
        }

        /**
         * @param {object} live
         * @param {string} toolCallId
         * @param {string} phase
         * @param {object} [extras]
         */
        function markToolPhaseByCallId(live, toolCallId, phase, extras = {}) {
            const toolName = String(extras.toolName || '');
            let row = toolCallId ? findToolRowByCallId(live, toolCallId) : null;
            if (!row) {
                row = findLastPendingApprovalToolRow(live, toolName);
            }
            if (!row) {
                return;
            }
            row.phase = phase;
            Object.assign(row, extras);
        }

        /**
         * @param {object} live
         * @param {object} data
         */
        function recordToolDoneToLive(live, data) {
            const toolName = String(data.tool_name || '');
            let tid = String(data.tool_call_id || '').trim();
            if (!tid || !findToolRowByCallId(live, tid)) {
                const pending = findLastPendingApprovalToolRow(live, toolName);
                if (pending && pending.toolCallId) {
                    tid = String(pending.toolCallId);
                }
            }
            const rp = data.result_preview != null ? String(data.result_preview) : '';
            const row = tid ? findToolRowByCallId(live, tid) : findLastPendingApprovalToolRow(live, toolName);
            if (row) {
                row.phase = 'done';
                row.successful = !!data.successful;
                row.error = data.error ? String(data.error) : '';
                if (rp) {
                    row.resultPreview = rp;
                }
                if (data.duration_ms != null) {
                    row.durationMs = Number(data.duration_ms);
                } else if (row.calledAt) {
                    row.durationMs = Date.now() - row.calledAt;
                }
                if (data.raw_output != null && String(data.raw_output) !== '') {
                    row.rawOutput = String(data.raw_output);
                }
            } else {
                if (!openToolsSegment(live)) {
                    live.segments.push({ kind: 'tools', tools: [] });
                }
                openToolsSegment(live).tools.push({
                    toolCallId: tid,
                    name: String(data.tool_name || ''),
                    phase: 'done',
                    successful: !!data.successful,
                    error: data.error ? String(data.error) : '',
                    resultPreview: rp,
                });
            }
            if (!hasCallingToolsInLive(live)) {
                live.textLocked = false;
            }
        }

        /**
         * 收集当前缓冲区内所有工具行，用于状态判断。
         *
         * @param {object} live
         * @returns {Array<object>}
         */
        function collectLiveToolsFlat(live) {
            const out = [];
            (live.segments || []).forEach((seg) => {
                if (seg.kind === 'tools') {
                    (seg.tools || []).forEach((t) => out.push(t));
                }
            });
            return out;
        }

        /**
         * 渲染一组工具卡片（同一轮次内可多个并行工具）。
         *
         * @param {Array<object>} tools
         * @returns {string}
         */
        function formatDurationMs(ms) {
            const n = Number(ms);
            if (!n || n < 0) {
                return '';
            }
            if (n < 1000) {
                return `${Math.round(n)}ms`;
            }
            return `${(n / 1000).toFixed(1)}s`;
        }

        function classifyToolShouldExpand(t) {
            if (t.phase === 'calling' || t.phase === 'awaiting_approval' || t.phase === 'executing') {
                return true;
            }
            if (t.phase === 'rejected') {
                return true;
            }
            if (t.phase === 'done' && t.successful === false) {
                return true;
            }
            return false;
        }

        function renderAiOpsToolCardHtml(t) {
            const isCalling = t.phase === 'calling';
            const executing = t.phase === 'executing';
            const awaiting = t.phase === 'awaiting_approval';
            const rejected = t.phase === 'rejected';
            const failed = !rejected && t.phase === 'done' && t.successful === false;
            let label = text.toolPhaseDone;
            if (isCalling) {
                label = text.toolPhaseCalling;
            } else if (executing) {
                label = text.toolPhaseExecuting;
            } else if (awaiting) {
                label = text.toolPhaseAwaitingApproval;
            } else if (rejected) {
                label = text.toolPhaseRejected;
            } else if (failed) {
                label = text.toolPhaseFailed;
            }
            const tone = isCalling || executing || awaiting
                ? 'border-amber-200 bg-amber-50/90 text-amber-900'
                : (rejected || failed
                    ? 'border-rose-200 bg-rose-50/90 text-rose-900'
                    : 'border-emerald-200 bg-emerald-50/90 text-emerald-900');
            const duration = formatDurationMs(t.durationMs);
            const openAttr = classifyToolShouldExpand(t) ? ' open' : '';
            const parts = [];
            parts.push(`<details class="ai-ops-tool-details rounded-lg border ${tone} text-xs shadow-sm"${openAttr}>`);
            parts.push('<summary class="flex cursor-pointer list-none flex-wrap items-center gap-2 px-2.5 py-2 font-medium marker:content-none [&::-webkit-details-marker]:hidden">');
            parts.push(`<span class="text-slate-600">${escapeHtml(text.toolCalled)}</span>`);
            parts.push(`<code class="rounded bg-white/80 px-1.5 py-0.5 text-[11px] text-slate-800">${escapeHtml(t.name)}</code>`);
            parts.push(`<span class="text-[11px] font-normal opacity-80">${escapeHtml(label)}</span>`);
            if (duration) {
                parts.push(`<span class="ml-auto text-[10px] font-normal tabular-nums text-slate-500">${escapeHtml(duration)}</span>`);
            }
            parts.push('</summary>');
            parts.push('<div class="space-y-2 border-t border-black/5 px-2.5 pb-2.5 pt-2">');
            const preview = String(t.preview || '');
            if (preview) {
                parts.push(`<div class="text-[11px] font-medium text-slate-600">${escapeHtml(text.toolArgsLabel)}</div>`);
                parts.push(`<pre class="max-h-36 overflow-auto rounded-md bg-white/95 p-2 text-[11px] leading-snug text-slate-700">${escapeHtml(preview)}</pre>`);
            }
            if (t.phase === 'done' && t.error) {
                parts.push(`<div class="text-[11px] text-rose-700">${escapeHtml(t.error)}</div>`);
            }
            const rp = String(t.resultPreview || '');
            if (t.phase === 'done' && rp) {
                parts.push(`<div class="text-[11px] font-medium text-slate-600">${escapeHtml(text.toolResultPreview)}</div>`);
                parts.push(`<pre class="max-h-40 overflow-auto rounded-md bg-slate-100/95 p-2 text-[11px] leading-snug text-slate-800">${escapeHtml(rp)}</pre>`);
            }
            const raw = String(t.rawOutput || '');
            if (t.phase === 'done' && raw) {
                parts.push(`<div class="text-[11px] font-medium text-slate-600">${escapeHtml(text.toolRawOutput)}</div>`);
                parts.push(`<pre class="max-h-48 overflow-auto rounded-md bg-slate-900/95 p-2 font-mono text-[11px] leading-snug text-slate-100">${escapeHtml(raw)}</pre>`);
            }
            parts.push('</div>');
            parts.push('</details>');
            return parts.join('');
        }

        function renderAiOpsToolGroupHtml(tools) {
            if (!tools.length) {
                return '';
            }
            const cards = tools.map((t) => renderAiOpsToolCardHtml(t)).join('');
            return `<div class="mb-3 space-y-2 rounded-xl border border-slate-200 bg-slate-50/90 p-3 shadow-inner">${cards}</div>`;
        }

        /**
         * 续流前若内存缓冲丢失，用会话里已落库的 partial 预览兜底，避免首轮正文整块消失。
         *
         * @param {number|string} runId
         * @returns {Array<{ segments: Array<object> }>}
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
            return [{ segments: [{ kind: 'text', text: partial }] }];
        }

        /**
         * 将上一轮 EventSource 缓冲转为续流用的 completedRounds 初始值。
         *
         * @param {object|null} prevLive
         * @returns {Array<{ segments: Array<object> }>}
         */
        function buildCompletedRoundsFromPriorLive(prevLive) {
            if (!prevLive || typeof prevLive !== 'object') {
                return [];
            }
            const normalized = Array.isArray(prevLive.segments)
                ? prevLive
                : migrateLegacyLiveSnapshot(prevLive);
            const out = [];
            (normalized.completedRounds || []).forEach((round) => {
                out.push({ segments: cloneTimelineSegments(round.segments) });
            });
            if ((normalized.segments || []).length) {
                out.push({ segments: cloneTimelineSegments(normalized.segments) });
            }
            return out;
        }

        /**
         * 按 segments 顺序渲染：text → tools → text …
         *
         * @param {Array<object>} segments
         * @returns {string}
         */
        function renderSegmentsHtml(segments) {
            const chunks = [];
            (segments || []).forEach((seg) => {
                if (seg.kind === 'text') {
                    const slice = String(seg.text || '');
                    if (slice.trim()) {
                        chunks.push(renderAssistantMarkdownHtml(slice));
                    }
                } else if (seg.kind === 'tools') {
                    chunks.push(renderAiOpsToolGroupHtml(seg.tools || []));
                }
            });
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
            (live.completedRounds || []).forEach((round) => {
                const s = plainTextFromSegments(round.segments).trim();
                if (s) {
                    parts.push(s);
                }
            });
            const cur = plainTextFromSegments(live.segments).trim();
            if (cur) {
                parts.push(cur);
            }
            return parts.join('\n\n');
        }

        /**
         * 将 completedRounds 压平进 segments，避免续流/完成态出现多段分隔线与残片。
         *
         * @param {object} live
         */
        function flattenLiveTimelineRounds(live) {
            const merged = [];
            (live.completedRounds || []).forEach((round) => {
                (round.segments || []).forEach((seg) => merged.push(seg));
            });
            (live.segments || []).forEach((seg) => merged.push(seg));
            live.segments = mergeAdjacentTextSegments(cloneTimelineSegments(merged));
            live.completedRounds = [];
        }

        /**
         * 合并相邻 text 段；若后段是前段超集则保留较长者，避免工具轮次后重复开头。
         *
         * @param {Array<object>} segments
         * @returns {Array<object>}
         */
        function mergeAdjacentTextSegments(segments) {
            const out = [];
            (segments || []).forEach((seg) => {
                if (seg.kind !== 'text') {
                    out.push({ ...seg, tools: seg.tools ? [...seg.tools] : undefined });
                    return;
                }
                const t = String(seg.text || '');
                if (!t.trim()) {
                    return;
                }
                if (out.length && out[out.length - 1].kind === 'text') {
                    const prev = String(out[out.length - 1].text || '');
                    if (t.startsWith(prev) || prev.startsWith(t)) {
                        out[out.length - 1].text = t.length >= prev.length ? t : prev;
                    } else {
                        out[out.length - 1].text = `${prev}\n\n${t}`;
                    }
                    return;
                }
                out.push({ kind: 'text', text: t });
            });
            return out;
        }

        /**
         * 展示前规范化时间线（压平历史轮次、合并重复正文）。
         *
         * @param {object} live
         * @returns {object}
         */
        function compactLiveTimelineForDisplay(live) {
            const copy = {
                segments: cloneTimelineSegments(live.segments || []),
                completedRounds: (live.completedRounds || []).map((round) => ({
                    segments: cloneTimelineSegments(round.segments || []),
                })),
                textLocked: !!live.textLocked,
                preToolTextSnapshot: String(live.preToolTextSnapshot || ''),
                streamPending: !!live.streamPending,
                streamConnected: !!live.streamConnected,
                awaitingModelAfterTools: !!live.awaitingModelAfterTools,
            };
            if (copy.completedRounds.length > 0) {
                flattenLiveTimelineRounds(copy);
            } else {
                copy.segments = mergeAdjacentTextSegments(copy.segments);
            }
            return copy;
        }

        /**
         * 按时间线渲染：助手文本片段 → 紧随其后的工具组 → 后续文本（避免工具块全部堆在顶部）。
         *
         * @param {string} st
         * @param {object} live
         * @returns {string}
         */
        function buildAssistantLiveBodyHtml(st, live) {
            const display = compactLiveTimelineForDisplay(live);
            const chunks = [];
            const inner = renderSegmentsHtml(display.segments);
            if (inner.trim()) {
                chunks.push(inner);
            }

            const flatTools = collectLiveToolsFlat(display);
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
         * 时间线是否含有可渲染的 text/tools 段（非仅空 completedRounds 占位）。
         *
         * @param {object|null} live
         * @returns {boolean}
         */
        function timelineHasDisplayableContent(live) {
            if (!live || typeof live !== 'object') {
                return false;
            }
            const hasSegs = (segs) => (segs || []).some((seg) => {
                if (seg.kind === 'text') {
                    return String(seg.text || '').trim() !== '';
                }
                if (seg.kind === 'tools') {
                    return (seg.tools || []).length > 0;
                }
                return false;
            });
            if (hasSegs(live.segments)) {
                return true;
            }
            return (live.completedRounds || []).some((round) => hasSegs(round.segments));
        }

        /**
         * @param {object|null} timeline
         * @returns {number}
         */
        function timelineContentScore(timeline) {
            if (!timeline || typeof timeline !== 'object') {
                return 0;
            }
            let score = 0;
            const countSegs = (segs) => {
                (segs || []).forEach((seg) => {
                    if (seg.kind === 'text' && String(seg.text || '').trim()) {
                        score += String(seg.text).length;
                    }
                    if (seg.kind === 'tools') {
                        score += 1000 * (seg.tools || []).length;
                    }
                });
            };
            countSegs(timeline.segments);
            (timeline.completedRounds || []).forEach((round) => countSegs(round.segments));
            return score;
        }

        /**
         * 判断当前 run 的实时缓冲是否仍有可展示内容（用于 transcript 分支）。
         *
         * @param {object} live
         */
        function liveHasAssistantStreamContent(live) {
            if (timelineHasDisplayableContent(live)) {
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

        /** @type {Record<string, string>} */
        const approvalToolCallByRunId = {};

        /**
         * 解析审批对应的 tool_call_id（SSE / 会话 / 内存兜底）。
         *
         * @param {object} live
         * @param {number|string} runId
         * @param {string} toolCallId
         * @param {string} toolName
         * @returns {string}
         */
        function resolveApprovalToolCallId(live, runId, toolCallId, toolName) {
            const tid = String(toolCallId || '').trim();
            if (tid && findToolRowByCallId(live, tid)) {
                return tid;
            }
            const fromMap = String(approvalToolCallByRunId[String(runId)] || '').trim();
            if (fromMap && findToolRowByCallId(live, fromMap)) {
                return fromMap;
            }
            const row = findLastPendingApprovalToolRow(live, toolName);
            if (row && row.toolCallId) {
                return String(row.toolCallId);
            }
            return tid || fromMap;
        }

        /**
         * @param {object} live
         * @param {string} [toolName]
         * @returns {object|null}
         */
        function findLastPendingApprovalToolRow(live, toolName = '') {
            const flat = collectLiveToolsFlat(live);
            const pendingPhases = ['awaiting_approval', 'calling', 'executing'];
            const candidates = flat.filter((t) => pendingPhases.includes(t.phase));
            const name = String(toolName || '').trim();
            if (name) {
                for (let i = candidates.length - 1; i >= 0; i -= 1) {
                    if (candidates[i].name === name) {
                        return candidates[i];
                    }
                }
            }
            return candidates.length ? candidates[candidates.length - 1] : null;
        }

        /**
         * 渲染 run 时优先使用内存中的 live 缓冲（审批/续流中的实时状态）。
         *
         * @param {object} run
         * @returns {object}
         */
        function resolveLiveTimelineForRun(run) {
            const runId = String(run.id);
            if (Object.prototype.hasOwnProperty.call(state.liveByRunId, runId)) {
                const buf = normalizeLiveSnapshot(run.id);
                if (timelineHasDisplayableContent(buf)) {
                    return buf;
                }
            }
            const persisted = assistantTimelineFromRun(run);
            if (persisted && timelineHasDisplayableContent(persisted)) {
                return persisted;
            }
            return normalizeLiveSnapshot(run.id);
        }

        /**
         * 将内存 live 时间线同步回 run 对象，避免被陈旧 assistant_timeline 盖住。
         *
         * @param {object} run
         */
        function syncRunAssistantTimelineFromLiveBuffer(run) {
            const runId = String(run.id);
            if (!Object.prototype.hasOwnProperty.call(state.liveByRunId, runId)) {
                return;
            }
            const buf = normalizeLiveSnapshot(run.id);
            if (!timelineHasDisplayableContent(buf)) {
                return;
            }
            const compact = compactLiveTimelineForDisplay(buf);
            state.liveByRunId[runId] = {
                ...buf,
                segments: compact.segments,
                completedRounds: [],
            };
            run.assistant_timeline = {
                completedRounds: [],
                segments: cloneTimelineSegments(compact.segments),
            };
        }

        function showToolApprovalModal(runId, payload) {
            const approvalId = String(payload.id || payload.approval_id || '');
            const rid = Number(runId);
            pendingApprovalCtx = {
                runId: rid,
                approvalId,
                toolCallId: String(
                    payload.tool_call_id
                    || approvalToolCallByRunId[String(rid)]
                    || '',
                ),
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
            const finishedRunId = state.activeStreamRunId;
            closeRunEventSource();
            setComposerLoading(false);
            if (state.currentSession?.id && finishedRunId) {
                const runRow = state.currentSession.runs?.find((r) => Number(r.id) === Number(finishedRunId));
                if (runRow) {
                    syncRunAssistantTimelineFromLiveBuffer(runRow);
                }
            }
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
        /**
         * @param {object} live
         * @param {string} toolCallId
         * @param {string} reason
         */
        function markToolRejectedByCallId(live, toolCallId, reason) {
            const tid = String(toolCallId || '');
            if (!tid) {
                return;
            }
            const row = findToolRowByCallId(live, tid);
            if (!row) {
                return;
            }
            row.phase = 'rejected';
            row.successful = false;
            const r = String(reason || '').trim();
            if (r) {
                row.error = r;
                row.resultPreview = r;
            }
        }

        function markCallingToolsAwaitingApproval(live, previewText) {
            const pv = String(previewText || '').trim();
            const mark = (t) => {
                if (t && t.phase === 'calling') {
                    t.phase = 'awaiting_approval';
                    t.successful = false;
                    if (pv) {
                        t.resultPreview = pv;
                    }
                }
            };
            (live.segments || []).forEach((seg) => {
                if (seg.kind === 'tools') {
                    (seg.tools || []).forEach(mark);
                }
            });
            renderTranscript();
            updateHeaderFromSession();
        }

        /**
         * @param {object} live
         * @param {object} data
         */
        function applyToolPhaseEventToLive(live, data) {
            const phase = String(data.phase || '');
            const tid = String(data.tool_call_id || '');
            if (phase === 'calling') {
                recordToolCallingToLive(live, data);
                return;
            }
            if (phase === 'awaiting_approval') {
                let row = findToolRowByCallId(live, tid);
                if (!row) {
                    recordToolCallingToLive(live, data);
                    row = findToolRowByCallId(live, tid);
                }
                if (row) {
                    row.phase = 'awaiting_approval';
                    row.successful = false;
                    const rp = data.result_preview != null ? String(data.result_preview) : '';
                    if (rp) {
                        row.resultPreview = rp;
                    }
                }
                return;
            }
            if (phase === 'rejected') {
                const reason = data.error != null ? String(data.error) : (data.result_preview != null ? String(data.result_preview) : '');
                markToolRejectedByCallId(live, tid, reason);
                return;
            }
            if (phase === 'done') {
                recordToolDoneToLive(live, data);
            }
        }

        function bindAiOpsSseHandlers(es, id) {
            es.addEventListener('approval_required', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    const live = ensureLiveStruct(id);
                    const tcid = String(data.tool_call_id || '').trim();
                    if (tcid) {
                        approvalToolCallByRunId[id] = tcid;
                    }
                    markCallingToolsAwaitingApproval(live, text.toolPendingApprovalResult);
                    renderTranscript();
                    showToolApprovalModal(Number(id), {
                        id: data.approval_id,
                        tool_call_id: tcid,
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
                    applyDeltaToLive(live, t);
                    live.awaitingModelAfterTools = false;
                    if (t) {
                        live.streamPending = false;
                        live.streamConnected = true;
                    }
                    const runRow = state.currentSession?.runs?.find((r) => String(r.id) === String(id));
                    if (runRow) {
                        syncRunAssistantTimelineFromLiveBuffer(runRow);
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
                    if (!Array.isArray(live.segments)) {
                        live.segments = [];
                    }
                    live.awaitingModelAfterTools = false;
                    applyToolPhaseEventToLive(live, data);
                    const runRowTool = state.currentSession?.runs?.find((r) => String(r.id) === String(id));
                    if (runRowTool) {
                        syncRunAssistantTimelineFromLiveBuffer(runRowTool);
                    }
                    if (data.phase === 'done') {
                        const run = state.currentSession?.runs?.find((r) => String(r.id) === String(id));
                        const st = run ? String(run.status || '') : '';
                        const anyCalling = collectLiveToolsFlat(live).some((x) => x.phase === 'calling');
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
                            if (liveSnap && timelineHasDisplayableContent(liveSnap)) {
                                const compact = compactLiveTimelineForDisplay(liveSnap);
                                run = { ...run, assistant_timeline: {
                                    completedRounds: [],
                                    segments: compact.segments || [],
                                } };
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
            state.liveByRunId[id] = createEmptyLiveStruct(true);
            const es = new EventSource(urls.stream(id), { withCredentials: true });
            state.eventSource = es;
            bindAiOpsSseHandlers(es, id);
        }

        function openResumeEventSourceFromUrl(fullUrl, runId) {
            const id = String(runId);
            const prior = state.liveByRunId[id];
            const resumed = createEmptyLiveStruct(true);

            if (prior && typeof prior === 'object') {
                const normalized = Array.isArray(prior.segments) ? prior : migrateLegacyLiveSnapshot(prior);
                resumed.completedRounds = (normalized.completedRounds || []).map((round) => ({
                    segments: cloneTimelineSegments(round.segments),
                }));
                resumed.segments = cloneTimelineSegments(normalized.segments || []);
                resumed.preToolTextSnapshot = String(normalized.preToolTextSnapshot || '');
                resumed.textLocked = false;
                flattenLiveTimelineRounds(resumed);
            } else {
                resumed.completedRounds = seedCompletedRoundsFromSessionRun(runId);
            }

            resumed.resumeStreamActive = true;
            resumed.streamPending = true;
            resumed.streamConnected = false;
            closeRunEventSource();
            state.activeStreamRunId = Number(runId);
            state.liveByRunId[id] = resumed;
            renderTranscript();
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
            if (els.networkMode) {
                els.networkMode.disabled = on || els.model?.disabled || !webSearchKeyConfigured;
            }
        }

        function scrollMessagesToBottom() {
            if (!els.messages) return;
            els.messages.scrollTop = els.messages.scrollHeight;
        }

        /**
         * 为会话列表内动态插入的 Lucide 图标执行渲染。
         */
        function refreshSessionListIcons() {
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function' && els.sessions) {
                lucide.createIcons({ root: els.sessions });
            }
        }

        function renderSessionList() {
            if (!els.sessions) return;
            const q = (els.filter?.value || '').trim().toLowerCase();
            const items = state.sessions.filter((s) => !q || String(s.title || '').toLowerCase().includes(q));
            els.sessions.innerHTML = items.map((s) => {
                const active = state.currentSession && Number(state.currentSession.id) === Number(s.id);
                const deleteLabel = escapeHtml(text.deleteSession || 'Delete');
                return `<div class="group flex w-full items-center gap-0.5 rounded-xl border text-sm transition ${active ? 'border-indigo-300 bg-indigo-50/90 shadow-sm' : 'border-transparent bg-white/70 hover:border-slate-200 hover:bg-white'}">
                    <button type="button" data-session-id="${s.id}" class="flex min-w-0 flex-1 flex-col px-3 py-2.5 text-left">
                        <span class="truncate font-medium text-slate-900">${escapeHtml(s.title)}</span>
                        <span class="mt-0.5 truncate text-xs text-slate-500">${escapeHtml(s.updated_at || '')}</span>
                    </button>
                    <button type="button" data-session-delete-id="${s.id}" class="mr-1.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 opacity-0 transition-colors hover:bg-rose-50 hover:text-rose-600 group-hover:opacity-100 focus:opacity-100" title="${deleteLabel}" aria-label="${deleteLabel}">
                        <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                    </button>
                </div>`;
            }).join('') || `<div class="px-1 py-6 text-center text-xs text-slate-400">${escapeHtml(text.emptyState)}</div>`;

            els.sessions.querySelectorAll('[data-session-id]').forEach((btn) => {
                btn.addEventListener('click', () => selectSession(Number(btn.getAttribute('data-session-id'))));
            });
            els.sessions.querySelectorAll('[data-session-delete-id]').forEach((btn) => {
                btn.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    destroySession(Number(btn.getAttribute('data-session-delete-id'))).catch((e) => alert(e.message));
                });
            });
            refreshSessionListIcons();
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
                const live = resolveLiveTimelineForRun(run);
                let bodyInner = '';
                if (st === 'completed') {
                    if (timelineHasDisplayableContent(live)) {
                        bodyInner = buildAssistantLiveBodyHtml(st, live);
                    }
                    if (!bodyInner.trim() && run.client_ai_ops_body_html) {
                        bodyInner = run.client_ai_ops_body_html;
                    }
                    if (!bodyInner.trim()) {
                        const summary = String(run.result_summary || '').trim();
                        const fallback = summary || plainTextFromSegments(live.segments).trim();
                        if (fallback) {
                            bodyInner = renderAssistantMarkdownHtml(fallback);
                        }
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
                    const oldTlScore = timelineContentScore(old.assistant_timeline);
                    const newTlScore = timelineContentScore(r.assistant_timeline);
                    if (old.assistant_timeline && (!r.assistant_timeline || newTlScore < oldTlScore)) {
                        merged.assistant_timeline = old.assistant_timeline;
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

        /**
         * 删除会话；若删除当前会话则关闭流并清空对话区。
         *
         * @param {number} sessionId
         */
        async function destroySession(sessionId) {
            const id = Number(sessionId);
            if (!id) {
                return;
            }
            const msg = String(text.confirmDeleteSession || '').trim();
            if (msg && !window.confirm(msg)) {
                return;
            }
            await request(urls.sessionDestroy(id), { method: 'DELETE' });
            const wasCurrent = state.currentSession && Number(state.currentSession.id) === id;
            if (wasCurrent) {
                closeRunEventSource();
                state.currentSession = null;
                state.liveByRunId = {};
                renderTranscript();
                updateHeaderFromSession();
            }
            await loadSessions();
            if (wasCurrent && state.sessions.length > 0) {
                await selectSession(Number(state.sessions[0].id));
            }
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
                showToolApprovalModal(rid, {
                    ...run.approval_pending,
                    tool_call_id:
                        run.approval_pending.tool_call_id
                        || approvalToolCallByRunId[String(rid)]
                        || '',
                });
            }
        });

        document.getElementById('ai-ops-tool-approval-approve-btn')?.addEventListener('click', () => {
            if (!pendingApprovalCtx) return;
            const ctx = { ...pendingApprovalCtx };
            const url = aiOpsHttp.approveUrl(ctx.runId, ctx.approvalId);
            if (!url) {
                alert(text.networkError);
                return;
            }
            const rid = ctx.runId;
            const toolName = ctx.toolName || '';
            hideToolApprovalModal();
            setComposerLoading(true);
            const live = ensureLiveStruct(rid);
            const toolCallId = resolveApprovalToolCallId(live, rid, ctx.toolCallId, toolName);
            if (toolCallId) {
                approvalToolCallByRunId[String(rid)] = toolCallId;
                markToolPhaseByCallId(live, toolCallId, 'executing', { successful: false, toolName });
                syncRunAssistantTimelineFromLiveBuffer(
                    state.currentSession?.runs?.find((r) => Number(r.id) === Number(rid)) || { id: rid },
                );
                renderTranscript();
            }
            request(url, { method: 'POST', body: JSON.stringify({}) })
                .then((res) => {
                    const liveAfter = ensureLiveStruct(rid);
                    const resolvedId = resolveApprovalToolCallId(liveAfter, rid, toolCallId, toolName);
                    if (resolvedId && res && (res.executed_this_request || res.already_executed)) {
                        const okPreview = res.executed_output_preview != null ? String(res.executed_output_preview) : '';
                        let successful = true;
                        if (okPreview.includes('"ok":false') || okPreview.includes('"ok": false')) {
                            successful = false;
                        }
                        recordToolDoneToLive(liveAfter, {
                            phase: 'done',
                            tool_call_id: resolvedId,
                            tool_name: toolName,
                            successful,
                            result_preview: okPreview,
                        });
                    }
                    if (res && res.run && state.currentSession?.runs) {
                        const runs = [...state.currentSession.runs];
                        const idx = runs.findIndex((r) => Number(r.id) === Number(res.run.id));
                        if (idx >= 0) {
                            const merged = { ...res.run };
                            delete merged.assistant_timeline;
                            runs[idx] = merged;
                            syncRunAssistantTimelineFromLiveBuffer(runs[idx]);
                        }
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
                .catch((e) => {
                    setComposerLoading(false);
                    alert(e.message);
                });
        });

        document.getElementById('ai-ops-tool-approval-reject-btn')?.addEventListener('click', () => {
            if (!pendingApprovalCtx) return;
            const ctx = { ...pendingApprovalCtx };
            const url = aiOpsHttp.rejectUrl(ctx.runId, ctx.approvalId);
            if (!url) {
                alert(text.networkError);
                return;
            }
            const rid = ctx.runId;
            const reason = (document.getElementById('ai-ops-tool-approval-reason')?.value || '').trim();
            const toolName = ctx.toolName || '';
            hideToolApprovalModal();
            setComposerLoading(true);
            const live = ensureLiveStruct(rid);
            const toolCallId = resolveApprovalToolCallId(live, rid, ctx.toolCallId, toolName);
            if (toolCallId) {
                markToolRejectedByCallId(live, toolCallId, reason || text.toolPhaseRejected);
                syncRunAssistantTimelineFromLiveBuffer(
                    state.currentSession?.runs?.find((r) => Number(r.id) === Number(rid)) || { id: rid },
                );
                renderTranscript();
            }
            request(url, { method: 'POST', body: JSON.stringify({ reason }) })
                .then((res) => {
                    if (res && res.run && state.currentSession?.runs) {
                        const runs = [...state.currentSession.runs];
                        const idx = runs.findIndex((r) => Number(r.id) === Number(res.run.id));
                        if (idx >= 0) {
                            const merged = { ...res.run };
                            delete merged.assistant_timeline;
                            runs[idx] = merged;
                            syncRunAssistantTimelineFromLiveBuffer(runs[idx]);
                        }
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
                .catch((e) => {
                    setComposerLoading(false);
                    alert(e.message);
                });
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
                    web_search_enabled: isNetworkModeEnabled(),
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
}

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('ai-ops-config');
    if (!el) {
        return;
    }
    try {
        initAdminAiOps(JSON.parse(el.textContent || '{}'));
    } catch (e) {
        console.error('ai-ops config parse failed', e);
    }
});
