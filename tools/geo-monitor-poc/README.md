# GEO 引用度 POC（Stage 1）

基于 [Scrapling](https://github.com/D4Vinci/Scrapling) 验证 **豆包 / DeepSeek / 腾讯元宝** 在授权登录态、低频测试场景中的 GEO 引用观测与来源抽取方式。

本目录是独立 POC，不接入 Laravel 队列；验证通过后再进入 sidecar 集成阶段。本工具仅用于自有账号、自有品牌和内部研究验证，不提供第三方平台数据转售、代采集或商业化监测服务。

> **合规提醒**：使用前请阅读 [`docs/geo-monitoring-compliance.md`](../../docs/geo-monitoring-compliance.md)。请遵守目标平台服务条款和适用法律法规，不得用于绕过验证码、规避风控、批量账号池、代理池滥用、大规模抓取、转售数据或公开分发第三方平台回答正文/截图/HTML。

## 目标

1. 人工登录后持久化 browser profile
2. 低频发送自有品牌或研究用途的测试问题
3. 抽取回答中的引用来源与必要统计信息
4. 在调试或排障需要时保存截图 / HTML / JSON / Markdown 报告
5. 输出三平台 GEO 引用观测结果

## 目录结构

```text
tools/geo-monitor-poc/
  accounts.sample.json      # 账号/profile/proxy 模板
  prompts.sample.json       # 测试问题集
  geo_monitor_poc/          # Python 包
  scripts/setup.sh          # 一键安装依赖
  profiles/                 # 浏览器 profile（gitignore）
  evidence/                 # 证据与报告（gitignore）
```

## 环境要求

- Python 3.10+
- Linux / macOS（服务器建议 Ubuntu 22.04+）
- 可访问目标 AI 平台 Web 页面
- 如需服务器 GUI 登录，建议额外准备 noVNC（见下文）

## 快速开始

```bash
cd tools/geo-monitor-poc
chmod +x scripts/setup.sh run.sh
./scripts/setup.sh
cp accounts.sample.json accounts.json
```

> **注意**：请使用 `./run.sh` 或激活 `.venv` 后再运行。  
> 直接用 conda `(base)` 的 `python` 会报 `No module named 'scrapling'`。

按需编辑 `accounts.json`：

- `profile_dir`：每个账号独立目录
- `proxy`：可选，格式 `http://user:pass@host:port`。仅应使用自己有权使用的网络环境，不应使用代理池规避平台访问限制。

## 操作流程（推荐顺序）

### 验证码：生产（无头）vs 本地调试

POC 通过 DOM 检测验证码（`#captcha_container`、verifycenter `iframe`、「滑动验证」等）。  
**生产环境必须无头**，无法在 headless 里过滑块，策略是：**快速失败 + 告警 + 人工维护 profile**。

#### 生产（推荐）

```bash
export GEO_MONITOR_WEBHOOK_URL="https://your-hook.example/alert"   # 可选
export GEO_MONITOR_ALERT_FILE="./evidence/alerts.jsonl"              # 可选

./run.sh probe --production --platform doubao \
  --accounts accounts.json --prompts prompts.sample.json
```

- `--production` = `--headless` + 非交互
- 检测到验证码 → `status=captcha_required`，保存截图/HTML，stderr 打印 **`【生产告警】`**
- 可配置 Webhook / 告警文件，供 Laravel 队列或监控消费
- **人工恢复**（同一台机器、同一 `profiles/` 卷）：

```bash
./run.sh captcha --platform doubao --account-id doubao_account_01
# 或 noVNC 里跑 login/captcha，完成后 profile 持久化，再无头重试 probe
```

#### 本地调试（可见浏览器）

不加 `--production` / `--headless` 时，验证码会暂停并提示你在浏览器里处理，终端按 Enter 继续。

检测时机：**页面加载后**、**输入前**、**发送后**、**等待回答中**（约每 1.5s）。

### Step 0：豆包 / DeepSeek / 元宝 — 先登录（推荐）

豆包**访客约 5 轮对话后**会弹出「登录以解锁更多功能」，POC 默认按**登录账号**采集，与 DeepSeek、元宝一致。

```bash
./run.sh login --platform doubao --accounts accounts.json --account-id doubao_account_01
./run.sh login --platform deepseek --accounts accounts.json
./run.sh login --platform yuanbao --accounts accounts.json
```

在弹出浏览器里完成抖音/手机号登录，回到终端按 Enter 保存 `profiles/doubao_account_01/`。

若出现验证码，可先：

```bash
./run.sh captcha --platform doubao --account-id doubao_account_01
```

### Step 1：豆包单题测试（登录态 + 快速模式）

```bash
./run.sh ask --platform doubao \
  -q "企业知识库系统有哪些主流方案？请说明优缺点" \
  --accounts accounts.json \
  --account-id doubao_account_01 \
  --require-login
```

说明：

- `--require-login`：未登录或触发 5 轮访客上限时会报 `needs_login`，不会空跑
- 提问前会确保输入栏为 **「快速」** 模式（不用思考模式）
- 本地调试出现验证码：不要加 `--headless`，按终端提示在浏览器处理
- 生产定时任务：使用 `--production`，验证码走告警 + `captcha` 维护 profile

**访客仅适合临时试跑**（`doubao_guest`，约 5 次内）：

```bash
./run.sh ask --platform doubao -q "你好" --account-id doubao_guest
```

调试 selector：

```bash
./run.sh discover --platform doubao --accounts accounts.json
```

### Step 2：单题测试（任意平台）

```bash
./run.sh ask --platform doubao -q "你的问题" \
  --accounts accounts.json --account-id doubao_account_01 --require-login
./run.sh ask --platform deepseek -q "你的问题" --require-login
./run.sh ask --platform yuanbao -q "你的问题" \
  --accounts accounts.json --account-id yuanbao_account_01 --require-login
```

元宝会在提问前自动：关闭「智能联网」新手引导（点「我知道了」）、尝试打开「联网搜索」或「深度思考」、并等待 AI 回答结束（避免 8 秒被侧栏文案误判为已完成）。

### Step 4：保存 DOM 快照（调 selector）

```bash
python -m geo_monitor_poc discover --platform deepseek --accounts accounts.json
python -m geo_monitor_poc discover --platform doubao --accounts accounts.json
python -m geo_monitor_poc discover --platform yuanbao --accounts accounts.json
```

输出：

- `evidence/discover/<platform>/page.html`
- `evidence/discover/<platform>/page.png`
- `evidence/discover/<platform>/login_status.txt`

若 selector 未命中，根据 `page.html` 更新 [`geo_monitor_poc/config.py`](geo_monitor_poc/config.py) 中对应平台的 `PlatformSelectors`。

### Step 3：执行 smoke probe

先每平台跑 1 题：

```bash
python -m geo_monitor_poc probe \
  --platform all \
  --accounts accounts.json \
  --prompts prompts.sample.json \
  --limit 1 \
  --delay-seconds 15
```

全量 5 题：

```bash
python -m geo_monitor_poc probe \
  --platform all \
  --accounts accounts.json \
  --prompts prompts.sample.json \
  --headless \
  --delay-seconds 20
```

输出：

- `evidence/runs/latest-report.md`
- `evidence/runs/latest-results.json`
- 每题独立 JSON + 截图/HTML/文本证据

证据 PNG 默认对**聊天滚动区域做长截图**（滚动容器内分段截取后纵向拼接），不再只截一屏视口。若平台 DOM 改版导致截不全，请用 `discover` 更新 `config.py` 里的 `screenshot_scroll_selectors`。

## Linux 无图形界面：如何过验证码

Linux 服务器**无法直接弹出本地浏览器窗口**，验证码必须在一台「有界面或远程桌面」的环境里完成一次，然后把 **profile 目录** 带到服务器上复用。

### 方案 A：本机过验证码，再上传 profile（最简单）

在本机 Mac/Windows：

```bash
./run.sh captcha --platform doubao --account-id doubao_guest
# 浏览器里完成验证码 → 终端按 Enter
```

打包上传到服务器：

```bash
tar czf doubao_guest.tgz -C tools/geo-monitor-poc/profiles doubao_guest
scp doubao_guest.tgz user@your-server:/opt/geoflow/tools/geo-monitor-poc/profiles/
ssh user@your-server 'cd /opt/geoflow/tools/geo-monitor-poc/profiles && tar xzf doubao_guest.tgz'
```

服务器上无头运行：

```bash
./run.sh ask --platform doubao -q "你的问题" \
  --account-id doubao_guest --headless --no-interactive
```

### 方案 B：服务器 noVNC 远程桌面（推荐生产）

在 Linux 上跑带虚拟显示 + noVNC 的环境，浏览器通过网页操作：

```text
Xvfb :99 + Chromium + noVNC(6080)
  → 浏览器访问 http://服务器IP:6080
  → 在远程桌面里打开豆包、过验证码
  → 执行 ./run.sh captcha ...（终端里按 Enter 保存 profile）
```

profile 持久化在挂载卷 `profiles/doubao_guest/`，之后同一台机器可 `--headless`。

**双运行环境**（`GEOFLOW_GEO_MONITOR_RUNTIME`）：

| 模式 | 维护方式 |
|------|----------|
| `headless_linux` | `scripts/novnc/*` + noVNC，见 `docs/geo-monitoring-novnc.md` |
| `headed_desktop` | `scripts/headed/maintain-profile.sh`，本机弹出浏览器 |

详见 `docs/geo-monitoring-dual-runtime.md`。

### 方案 C：验证码复现时的处理

| 现象 | 处理 |
|------|------|
| 无头报验证码 / 回答为空 | 重新跑 `captcha` 更新 profile |
| 换 IP / 换机器 | 可能再次触发验证码，需重做方案 A 或 B |
| 长期低频观测 | 固定授权账号 + 固定 profile + 合规网络环境，低频请求 |

**结论**：验证码无法在无头 Linux 里「自动跳过」，本项目也不提供自动破解或绕过验证码的能力。只能 **先在有界面环境人工处理 → 保存 profile → 服务器无头复用**；失效后再人工更新 profile。

---

## 无头模式（headless / production）

**前提**：同一 `profile` 已在有界面环境下通过 `login` / `captcha` 或成功 `ask` 至少一次。

生产批量探测（推荐一条命令）：

```bash
./run.sh probe --production --platform doubao \
  --accounts accounts.json --prompts prompts.sample.json --limit 1
```

等价于 `--headless --no-interactive`。出现验证码时返回 `captcha_required` 并触发告警，不会卡在 `input()`。

单次 ask 生产试跑：

```bash
./run.sh ask --production --platform doubao -q "测试" \
  --account-id doubao_account_01 --require-login
```

若 profile 失效出现验证码：看 stderr 告警与证据截图 → 在同一 profile 卷上跑 `captcha` → 再无头重试。

---

## Linux 服务器登录方式（登录态，非验证码）

### 方案 A：本机先登录，再上传 profile（最简单）

1. 在本地 macOS/Windows 跑 `login` 命令
2. 将 `profiles/<account_id>/` 打包上传到服务器同路径
3. 服务器上直接跑 `discover` / `probe`

注意：跨机器迁移 profile 可能触发平台重新验证。

### 方案 B：服务器 noVNC 可视化登录（推荐生产）

1. 在 sidecar 容器里安装 Chromium + Xvfb + noVNC
2. 通过 `http://服务器IP:6080` 打开远程桌面
3. 在远程浏览器里登录各平台
4. profile 持久化在挂载卷

POC 阶段可先用方案 A，验证通过后再做 Docker 化。

## 引用抽取逻辑

当前 POC 采用两层抽取：

1. **DOM 引用**：从引用卡片/来源链接 selector 提取 `href` 与标题
2. **文本 URL**：从回答正文中正则提取 `http(s)://...`

合并去重后写入 `citations` 字段。

状态含义：

| status | 说明 |
| --- | --- |
| `success` | 有回答且抽到引用 |
| `partial` | 有回答但未抽到引用 |
| `needs_login` | 未登录 |
| `captcha_required` | 出现验证码/安全验证 |
| `selector_miss` | 有页面但 selector 未命中 |
| `failed` | 运行异常 |

## 多账号 / 代理约定

- 一个账号对应一个 `profile_dir`
- 一个账号建议固定一个合规网络环境
- 不要在同一 profile 里切换多个平台账号
- probe 默认 `--delay-seconds 15`，避免触发频控
- 不要使用批量账号池、代理池滥用或任何规避平台访问控制的方式运行本工具

## 常见问题

### 1. `needs_login`

重新执行：

```bash
python -m geo_monitor_poc login --platform <platform> --accounts accounts.json
```

### 2. `selector_miss`

执行 `discover`，更新 `geo_monitor_poc/config.py` 中 selector，再重跑 `probe`。

### 3. headless 下失败、可见浏览器成功

先用非 headless 验证，再在 profile 稳定后切换 `--headless`。

### 4. 服务器无 GUI

使用 noVNC，或本地登录后上传 profile。

## Sidecar HTTP API（Stage 2）

协议文档：[`docs/SIDECAR_API.md`](docs/SIDECAR_API.md)

```bash
export GEO_MONITOR_SIDECAR_TOKEN="your-secret"
./run.sh serve --host 127.0.0.1 --port 8765

# 健康检查
curl -s http://127.0.0.1:8765/health | jq .

# 单次探测
curl -s -X POST http://127.0.0.1:8765/v1/probe \
  -H "Authorization: Bearer $GEO_MONITOR_SIDECAR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"platform":"doubao","account_id":"doubao_account_01","prompt_id":"api_1","prompt_text":"你好","production":true}'
```

## 下一步

POC 通过后进入 Stage 2/3：

- Laravel `ScraplingBridgeClient`（对接 `docs/SIDECAR_API.md`）
- 账号/profile/代理资源池表
- 后台 `geo-monitoring` 页面

## 参考

- Scrapling: https://github.com/D4Vinci/Scrapling
- GEOFlow 外部抓取方案: [`docs/external-fetch-plan.md`](../../docs/external-fetch-plan.md)
