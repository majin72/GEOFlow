# 外部浏览器抓取（external-fetch-bridge）部署指南

> 适用范围：把"本机 macOS 上跑的浏览器抓取服务"接入"远程 Linux 服务器上的 GEOFlow 队列容器"，用于绕过知乎/小红书/公众号等站点的 403、登录态拦截。
>
> 关联文档：
>
> - 总体方案：[`../external-fetch-plan.md`](../external-fetch-plan.md)
> - 本机 Bridge：[`../../external-fetch-bridge/README.md`](../../external-fetch-bridge/README.md)
> - 生产部署主线：[`./DEPLOYMENT.md`](./DEPLOYMENT.md)

---

## 1. 为什么这件事不是"启动一个隧道这么简单"

把"本机 Bridge HTTP 服务"暴露给"服务器 Docker 容器"的路径上，有**三道独立的防火墙**会拦截。如果第一次部署没人提醒，每一道都会让你卡半小时：

```
docker 容器 (172.19.0.6)
   │  ① docker iptables：跨 bridge 网络默认隔离（DOCKER-ISOLATION）
   ↓
服务器宿主机网络栈
   │  ② 宿主机 INPUT 链：默认 DROP；ufw / 宝塔安全防火墙白名单只放行 22/80/443 等
   ↓
sshd 监听的 listener
   │  ③ OpenSSH GatewayPorts：默认 `no`，sshd 拒绝把 -R 隧道绑到非 127.0.0.1
   ↓
SSH 反向通道
   ↓
本机 autossh → external-fetch-bridge (127.0.0.1:19826)
   ↓
本机 opencli → Chrome (含真实登录态)
   ↓
目标网站
```

下面按"先验证、再修复"的顺序逐项排查。

---

## 2. 部署前置条件

- 本机已按 [`external-fetch-bridge/README.md`](../../external-fetch-bridge/README.md) 起好 Bridge，`curl http://127.0.0.1:19826/health` 在本机能拿到 JSON
- 本机已装 `autossh`：`brew install autossh`
- 本机能 SSH 登录到服务器（key-based，免密）
- 服务器 GEOFlow 主体已通过 `docker compose --env-file .env.prod -f docker-compose.prod.yml up -d` 起好
- 服务器有 sudo 权限（要改 sshd_config 与 iptables）

---

## 3. 部署流程（10 步，按顺序执行）

整套步骤一次性走完大约 5 分钟，每步都有明确的"预期输出"。**所有 IP（172.19.0.1 等）都不能写死，必须以你这台服务器实际的 `docker network inspect` 结果为准。**

### 3.1 服务器：查 compose 自定义网络的 gateway IP

```bash
sudo docker network inspect -f '{{(index .IPAM.Config 0).Gateway}}' \
  geoflow-laravel-prod_default
```

输出例：

```text
172.19.0.1
```

后面所有命令里的 `172.19.0.1` 都要替换成你这一步实际拿到的值。**这是部署方的环境决定的 IP，不在仓库里写死任何假设。**

> 多台服务器、多次重建 compose 网络，这个 IP 可能不同。每次部署完先跑这条记下来。

### 3.2 服务器：打开 sshd 的 GatewayPorts

OpenSSH 默认只允许 `-R` 隧道绑到 `127.0.0.1`。要让本机 `autossh -R 172.19.0.1:...` 能在服务器的 docker bridge 接口上 listen，必须显式放开：

```bash
sudo sed -i 's/^#*GatewayPorts.*/GatewayPorts clientspecified/' /etc/ssh/sshd_config

grep -E '^GatewayPorts' /etc/ssh/sshd_config
sudo sshd -T 2>/dev/null | grep -i gatewayports
```

两条都应输出：

```text
GatewayPorts clientspecified
gatewayports clientspecified
```

> **为什么用 `clientspecified` 而不是 `yes`**：
> - `clientspecified` = sshd 不主动绑公网，只允许客户端 `-R` 显式指定具体绑定地址
> - `yes` = sshd 会按 `-R port:host:hostport` 绑到 `0.0.0.0`，公网端口直接暴露
>
> `clientspecified` 是同等功能里最安全的选项。

