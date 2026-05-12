# 外部浏览器抓取（External Fetch）方案存档

> 版本：v1（草案，未实施）
> 创建：2026-05-12
> 关联代码：暂无（本文档先行存档，等正式开工再生成代码）
> 关联讨论：见 chat 记录 [URL 抓取 403 与 opencli 评估](eb232cee-2fdf-4d2e-90bc-f8bd9ad44297)

---

## 1. 背景与目标

### 1.1 问题

`UrlImportProcessingService::processFetchStep()` 在抓取部分目标站时遇到 **HTTP 403**，例如：

- `https://zhuanlan.zhihu.com/p/665715823`（知乎专栏，JS 挑战）
- `xiaohongshu.com`（笔记详情，需登录态）
- `mp.weixin.qq.com`（公众号文章，部分校验）

这些网站对**匿名服务器请求**做了拦截，常规 `Http::get()` 无法绕过。

### 1.2 目标

1. 短期（最小闭环）：知乎专栏文章能被服务器队列正常采集
2. 中期：覆盖小红书 / 公众号 / 微博 / 搜狐号等中文媒体
3. 长期：架构留有切换余地，未来商业化时可平滑切到云端方案

### 1.3 非目标

- ❌ 大规模（万级/日）抓取
- ❌ 多账号矩阵管理
- ❌ 通用爬虫平台

---

## 2. 决策记录

| # | 决策项 | 选定方案 | 备选方案（已否决） | 理由 |
|---|---|---|---|---|
| D1 | 反爬绕过手段 | ✅ 本地浏览器 + 真实登录态（opencli） | 复制 cookie 到服务器 / 服务器装 Playwright / 第三方爬虫 SaaS | 实测可行（3.5s 拿到 27KB），维护成本最低 |
| D2 | 服务器↔本地通道 | ✅ SSH 反向隧道 + autossh（单机阶段） / Tailscale（集群阶段） | Cloudflare Tunnel / ngrok / Redis 中转 | 零第三方依赖、端到端加密、未来扩 Tailscale 几乎零迁移成本 |
| D3 | 触发时机 | ✅ 域名白名单默认走 + 普通路径遇 `403/429/timeout` 回退 | 仅 fallback / 仅按域名 | 兜底叠加，最稳 |
| D4 | 返回格式 | ✅ Markdown（opencli 原生输出）| HTML / JSON | 服务端 `processPageJsonStep` 加 Markdown 分支，更易解析 |
| D5 | 本地端实现 | ✅ Node.js HTTP 包装器（约 60 行） | PHP / Python / 直接调 opencli daemon | Node 是 opencli 母语，`child_process.exec` 最自然 |
| D6 | Node 版本 | ⚠️ 待定（建议 nvm 切默认到 v24，或专门装隔离版本）| - | opencli ≥21，当前默认 v20 不够 |
| D7 | 集群规模 | ⚠️ 待定（用户尚未明确实际规模）| - | < 100/日 单机；100~2000/日 多机；> 2000/日 切代理路线 |

---

## 3. 整体架构

### 3.1 单机阶段（最小闭环）

```
Linux 服务器
  │
  │  Http::post('http://127.0.0.1:19826/fetch')
  │
  ↓ SSH 反向隧道（autossh + launchd 保活）
  ↓
本地 macOS
  ↓
Node 包装器（端口 19826）
  ↓
opencli daemon（19825）
  ↓
Chrome + opencli Bridge 扩展 + 真人登录态
  ↓
目标网站
```

### 3.2 集群阶段（多节点 + Tailscale）

```
Linux 服务器
  │
  │  pickHealthyNode() → Http::post('http://mac-N.tail-xxx.ts.net:19826/fetch')
  │
  ↓ Tailscale WireGuard mesh
  │
  ┌──────────┴──────────┐
  ↓          ↓          ↓
Node1      Node2      Node3
家(电信)   办公(联通)  4G(移动)
opencli    opencli    opencli
```

