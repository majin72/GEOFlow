/**
 * external-fetch-bridge —— GEOFlow 服务端 ↔ 本地浏览器抓取的 HTTP 适配器
 *
 * 链路：
 *   PHP ExternalFetchService → SSH 反向隧道(server:19826) → 本进程(localhost:19826)
 *                                                       → opencli → Chrome（真实登录态）
 *
 * 端点：
 *   GET  /health  —— 不鉴权，用于 autossh / 服务端 ping / 监控
 *   POST /fetch   —— Bearer Token 鉴权，body: { url: string }
 *
 * 设计要点：
 *   - 使用 execFile（不走 shell）避免 url 包含特殊字符时的命令注入
 *   - opencli 实测要求 Node ≥21，通过显式 NODE24_BIN 绕过全局 v20 入口的 webidl 报错
 *   - 并发限流（MAX_CONCURRENT）保护本地 Chrome 不被同时多页面拖垮
 *   - 单次超时 FETCH_TIMEOUT_MS，必须小于服务端 ExternalFetchService timeout，避免悬挂
 *   - tmpDir 抓取后无论成败都清理，避免磁盘累积
 *
 * 详见 docs/external-fetch-plan.md §3、§5.2。
 */

import express from 'express';
import { execFile } from 'child_process';
import { promisify } from 'util';
import { readFile, rm, mkdir } from 'fs/promises';
import { glob } from 'glob';
import os from 'os';
import path from 'path';
import dotenv from 'dotenv';

dotenv.config();

const execFileAsync = promisify(execFile);

/**
 * 解析整数环境变量并保证为正数；非法时返回 fallback。
 *
 * @param {string | undefined} raw
 * @param {number} fallback
 * @returns {number}
 */
function intEnv(raw, fallback) {
    const value = parseInt(String(raw ?? ''), 10);
    return Number.isFinite(value) && value > 0 ? value : fallback;
}

const PORT = intEnv(process.env.BRIDGE_PORT, 19826);
const HOST = process.env.BRIDGE_HOST || '127.0.0.1';
const TOKEN = (process.env.BRIDGE_TOKEN || '').trim();
const NODE_BIN = (process.env.NODE24_BIN || '').trim();
const OPENCLI_ENTRY = (process.env.OPENCLI_ENTRY || '').trim();
const MAX_CONCURRENT = intEnv(process.env.MAX_CONCURRENT, 2);
const FETCH_TIMEOUT_MS = intEnv(process.env.FETCH_TIMEOUT_MS, 55_000);
const TMP_ROOT = path.join(os.tmpdir(), 'opencli-bridge');

// 启动前置检查：缺失关键配置则立即 crash，避免上线后悄无声息地永远 500
const fatal = (msg) => {
    console.error(`[bridge] FATAL: ${msg}`);
    process.exit(1);
};
if (!TOKEN) fatal('BRIDGE_TOKEN must be set in .env (non-empty)');
if (!NODE_BIN) fatal('NODE24_BIN must be set in .env (path to Node ≥21 binary)');
if (!OPENCLI_ENTRY) fatal('OPENCLI_ENTRY must be set in .env (path to opencli shim)');

const app = express();
app.use(express.json({ limit: '64kb' }));

/** 当前正在执行的抓取任务数；用于限流和 /health 上报 */
let activeJobs = 0;

// /health 必须在鉴权中间件之前，便于无 token 的探针/隧道保活检测
app.get('/health', (_req, res) => {
    res.json({
        ok: true,
        hostname: os.hostname(),
        activeJobs,
        capacity: Math.max(0, MAX_CONCURRENT - activeJobs),
        maxConcurrent: MAX_CONCURRENT,
    });
});

// Bearer Token 鉴权（仅对后续注册的路由生效）
app.use((req, res, next) => {
    const auth = req.headers.authorization || '';
    if (auth !== `Bearer ${TOKEN}`) {
        return res.status(401).json({ error: 'unauthorized' });
    }
    next();
});

/**
 * POST /fetch
 * Body: { url: string }
 * 200: { markdown, format, node, fetched_at, elapsed_ms }
 * 400: 缺 url
 * 503: 已达并发上限
 * 500: opencli 失败 / 超时 / 无产物
 */
app.post('/fetch', async (req, res) => {
    if (activeJobs >= MAX_CONCURRENT) {
        return res.status(503).json({
            error: 'busy',
            activeJobs,
            maxConcurrent: MAX_CONCURRENT,
        });
    }

    const url = typeof req.body?.url === 'string' ? req.body.url.trim() : '';
    if (!url) {
        return res.status(400).json({ error: 'url required' });
    }

    activeJobs++;
    const jobId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const tmpDir = path.join(TMP_ROOT, jobId);
    const startedAt = Date.now();

    try {
        await mkdir(tmpDir, { recursive: true });

        // 不走 shell，直接 execFile 把参数作为 argv 传递，避免 url 中的 &/;/$/反引号触发注入
        await execFileAsync(
            NODE_BIN,
            [
                OPENCLI_ENTRY,
                'web', 'read',
                '--url', url,
                '--output', tmpDir,
                '--download-images', 'false',
                '-f', 'json',
            ],
            {
                timeout: FETCH_TIMEOUT_MS,
                killSignal: 'SIGTERM',
                maxBuffer: 32 * 1024 * 1024,
            }
        );

        // opencli 把产物写到 tmpDir 下的子目录里（以域名/路径命名），抓取 *.md 第一个文件即可
        const files = await glob(`${tmpDir}/**/*.md`, { absolute: true });
        if (files.length === 0) {
            throw new Error('opencli produced no markdown output');
        }

        const markdown = await readFile(files[0], 'utf8');

        res.json({
            markdown,
            format: 'markdown',
            node: os.hostname(),
            fetched_at: Date.now(),
            elapsed_ms: Date.now() - startedAt,
        });
    } catch (err) {
        const msg = err?.message || String(err);
        console.error(`[bridge] fetch failed jobId=${jobId} url=${url} elapsed=${Date.now() - startedAt}ms err=${msg}`);
        res.status(500).json({ error: msg, jobId });
    } finally {
        activeJobs--;
        // 清理临时目录但不阻塞响应；清理失败只记录不抛
        rm(tmpDir, { recursive: true, force: true }).catch((cleanupErr) => {
            console.warn(`[bridge] tmpDir cleanup failed: ${cleanupErr?.message ?? cleanupErr}`);
        });
    }
});

const server = app.listen(PORT, HOST, () => {
    console.log(
        `[bridge] listening on http://${HOST}:${PORT}`
        + `  max_concurrent=${MAX_CONCURRENT}`
        + `  fetch_timeout_ms=${FETCH_TIMEOUT_MS}`
    );
});

// 优雅退出：收到信号后停止接受新连接、等待现有响应完成，再 exit
for (const sig of ['SIGINT', 'SIGTERM']) {
    process.on(sig, () => {
        console.log(`[bridge] received ${sig}, draining...`);
        server.close(() => process.exit(0));
        // 兜底 5s 强制退出，防止悬挂连接卡住
        setTimeout(() => process.exit(0), 5_000).unref();
    });
}