reload 让新配置生效：

```bash
sudo systemctl reload ssh   # Debian/Ubuntu 是 ssh.service
# 红帽系是：sudo systemctl reload sshd
```

reload 不会断开当前会话。

### 3.3 服务器：放行 INPUT 链上 docker bridge 来的 19826 入站

Ubuntu 服务器（尤其装了宝塔/ufw 的）`INPUT` 默认策略是 `DROP`，端口走白名单。本机 Bridge 的反向隧道在服务器 docker bridge IP 上 listen，包从容器进来后会被 INPUT 链直接丢掉。验证：

```bash
sudo iptables -L INPUT -v -n | head -5
```

如果第一行是 `Chain INPUT (policy DROP ...)`，就需要加白：

```bash
sudo iptables -I INPUT 1 -s 172.19.0.0/16 -p tcp --dport 19826 -j ACCEPT
```

- `-I INPUT 1` 插到链顶部，抢在 IN_BT / ufw 之前生效
- `-s 172.19.0.0/16` 只放行来自 compose 自定义网络的源 IP（**用你 3.1 拿到的网段，掩码用 `/16` 或 `/24` 视 docker 实际分配而定**）
- 公网 IP 即使绕到 19826 也不会命中这条 ACCEPT

> ⚠️ 这条规则**重启后丢失**。3.10 步会做持久化。

### 3.4 本机：启动带两条 `-R` 的 autossh

`Ctrl+C` 杀掉之前任何形态的 ssh/autossh，跑：

```bash
GATEWAY=172.19.0.1            # ← 改成 3.1 步拿到的值
SERVER=ecs-user@{ip} # ← 改成你的服务器 SSH 别名 / 用户@主机

autossh -M 0 -N \
  -o "ServerAliveInterval=10" \
  -o "ServerAliveCountMax=2" \
  -o "TCPKeepAlive=yes" \
  -o "ExitOnForwardFailure=yes" \
  -R 127.0.0.1:19826:127.0.0.1:19826 \
  -R ${GATEWAY}:19826:127.0.0.1:19826 \
  ${SERVER}
```

- 第一条 `-R` 绑到服务器 lo，**给宿主机本机 curl 测试用**
- 第二条 `-R` 绑到服务器 docker bridge IP，**给容器用**

`ExitOnForwardFailure=yes` 很关键：任何一条 `-R` 绑不上 sshd（比如 3.2 还没改）autossh 整个会退出，便于第一时间发现问题，而不是"绑了 lo 但没绑 docker bridge"这种半残状态。

### 3.5 服务器：验证 sshd 真的 listen 了两个地址

```bash
sudo ss -tnlp | grep 19826
```

应看到两行：

```text
LISTEN 0  128  127.0.0.1:19826     0.0.0.0:*  sshd
LISTEN 0  128  172.19.0.1:19826    0.0.0.0:*  sshd
```

如果只看到 `127.0.0.1`，回到 3.2 确认 GatewayPorts；回到 3.4 确认本机 autossh 还活着（`ps aux | grep autossh`）。

### 3.6 服务器宿主机：直测两个地址都通

```bash
curl -sS --max-time 3 http://127.0.0.1:19826/health
curl -sS --max-time 3 http://${GATEWAY}:19826/health
```

两条都应该秒回：

```json
{"ok":true,"hostname":"your-mac.local","activeJobs":0,"capacity":2,"maxConcurrent":2}
```

- `127.0.0.1` 通、`${GATEWAY}` 不通 → 回到 3.3 确认 iptables ACCEPT 规则在第一条
- 两个都不通 → 隧道断了，看 3.4 autossh 是否还活着

### 3.7 容器：跨 bridge 测连通性

```bash
sudo docker compose --env-file .env.prod -f docker-compose.prod.yml exec queue \
  curl -sS --max-time 3 http://${GATEWAY}:19826/health
```

应返回与 3.6 相同的 JSON。