通道层（Tailscale）解决「服务器→本地」的连通性；
出口层（不同物理网络）解决「单 IP 频次封禁」。

---

## 4. 实施阶段（Stages）

### Stage 1：服务端配置 + Service 抽象（不动业务逻辑）

**目标**：搭好可独立单测的 `ExternalFetchService` 抽象层。
**Status**：Not Started

**新增文件**：

- `config/external_fetch.php`
  - `driver`（默认 `opencli_local`，预留 `tailscale_pool`、`playwright_remote`）
  - `endpoint` / `token` / `timeout` / `retry_on_status`
  - `domains` 白名单（`zhihu.com,zhuanlan.zhihu.com,xiaohongshu.com,mp.weixin.qq.com`）
- `app/Services/GeoFlow/ExternalFetch/ExternalFetchService.php`
  - `shouldUseExternal(string $url): bool`
  - `fetch(string $url): ExternalFetchResult`
- `app/Services/GeoFlow/ExternalFetch/ExternalFetchResult.php`（值对象）
- `app/Exceptions/GeoFlow/ExternalFetchException.php`
- `.env.example` 增加默认条目

**测试**：

- `tests/Unit/Services/GeoFlow/ExternalFetchServiceTest.php`
  - 白名单匹配（含子域名）
  - HTTP 成功路径
  - 超时/连接失败
  - Token 鉴权失败

**验收**：

- [ ] `php artisan test --filter=ExternalFetchServiceTest` 全绿
- [ ] 现有所有测试不受影响

### Stage 2：接入 fetch 步骤（最小侵入业务）

**目标**：`processFetchStep` 与 `processPageJsonStep` 支持外部抓取分支。
**Status**：Not Started

**修改文件**：

- `database/migrations/xxxx_add_external_fetch_columns_to_url_import_jobs.php`
  - `fetched_markdown`（`text`，可空）
  - `fetch_source`（`enum: direct, external_primary, external_fallback`，默认 `direct`）
- `app/Models/UrlImportJob.php`：加 fillable / casts
- `app/Services/GeoFlow/UrlImportProcessingService.php`
  - 注入 `ExternalFetchService`
  - `processFetchStep()`：
    - 白名单命中 → 直接走 external（标记 `external_primary`）
    - 普通抓取遇 403/429/timeout → 回退 external（标记 `external_fallback`）
    - 全部失败才抛错
  - `processPageJsonStep()`：
    - `fetched_markdown` 非空 → 走 Markdown 提取分支
    - 否则走原 HTML 分支
- `app/Console/Commands/`：新增超时清理命令（处理 awaiting 状态超过 N 分钟的任务）

**测试**：

- 新增 unit：mock `ExternalFetchService`，验证 fetch 步骤分支行为
- 修改现有 feature：模拟 403 → fallback → 拿到 mock Markdown → 流程继续

**验收**：

- [ ] 知乎专栏 URL 全流程跑通（fetch → page_json → knowledge → keywords → titles → preview → commit）
- [ ] 现有非白名单站点（普通博客）行为不变
- [ ] 库名仍能正确取自页面标题（不能因 Markdown 改动破坏现有命名逻辑）

### Stage 3：本地端 Node 包装器

**目标**：本地一份可独立运行、可复制到任意节点的 Node HTTP 服务。
**Status**：Not Started

**新增目录**：`external-fetch-bridge/`

- `package.json`（仅 `express`、`glob` 两个依赖）
- `index.js`（约 60 行，参考第 5 节示意代码）
- `.env.example`（`BRIDGE_TOKEN` / `NODE24_BIN` / `OPENCLI_ENTRY` / `MAX_CONCURRENT`）
- `README.md`（节点首装清单 5 步）
- `com.geoflow.external-fetch-bridge.plist`（macOS launchd 配置模板）
- `install.sh`（一键安装：写 plist、`launchctl load`、健康检查）

**单元能力**：

