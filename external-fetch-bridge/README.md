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

## SSH 反向隧道 (autossh)

把"在你 macOS 上跑的 Bridge"暴露给"远程 Linux 服务器上的队列 worker"，又不开公网端口。Bridge 启动后只监听 `127.0.0.1:19826`，由本机发起一条**反向**隧道到服务器，让服务器侧的 `127.0.0.1:19826` 实际指向你本机的 Bridge。

```
┌──────────── 服务器 (Linux) ────────────┐         ┌──────────── 本机 (macOS) ────────────┐
│  queue worker                          │         │  autossh ─ ssh -N -R ...             │
│    └ Http::post(127.0.0.1:19826) ──────┼─tunnel──┼─→ bridge (127.0.0.1:19826)           │
│                                        │         │       └ opencli + Chrome             │
└────────────────────────────────────────┘         └──────────────────────────────────────┘
```

### 1. 本机安装 autossh

```bash
brew install autossh
```

### 2. 先用原生 ssh 验证一次通

替换 `ecs-user@your-server.com` 为你实际的 SSH 用户和主机：

```bash
# 本机执行：建立反向隧道；保持窗口不关
ssh -N -R 19826:127.0.0.1:19826 ecs-user@your-server.com

# 服务器执行（另开终端）：应能拿到本机 bridge 的 /health 响应
ssh ecs-user@your-server.com 'curl -s http://127.0.0.1:19826/health'
```

预期返回 `{"ok":true,"hostname":"...MacBook-Pro.local",...}`，证明隧道走通了。然后 `Ctrl+C` 关掉这次手动 ssh。

### 3. 用 autossh 做保活

```bash
autossh -M 0 -N \
  -o "ServerAliveInterval=30" \
  -o "ServerAliveCountMax=3" \
  -o "ExitOnForwardFailure=yes" \
  -R 19826:127.0.0.1:19826 \
  ecs-user@your-server.com
```

参数详解：

| 参数 | 作用 |
|---|---|
| `-M 0` | 关闭 autossh 自带的端口探测，改用 SSH 原生心跳，更轻量 |
| `-N` | 只建隧道，不开远程 shell |
| `ServerAliveInterval=30` | 每 30s 发一次心跳包，及时探测断线 |
| `ServerAliveCountMax=3` | 连续 3 次心跳无响应即视为断连，autossh 立刻重连 |
| `ExitOnForwardFailure=yes` | 端口转发失败立刻退出（防止"连上了但隧道没建起来"的哑壳进程） |
| `-R 19826:127.0.0.1:19826` | 把服务器 `127.0.0.1:19826` 反向映射到本机 `127.0.0.1:19826` |

### 4. 开机自启（macOS launchd）

`~/Library/LaunchAgents/com.geoflow.external-fetch-bridge.tunnel.plist`：

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.geoflow.external-fetch-bridge.tunnel</string>
    <key>ProgramArguments</key>
    <array>
        <string>/opt/homebrew/bin/autossh</string>
        <string>-M</string><string>0</string>
        <string>-N</string>
        <string>-o</string><string>ServerAliveInterval=30</string>
        <string>-o</string><string>ServerAliveCountMax=3</string>
        <string>-o</string><string>ExitOnForwardFailure=yes</string>
        <string>-o</string><string>StrictHostKeyChecking=accept-new</string>
        <string>-R</string><string>19826:127.0.0.1:19826</string>
        <string>ecs-user@your-server.com</string>
    </array>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
    <key>EnvironmentVariables</key>
    <dict>
        <key>AUTOSSH_GATETIME</key><string>0</string>
    </dict>
    <key>StandardOutPath</key>
    <string>/Users/YOUR_USER/Library/Logs/external-fetch-tunnel.log</string>
    <key>StandardErrorPath</key>
    <string>/Users/YOUR_USER/Library/Logs/external-fetch-tunnel.err</string>
</dict>
</plist>
```

> Intel Mac 把 `/opt/homebrew/bin/autossh` 改成 `/usr/local/bin/autossh`；`YOUR_USER` 改成你的用户名。`AUTOSSH_GATETIME=0` 让 autossh 一启动就进入"持续重连"模式，不要求首次连接成功后才开始保活。

加载 / 卸载 / 查看：

```bash
launchctl load -w ~/Library/LaunchAgents/com.geoflow.external-fetch-bridge.tunnel.plist
launchctl list | grep external-fetch-bridge
launchctl unload ~/Library/LaunchAgents/com.geoflow.external-fetch-bridge.tunnel.plist
```

### 5. 服务器侧 GEOFlow 配置

后台 → 网站设置 → 外部浏览器抓取，把 endpoint 填成隧道目的地：

```
http://127.0.0.1:19826
```

Token 与本机 `.env` 中 `BRIDGE_TOKEN` 一致即可。

### 6. 排障

| 现象 | 排查 |
|---|---|
| autossh 启动即退出 | 用 `ssh -vN -R ...` 手动跑一次看具体报错；多半是 key 不通 / `ExitOnForwardFailure=yes` 检测到端口被占 |
| 服务器 `curl 127.0.0.1:19826/health` connection refused | 隧道掉了；`ps aux \| grep autossh` 看本机进程是否在；查 launchd 日志 `tail -f ~/Library/Logs/external-fetch-tunnel.err` |
| 服务器 19826 端口已被其他服务占用 | 换一个对端端口：`-R 29826:127.0.0.1:19826`，对应改 GEOFlow endpoint 为 `http://127.0.0.1:29826` |
| 多台服务器共用 | 给每台机器跑一条 autossh，对端端口可以都用 19826（彼此互相隔离） |

### 7. 安全要点

- 服务器 `sshd_config` 默认 `GatewayPorts no`，隧道端口只绑定到服务器 `127.0.0.1`，**外部公网不可达**，这是必须保留的默认值。
- 强烈建议给 autossh 准备一把**专用 SSH key**（不要复用日常登录 key），在服务器 `~/.ssh/authorized_keys` 该 key 行前加：

  ```
  command="echo only-tunnel",no-pty,no-agent-forwarding,no-X11-forwarding,permitlisten="127.0.0.1:19826" ssh-ed25519 AAA... bridge-tunnel
  ```

  这把 key 即使泄露也只能开 19826 反向隧道，登不了 shell。
- autossh 本身**没有加密**——加密是 SSH 提供的。换言之这条隧道全程 AES。

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

