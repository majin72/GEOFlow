# GEO 引用度 POC（Stage 1）

基于 [Scrapling](https://github.com/D4Vinci/Scrapling) 验证 **豆包 / DeepSeek / 腾讯元宝** 在登录态浏览器中的可采集性与引用抽取方式。

本目录是独立 POC，不接入 Laravel 队列；验证通过后再进入 sidecar 集成阶段。

## 目标

1. 人工登录后持久化 browser profile
2. 自动发送测试问题
3. 抽取回答文本与引用来源
4. 保存截图 / HTML / JSON / Markdown 报告
5. 输出三平台可采集性结论

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
- `proxy`：可选，格式 `http://user:pass@host:port`

## 操作流程（推荐顺序）

### Step 0：人工过验证码（浏览器不会自动关）

```bash
./run.sh captcha --platform doubao --account-id doubao_guest
```

流程：

1. 弹出可见浏览器
2. 在页面里完成验证码（可顺便登录）
3. **回到终端按 Enter** 才会关闭并保存 profile

过验证码后再跑 ask：

```bash
./run.sh ask --platform doubao -q "你的问题" --account-id doubao_guest
```

### Step 1：豆包访客模式快速测试（无需登录）

出现验证码时，**不要加 `--headless`**，终端会提示你在浏览器里完成验证后按 Enter：

```bash
./run.sh ask --platform doubao -q "企业知识库系统有哪些主流方案？请说明优缺点" --account-id doubao_guest
```

说明：

- 默认打开**可见浏览器**，方便观察输入和回答过程
- 不需要先跑 `login`
- 会自动使用 `profiles/doubao_guest/` 作为浏览器 profile

调试 selector 时可先保存 DOM：

```bash
./run.sh discover --platform doubao
```

### Step 2：需要登录的平台（DeepSeek / 元宝）

```bash
./run.sh login --platform deepseek --accounts accounts.json
./run.sh login --platform yuanbao --accounts accounts.json
```

### Step 3：单题测试（任意平台）

```bash
./run.sh ask --platform doubao -q "你的问题"
./run.sh ask --platform deepseek -q "你的问题" --require-login
```

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

POC 阶段可先不做 Docker 化；Stage 2 sidecar 再封装 noVNC 镜像。

### 方案 C：验证码复现时的处理

| 现象 | 处理 |
|------|------|
| 无头报验证码 / 回答为空 | 重新跑 `captcha` 更新 profile |
| 换 IP / 换机器 | 可能再次触发验证码，需重做方案 A 或 B |
| 长期稳定采集 | 固定账号 + 固定 profile + 固定代理，低频请求 |

**结论**：验证码无法在无头 Linux 里「自动跳过」，只能 **先在有界面环境过一次 → 保存 profile → 服务器无头复用**；失效后再人工更新 profile。

---

## 无头模式（headless）

**前提**：同一 `profile` 已在有界面模式下通过 `captcha` 或成功 `ask` 至少一次。

```bash
./run.sh ask --platform doubao \
  -q "用三句话总结 SaaS 知识库的优缺点" \
  --account-id doubao_guest \
  --headless \
  --no-interactive
```

说明：

- `--headless`：不弹浏览器窗口
- `--no-interactive`：不在终端等验证码（无头无法人工操作）
- 若 profile 失效出现验证码，无头会失败，需重新跑 `captcha`

批量探测：

```bash
./run.sh probe --platform doubao --accounts accounts.json \
  --limit 1 --headless --delay-seconds 15
```

（`probe` 同样支持 `--headless --no-interactive`。）

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
- 一个账号建议固定一个 `proxy`
- 不要在同一 profile 里切换多个平台账号
- probe 默认 `--delay-seconds 15`，避免触发频控

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

## 下一步

POC 通过后进入 Stage 2：

- 封装 Scrapling HTTP sidecar
- Laravel `ScraplingBridgeClient`
- 账号/profile/代理资源池表
- 后台 `geo-monitoring` 页面

## 参考

- Scrapling: https://github.com/D4Vinci/Scrapling
- GEOFlow 外部抓取方案: [`docs/external-fetch-plan.md`](../../docs/external-fetch-plan.md)