- `POST /fetch { url }` → 调 opencli → 返回 `{ markdown, format, node, fetched_at }`
- `GET /health` → `{ ok, hostname, activeJobs, capacity }`
- 并发限流：`MAX_CONCURRENT=2`（避免 Chrome 卡死）
- 单次超时 55s（小于服务端 60s）
- Bearer Token 鉴权
- 临时目录写入 `/tmp/opencli-bridge/<jobId>`，处理完即清

**验收**：

- [ ] 本地 `npm start` 启动后 `curl http://localhost:19826/health` 返回正常
- [ ] `curl -X POST .../fetch -H "Authorization: Bearer xxx" -d '{"url":"https://zhuanlan.zhihu.com/p/665715823"}'` 拿到 27KB Markdown
- [ ] launchd 自启验证：`launchctl unload && load` 能正常拉起，崩了能自愈

### Stage 4：SSH 隧道运维文档

**目标**：让任何人能照着把单节点接入服务器，不需要再问。
**Status**：Not Started

**新增**：`external-fetch-bridge/SSH_TUNNEL.md`

- 服务器 `sshd_config` 检查清单（`AllowTcpForwarding yes`）
- 建议创建专用 ssh 用户 `external-fetch`，限制只能 forward
- 本地 autossh 命令模板
- launchd plist 模板（让 autossh 也开机自启）
- 故障排查（telnet 127.0.0.1 19826 / journalctl / 服务器 ss -tnlp）

**验收**：

- [ ] 同事按文档能在 30 分钟内接入一个新节点
- [ ] 服务器侧 `curl http://127.0.0.1:19826/health` 能正常返回

### Stage 5：管理后台展示（可选，按需）

**目标**：让运维能看到外部抓取的状态、节点健康、失败统计。
**Status**：Not Started（可推迟）

**修改/新增**：

- `resources/views/admin/url-import/index.blade.php`：列表加 `fetch_source` 列
- `resources/views/admin/url-import/show.blade.php`：详情卡片新增「外部抓取来源」「等待外部抓取」状态
- `app/Http/Controllers/Admin/ExternalFetchHealthController.php`：节点健康总览页
- `app/Console/Commands/ExternalFetchHealthCheck.php`：调度任务，每分钟 ping 所有节点

**验收**：

- [ ] 后台能看到当前节点池状态
- [ ] 失败 job 能看到失败节点 + 错误信息

---

## 5. 关键代码示意

### 5.1 服务端 Service（Stage 1）

```php
<?php

namespace App\Services\GeoFlow\ExternalFetch;

use App\Exceptions\GeoFlow\ExternalFetchException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 通过本地浏览器（opencli）远程抓取需要登录态/JS 挑战的页面。
 *
 * 通道：默认 SSH 反向隧道，本地包装器监听 127.0.0.1:19826。
 * 集群：未来切换到 Tailscale 节点池时只改 driver 实现，service 接口不变。
 */
class ExternalFetchService
{
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * 判断指定 URL 是否应该走外部浏览器抓取。
     *
     * @param  string  $url
     * @return bool
     */
    public function shouldUseExternal(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        foreach ($this->config['domains'] ?? [] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 通过外部浏览器抓取并返回 Markdown 结果。
     *
     * @param  string  $url
     * @return ExternalFetchResult
     * @throws ExternalFetchException
     */
    public function fetch(string $url): ExternalFetchResult
    {
        try {
            $resp = Http::timeout((int) ($this->config['timeout'] ?? 60))
                ->withToken((string) ($this->config['token'] ?? ''))
                ->post(rtrim((string) $this->config['endpoint'], '/').'/fetch', ['url' => $url]);

            if (! $resp->successful()) {
                throw new ExternalFetchException("Bridge returned HTTP {$resp->status()}");
            }

            $data = $resp->json() ?? [];
            return ExternalFetchResult::fromArray($data);
        } catch (Throwable $e) {
            Log::warning('ExternalFetch failed', ['url' => $url, 'error' => $e->getMessage()]);
            throw new ExternalFetchException($e->getMessage(), 0, $e);
        }
    }
}
```

### 5.2 本地 Node 包装器（Stage 3）