> ⚠️ 不要用 `host.docker.internal` —— Docker `host-gateway` 关键字解析的是 docker0 的 IP (`172.17.0.1`)，**和你 compose 自定义网络（如 172.19.0.0/16）不在同一个 bridge**，会被 docker 的 `DOCKER-ISOLATION` 规则跨 bridge 隔离挡掉。直接用 `${GATEWAY}` IP 是最稳的。

### 3.8 GEOFlow admin：配置 bridge endpoint

GEOFlow 后台 → 网站设置 → 外部浏览器抓取，填：

| 字段 | 值 |
|---|---|
| 启用外部浏览器抓取 | 勾上 |
| Bridge 端点 | `http://172.19.0.1:19826`（用 3.1 的实际 IP） |
| Bearer Token | 和本机 `external-fetch-bridge/.env` 的 `BRIDGE_TOKEN` 完全一致 |
| 单次抓取超时 | 默认 60，可保持 |
| 域名白名单 | 默认即可，按需追加 |
| 失败回退状态码 | 默认 `403,429` 即可 |

保存后 `SiteSettingsBag::forget()` 会自动让队列 worker 下一次 resolve 时读到新配置。

### 3.9 端到端验证

GEOFlow 后台 → URL 智能采集，提交一条命中域名白名单的 URL（如 `https://zhuanlan.zhihu.com/p/665715823`）。

观察 `url_import_jobs` 表：

- `fetch_source` 列应该是 `external_primary`（域名命中）或 `external_fallback`（直连先失败回退）
- `fetched_markdown` 列应有抓取回的 Markdown

或在队列日志里看到类似：

```text
开始抓取：https://zhuanlan.zhihu.com/p/665715823
使用外部浏览器抓取页面（external_primary）
页面抓取完成，HTML 长度：26421 字节
```

到这里端到端 OK。

### 3.10 持久化（重启服务器后仍然生效）

iptables 规则在重启或 ufw / 宝塔 reload 时会失效。三选一：

#### A. 用宝塔面板（最简单）

宝塔 → 安全 → 防火墙 → 添加规则：

- 端口：`19826`
- 协议：`TCP`
- 来源 IP：`172.19.0.0/16`（用你 3.1 的网段）
- 策略：放行
- 备注：`external-fetch-bridge (docker)`

#### B. 用 ufw

```bash
sudo ufw allow from 172.19.0.0/16 to any port 19826 proto tcp
sudo ufw status numbered
```

#### C. systemd oneshot（不依赖任何面板）

```bash
sudo tee /etc/systemd/system/geoflow-bridge-allow.service > /dev/null <<'EOF'
[Unit]
Description=Allow GEOFlow external-fetch bridge inbound from docker bridge
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/sbin/iptables -C INPUT -s 172.19.0.0/16 -p tcp --dport 19826 -j ACCEPT \
  || /sbin/iptables -I INPUT 1 -s 172.19.0.0/16 -p tcp --dport 19826 -j ACCEPT
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now geoflow-bridge-allow.service
```

> 三选一即可，重复加无意义。

**autossh 也要在本机持久化**——见 [`../../external-fetch-bridge/README.md`](../../external-fetch-bridge/README.md) 的 §SSH 反向隧道 → "4. 开机自启 (macOS launchd)"。注意把 plist 里的 `-R` IP 同步成 3.1 拿到的值。

---

## 4. 三层防火墙速查表

下次遇到"容器内 curl host.docker.internal:19826 超时"，按这张表顺序排查：

| # | 表象 | 怎么确认 | 修复 |
|---|---|---|---|
| 隧道层 | 服务器 `sudo ss -tnlp \| grep 19826` 只有 1 行（127.0.0.1） | 服务器 `sudo sshd -T \| grep -i gatewayports` 输出 `gatewayports no` | 回 3.2：改 `clientspecified` + `systemctl reload ssh` + 本机重启 autossh |
| 宿主机 INPUT | 宿主机本机 `curl 127.0.0.1:19826` 通，`curl ${GATEWAY}:19826` 超时 | `sudo iptables -L INPUT -v -n \| head -5` 第一行是 `policy DROP` | 回 3.3：`iptables -I INPUT 1 -s <网段> -p tcp --dport 19826 -j ACCEPT` |
| 容器跨 bridge | 容器内 `curl host.docker.internal:19826` 超时但宿主机能通 | 容器 `getent hosts host.docker.internal` 解析到 `172.17.0.1`，与 compose 网络 `172.19.0.0/16` 不同 | 回 3.7/3.8：endpoint 改成 `http://${GATEWAY}:19826`，**不要**用 `host.docker.internal` |

