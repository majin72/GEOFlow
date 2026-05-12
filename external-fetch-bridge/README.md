# external-fetch-bridge

GEOFlow 服务端 ↔ 本地浏览器抓取的 HTTP 适配器。让运行在 Linux 服务器上的队列任务可以借助本机已登录的 Chrome 抓取需要 JS 挑战 / 登录态的页面（知乎专栏、小红书、微信公众号等）。

完整方案：[`docs/external-fetch-plan.md`](../docs/external-fetch-plan.md)

---

## 工作原理

```
GEOFlow 服务器（Linux）
   ↓  Http::post('http://127.0.0.1:19826/fetch', { url })
   ↓  ↑ SSH 反向隧道（autossh）
本机（macOS）
   ↓
本进程（index.js, port 19826）
   ↓  execFile node24 + opencli web read --url ...
opencli daemon
   ↓
本机 Chrome（带真实登录态）
   ↓
目标网站
```

单机阶段直接 `npm start` 即可；服务器部署阶段再加 SSH 反向隧道与 autossh 保活（详见 §SSH 隧道，Stage 4 文档另立）。

---

## 一次性安装

> 假设你已在本机用 `npm i -g @jackwener/opencli` 装好 opencli，并完成了 `opencli setup` / Chrome 扩展登录。

```bash
cd external-fetch-bridge
cp .env.example .env

# 编辑 .env：必填 BRIDGE_TOKEN / NODE24_BIN / OPENCLI_ENTRY
# 推荐生成 token：
openssl rand -hex 32

# 查询本机 Node 24 路径
ls "/Users/$USER/Library/Application Support/Herd/config/nvm/versions/node/" | grep '^v24'
# 或：nvm which 24 / which node（切到 v24 后执行）

# 查询本机 opencli 入口路径
command -v opencli

npm install
```

---

## 启动

```bash
npm start
# 或开发态自动重启：
npm run dev
```

成功后输出：

```
[bridge] listening on http://127.0.0.1:19826  max_concurrent=2  fetch_timeout_ms=55000
```

---

## 端点

### `GET /health`

无需鉴权。供 autossh / 服务端 ping / 监控使用。

```bash
curl -s http://127.0.0.1:19826/health
```

```json
{
  "ok": true,
  "hostname": "MacBook-Pro.local",
  "activeJobs": 0,
  "capacity": 2,
  "maxConcurrent": 2
}
```

### `POST /fetch`

需要 `Authorization: Bearer <BRIDGE_TOKEN>`。

```bash
curl -s -X POST http://127.0.0.1:19826/fetch \
  -H "Authorization: Bearer $BRIDGE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://zhuanlan.zhihu.com/p/665715823"}' \
  | jq '{ format, node, elapsed_ms, len: (.markdown | length) }'
```

预期：3~6 秒返回，`markdown` 长度约 25K~30K 字符。

返回结构：

```ts
{
  markdown:    string,  // 抓回的 Markdown 内容
  format:      "markdown",
  node:        string,  // os.hostname()，便于多节点定位是哪台机抓的
  fetched_at:  number,  // 毫秒 unix timestamp
  elapsed_ms:  number   // 本次抓取耗时
}
```

HTTP 状态：

| 码 | 含义 |
|---|---|
| `200` | 成功 |
| `400` | body 缺 `url` |
| `401` | Authorization 头缺失 / 不匹配 |
| `503` | 已达 `MAX_CONCURRENT`，请退避后重试 |
| `500` | opencli 内部错 / 超时 / 无产物 |

---

## 环境变量

详见 `.env.example`。最常需要调整的两项：

| 变量 | 默认 | 说明 |
|---|---|---|
| `MAX_CONCURRENT` | `2` | 本地 Chrome 同时打开 N 个会话；CPU/内存富裕可调到 3~4 |
| `FETCH_TIMEOUT_MS` | `55000` | 单次抓取超时（毫秒）。**必须 < 服务端 `external_fetch_timeout` × 1000**，否则服务端先 timeout，本地 opencli 仍在跑会浪费资源 |

---

## 故障排查

| 现象 | 排查 |
|---|---|
| 启动即 `FATAL: NODE24_BIN must be set` | `.env` 没填或路径含空格未生效；用绝对路径，不要包引号 |
| 启动报 `FATAL: BRIDGE_TOKEN must be set` | 同上；token 不能为空字符串 |
| `/fetch` 总是 401 | 客户端 `Authorization: Bearer <token>` 与 `.env` 不一致；token 区分大小写 |
| `/fetch` 报 `opencli produced no markdown output` | opencli 抓回的是非 Markdown 页（罕见）；或目标 URL 被反爬拦截；本机直接跑命令验证：`"$NODE24_BIN" "$OPENCLI_ENTRY" web read --url <URL> --output /tmp/opencli-out --download-images false -f json` |
| `webidl.util.markAsUncloneable is not a function` | `NODE24_BIN` 指向 Node 20 了，必须 ≥ 21；用 `"$NODE24_BIN" -v` 确认 |
| 503 `busy` 频繁 | 调大 `MAX_CONCURRENT` 或排查上游为何并发猛增（队列 worker 数量？） |
| 抓取耗时 > 30s | 目标网站慢 / 本机 Chrome 卡死。重启 Chrome；或临时升 `FETCH_TIMEOUT_MS` |

进一步定位看终端日志：本进程每次抓取失败都会打印 `jobId / url / elapsed / err`，可用于复现。

---

## 安全说明

- Bridge 监听 `127.0.0.1:19826`，**不开公网**。生产期通过 SSH 反向隧道穿透到服务器 `127.0.0.1`，外部不可达。
- `BRIDGE_TOKEN` 是 Bridge ↔ 服务端之间的对称密钥，不要写进任何会进 Docker 镜像 / 推 Git 的文件。`.env` 已在仓库根 `.gitignore`。
- `execFile`（而非 `exec`）确保 URL 内的 shell 元字符不会被解释。

---

## 当前进度（Stage 3 - 完成）

- [x] HTTP 适配器（/health + /fetch）
- [x] 并发限流 + 超时
- [x] Bearer Token 鉴权
- [x] tmpDir 自动清理
- [x] 优雅退出（SIGINT/SIGTERM）
- [ ] launchd 自启（Stage 4 时一起做）
- [ ] SSH 反向隧道 plist（Stage 4）