```js
// external-fetch-bridge/index.js
import express from 'express';
import { exec } from 'child_process';
import { promisify } from 'util';
import { readFile, rm, mkdir } from 'fs/promises';
import { glob } from 'glob';
import os from 'os';
import path from 'path';

const execAsync = promisify(exec);
const app = express();
app.use(express.json());

const TOKEN = process.env.BRIDGE_TOKEN || '';
const NODE_BIN = process.env.NODE24_BIN || '/usr/local/bin/node';
const OPENCLI_ENTRY = process.env.OPENCLI_ENTRY;
const MAX_CONCURRENT = parseInt(process.env.MAX_CONCURRENT || '2', 10);
const TMP_ROOT = path.join(os.tmpdir(), 'opencli-bridge');

let activeJobs = 0;

app.use((req, res, next) => {
    if (req.headers.authorization !== `Bearer ${TOKEN}`) return res.status(401).end();
    next();
});

app.get('/health', (_, res) => res.json({
    ok: true,
    hostname: os.hostname(),
    activeJobs,
    capacity: MAX_CONCURRENT - activeJobs,
}));

app.post('/fetch', async (req, res) => {
    if (activeJobs >= MAX_CONCURRENT) return res.status(503).json({ error: 'busy' });
    const { url } = req.body;
    if (!url) return res.status(400).json({ error: 'url required' });

    activeJobs++;
    const tmpDir = path.join(TMP_ROOT, `${Date.now()}-${Math.random().toString(36).slice(2)}`);

    try {
        await mkdir(tmpDir, { recursive: true });
        await execAsync(
            `"${NODE_BIN}" "${OPENCLI_ENTRY}" web read --url "${url}" --output "${tmpDir}" --download-images false -f json`,
            { timeout: 55000 }
        );
        const files = await glob(`${tmpDir}/*/*.md`);
        if (!files[0]) throw new Error('no output file');
        const markdown = await readFile(files[0], 'utf8');
        res.json({ markdown, format: 'markdown', node: os.hostname(), fetched_at: Date.now() });
    } catch (e) {
        res.status(500).json({ error: e.message });
    } finally {
        activeJobs--;
        rm(tmpDir, { recursive: true, force: true }).catch(() => {});
    }
});

app.listen(19826, '0.0.0.0', () => console.log('bridge ready on 19826'));
```

### 5.3 SSH 反向隧道（Stage 4）

```bash
# 本地一行启动（开发态）
ssh -N -R 19826:localhost:19826 deploy@your-server.com

# 生产态用 autossh 保活
autossh -M 0 -N \
    -R 19826:localhost:19826 \
    -o "ServerAliveInterval=30" \
    -o "ServerAliveCountMax=3" \
    -o "ExitOnForwardFailure=yes" \
    deploy@your-server.com
```

服务器侧 `sshd_config`：

```
AllowTcpForwarding yes
GatewayPorts no
```

---

## 6. 风险与应对

| # | 风险 | 概率 | 应对 |
|---|---|---|---|
| R1 | opencli 抓取质量在某些站点不达标 | 中 | Stage 1 之前先手动跑命令验证（**已验证**：知乎专栏 ✅） |
| R2 | 本地 Chrome 卡死 / 登录态过期 | 高 | launchd `KeepAlive` + 定时重启 Chrome；登录态过期监控告警 |
| R3 | SSH 隧道断连导致任务挂起 | 中 | 服务端 `Http::timeout(60)` + autossh 保活；超时直接抛错，job 标记 failed |
| R4 | opencli 命令签名变更 | 低 | 包装器层封装，遇变更只改一处；`opencli doctor` 加入 CI 烟雾测试 |
| R5 | Markdown 噪声（话题/互动数据） | 低 | `processPageJsonStep` 解析时简单过滤底部固定 pattern |
| R6 | 单 IP 频次封禁 | 中 | 升级到第 7 节的多节点方案 |
| R7 | 改 page_json 影响现有任务 | 中 | 新加 `fetched_markdown` 字段后保持向后兼容：空就走原 HTML 路径 |

---

## 7. 后续演进路线

### 7.1 集群化（解决吞吐 + 单 IP 封禁）

**触发条件**：日抓取量 > 100 或单 IP 出现 429。

**改动点**：
- `config/external_fetch.php` 增加 `tailscale_pool` driver
- 新增 `TailscalePoolFetcher` 实现节点池调度（轮询 / LRU / 加权）
- 健康检查 schedule 任务（每分钟 ping 所有节点）
- 节点物理部署：建议跨地域 + 跨运营商（家用电信 / 办公联通 / 4G 移动）

**通道选型对比**（已决策）：

| 通道 | 多机器调度 | 端口管理 | 故障切换 | 推荐度 |
|---|---|---|---|---|
| SSH 反向隧道 | 难（要分配端口） | 手动 | 手动 | 单机阶段 |
| Cloudflare Tunnel | 中（每台独立域名） | 自动 | 自动 | 备选 |
| **Tailscale** | **易（稳定主机名）** | **不需要** | **自动** | **集群首选** |

### 7.2 出口 IP 多样化（解决反爬）

> ⚠️ **关键认识**：通道（Tailscale/SSH）和出口 IP 是两个独立的层。换通道不会换出口 IP。

| 路线 | 成本 | IP 质量 | 适用规模 |
|---|---|---|---|
| 多家庭物理节点 | 高（一次性硬件） | 极高（真实家庭 IP） | 中量 |
| 4G/5G 路由器 | 中 | 高（运营商 NAT 池） | 中量 |
| 商用住宅代理（Bright Data 等） | 高（按流量计费） | 中-高 | 中-大量 |
| VPN 链 | 低 | 低（数据中心 IP，易被识别） | 不推荐 |

### 7.3 商业化切换（脱离本地依赖）

**触发条件**：要做对外 SaaS、不能依赖某台本地机器。

**改动点**：
- 新增 `playwright_remote` driver
- 服务端代码完全不改（`ExternalFetchService` 接口不变）
- 实现可以是：
  - 自建 Playwright 集群（K8s + Browserless 镜像）
  - 第三方 SaaS（Browserless / ScrapingBee / ScraperAPI）
  - 商用爬虫平台（亮数据 SaaS / Apify）

### 7.4 何时该放弃本方案

下列情况出现 ≥ 2 个，应直接跳到 7.3：

- [ ] 日抓取量持续 > 2000
- [ ] 多账号矩阵管理需求
- [ ] 客户 / 多租户场景
- [ ] 抓取站点列表 > 20 个，每个都要适配
- [ ] 知乎/小红书加大风控（指纹检测、行为分析）

---

## 8. 验证记录

### 8.1 opencli 抓取知乎专栏（2026-05-12）

- 命令：`opencli web read --url https://zhuanlan.zhihu.com/p/665715823 --output /tmp/opencli-out --download-images false -f json`
- 耗时：3.5 秒
- 产物：27.2 KB Markdown / 634 行
- 完整性：✅ 标题、正文、参考链接、发布时间、话题标签、互动数据全部保留
- 瑕疵：表格被打散为单元格列表；底部约 30 行话题/赞同/评论噪声
- 结论：**质量达标，可走该方案**

---

## 9. 待决策项（开工前需确认）

- [ ] **D6**：Node 24 怎么管理（默认切换 / 隔离安装 / 包装器写绝对路径）
- [ ] **D7**：实际抓取规模预期（决定是直接做单机还是规划多节点）
- [ ] 服务器域名 / SSH 用户名 / Token 命名约定
- [ ] 后台是否要做 Stage 5 展示（可推迟）
- [ ] 失败任务的最终处理：直接 fail 还是进重试队列

---

## 10. 参考

- opencli：[https://github.com/jackwener/opencli](https://github.com/jackwener/opencli)
- Tailscale：[https://tailscale.com/](https://tailscale.com/)
- Cloudflare Tunnel：[https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/)
- autossh：`brew install autossh`