---

## 5. 设计决策：为什么不在 compose 里写死网段

早期版本曾尝试给 compose 加一个 `host_access` 自定义 bridge 并固定 `subnet: 172.30.0.0/24 gateway: 172.30.0.1`，让 `autossh -R 172.30.0.1:...` 与 `extra_hosts: host.docker.internal:172.30.0.1` 全套硬编码。但这有几个问题：

1. **不通用**：开源项目不能假设别人服务器上 `172.30.0.0/24` 一定空闲；很多 IT 环境保留段、宝塔已分配的 baota_net、k8s overlay 都可能冲突
2. **多机部署**：不同机器 docker 自动分配的网段不同，硬编码反而让多机部署需要逐台改
3. **可移植性**：endpoint 字段本来就支持 admin UI 配置，让部署方填一次自己环境的实际 IP，是最自然的扩展点

所以**仓库 compose 不在 networks 里固定 IP**——`docker-compose.prod.yml` 里 `app` / `queue` 只保留 `extra_hosts: host.docker.internal:host-gateway`（macOS Docker Desktop 友好），Linux 生产部署走"admin endpoint 填实际 gateway IP"路径。

---

## 6. 安全说明

- **autossh `-R` 不要绑 `0.0.0.0` 或 `*`**：会让服务器公网 19826 端口直接暴露 Bridge 接口，外部任何人只要拿到 token 即可远程触发本机 opencli。**始终用具体 IP**（`127.0.0.1` 或 docker bridge gateway IP）
- **iptables 加白要带源 IP 限定**：`-s 172.19.0.0/16` 只放行 docker 内部网段；不要写 `-s 0.0.0.0/0`
- **`BRIDGE_TOKEN`** 用 `openssl rand -hex 32` 生成，本机 `.env` 和 admin UI 各存一份，禁止入 Git（仓库 `.gitignore` 已覆盖 `.env`）
- **服务器 sshd `GatewayPorts clientspecified`** 比 `yes` 更安全，已在 3.2 强调
- **本机 SSH key**：推荐为 autossh 单独生成一把 `ed25519` key，在服务器 `~/.ssh/authorized_keys` 该 key 行前加：

  ```text
  command="echo only-tunnel",no-pty,no-agent-forwarding,no-X11-forwarding,permitlisten="172.19.0.1:19826",permitlisten="127.0.0.1:19826" ssh-ed25519 AAA... bridge-tunnel
  ```

  这把 key 即使泄露，也只能打开这两个端口的反向隧道，登不了 shell。

---

## 7. 卸载 / 关闭

临时关：admin UI → 外部浏览器抓取 → 取消勾选"启用"。所有 URL 都会退回直连抓取，与开此功能前行为完全一致。

完全卸载：

```bash
# 服务器端
sudo systemctl disable --now geoflow-bridge-allow.service 2>/dev/null
sudo rm -f /etc/systemd/system/geoflow-bridge-allow.service
sudo iptables -D INPUT -s 172.19.0.0/16 -p tcp --dport 19826 -j ACCEPT 2>/dev/null

# 本机
launchctl unload ~/Library/LaunchAgents/com.geoflow.external-fetch-bridge.tunnel.plist 2>/dev/null
rm -f ~/Library/LaunchAgents/com.geoflow.external-fetch-bridge.tunnel.plist
# 关掉 npm start 的 external-fetch-bridge 进程

# 可选：撤回 sshd 改动
sudo sed -i 's/^GatewayPorts clientspecified/#GatewayPorts no/' /etc/ssh/sshd_config
sudo systemctl reload ssh
```

---

## 8. 修订记录

| 日期 | 变更 |
|---|---|
| 2026-05-12 | 首次记录：基于 ECS 阿里云 Ubuntu + 宝塔 + Docker Compose 实际部署过程整理 |
