# GEO 监测 Docker 部署

GEO 监测 sidecar（Chromium + noVNC + HTTP API）已并入主 Compose 栈，通过 **`geo-monitor` profile** 按需启用。未带该 profile 时，不会构建或启动 sidecar 容器。

## 架构

```mermaid
flowchart TB
  subgraph compose["docker compose（--profile geo-monitor）"]
    web[web / app]
    queue[queue worker]
    sidecar[geo-monitor-sidecar]
    pg[(postgres)]
    redis[(redis)]
  end

  web --> sidecar
  queue --> sidecar
  web --> pg
  queue --> redis
  sidecar --> poc["./tools/geo-monitor-poc\naccounts.json / profiles / evidence"]
  web --> poc
  queue --> poc
```

Laravel 与 sidecar 通过 Compose 内网 DNS 通信：`http://geo-monitor-sidecar:8765`。

## 首次准备

```bash
# 若尚无 accounts.json，从样例复制（Laravel 导出也会覆盖写入）
cp tools/geo-monitor-poc/accounts.sample.json tools/geo-monitor-poc/accounts.json

mkdir -p tools/geo-monitor-poc/profiles tools/geo-monitor-poc/evidence
```

## 开发环境

`.env` 中启用 GEO 监测并指向 Compose 服务名：

```env
GEOFLOW_GEO_MONITOR_ENABLED=true
GEOFLOW_GEO_MONITOR_RUNTIME=headless_linux
GEOFLOW_GEO_MONITOR_SIDECAR_URL=http://geo-monitor-sidecar:8765
GEOFLOW_GEO_MONITOR_SIDECAR_TOKEN=your-random-token
GEOFLOW_GEO_MONITOR_POC_ROOT=tools/geo-monitor-poc
```

启动（带 `geo-monitor` profile）：

```bash
docker compose --profile geo-monitor up -d --build
```

仅重启 sidecar：

```bash
docker compose --profile geo-monitor up -d geo-monitor-sidecar
```

不带 profile 时 sidecar **不会**出现在 `docker compose ps` 中，其余服务照常运行。

## 生产环境

`.env.prod` 示例：

```env
GEOFLOW_GEO_MONITOR_ENABLED=true
GEOFLOW_GEO_MONITOR_RUNTIME=headless_linux
GEOFLOW_GEO_MONITOR_SIDECAR_URL=http://geo-monitor-sidecar:8765
GEOFLOW_GEO_MONITOR_SIDECAR_TOKEN=your-random-token
GEOFLOW_GEO_MONITOR_POC_ROOT=tools/geo-monitor-poc
```

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build
docker compose --env-file .env.prod -f docker-compose.prod.yml --profile geo-monitor up -d geo-monitor-sidecar
```

或一条命令启动全部（含 sidecar）：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml --profile geo-monitor up -d --build
```

生产 `app` / `queue` 已挂载 `./tools/geo-monitor-poc`，与 sidecar 共享 `accounts.json`、`profiles/`、`evidence/`。

## noVNC 远程维护

sidecar 容器内已启动 Xvfb + noVNC，端口仅绑定 **127.0.0.1**：

| 端口 | 用途 |
|------|------|
| 6080 | noVNC 网页 |
| 8765 | sidecar HTTP API（调试时可本地转发） |

SSH 隧道：

```bash
ssh -L 6080:127.0.0.1:6080 -L 8765:127.0.0.1:8765 deploy@your-server
```

浏览器打开 `http://127.0.0.1:6080/vnc.html`。详细运维流程见 [geo-monitoring-novnc.md](./geo-monitoring-novnc.md)。

## 环境变量（Compose 层）

| 变量 | 默认 | 说明 |
|------|------|------|
| `GEOFLOW_GEO_MONITOR_NOVNC_PORT` | `6080` | 宿主机 noVNC 映射端口 |
| `GEOFLOW_GEO_MONITOR_SIDECAR_PORT` | `8765` | 宿主机 sidecar API 映射端口（可选，便于本机调试） |
| `GEOFLOW_GEO_MONITOR_SIDECAR_TOKEN` | 空 | sidecar Bearer Token，生产务必设置 |

## 不使用 Docker sidecar 时

若 sidecar 跑在宿主机或其它机器，**不要**加 `--profile geo-monitor`，并在 `.env` / `.env.prod` 中自行设置 `GEOFLOW_GEO_MONITOR_SIDECAR_URL`（例如 `http://host.docker.internal:8765` 或实际 IP）。

## 资源建议

sidecar 含 Chromium，建议：

- `shm_size: 1gb`（已在 compose 中配置）
- 单机 4 GB+ 内存，维护时预留可见浏览器资源
- 6080 / 8765 **勿暴露公网**

## 相关文档

- [geo-monitoring-novnc.md](./geo-monitoring-novnc.md) — noVNC 维护流程
- [geo-monitoring-dual-runtime.md](./geo-monitoring-dual-runtime.md) — headed_desktop vs headless_linux
- [deployment/DEPLOYMENT.md](./deployment/DEPLOYMENT.md) — 生产 Docker 总览
