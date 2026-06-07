# GEO 监测双运行环境

GEO 监测 sidecar 支持两套运行环境，通过 `GEOFLOW_GEO_MONITOR_RUNTIME` 切换。Laravel 队列探测在两种模式下均为 **无头**；差异在于 **profile 维护方式**。

## 模式对照

| | `headless_linux` | `headed_desktop` |
|---|------------------|------------------|
| **适用** | 生产 Linux 服务器 | macOS / Windows / 有 DISPLAY 的 Linux |
| **批量探测** | 无头 `--production` | 无头 `--production`（sidecar 可在本机） |
| **验证码/登录** | noVNC + SSH 隧道 | 本机弹出可见 Chromium |
| **维护脚本** | `scripts/novnc/maintain-profile.sh` | `scripts/headed/maintain-profile.sh` |
| **维护审计** | `maintenance_via=novnc` | `maintenance_via=headed_local` |

## 配置示例

### 生产服务器（无头 Linux）

```env
GEOFLOW_GEO_MONITOR_RUNTIME=headless_linux
GEOFLOW_GEO_MONITOR_ENABLED=true
GEOFLOW_GEO_MONITOR_SIDECAR_URL=http://127.0.0.1:8765
GEOFLOW_GEO_MONITOR_POC_ROOT=/opt/geoflow/tools/geo-monitor-poc
GEOFLOW_GEO_MONITOR_SSH_HOST=deploy@your-server
GEOFLOW_GEO_MONITOR_NOVNC_BIND=127.0.0.1
```

运维流程见 [geo-monitoring-novnc.md](./geo-monitoring-novnc.md)。

### 本地开发（有头桌面）

```env
GEOFLOW_GEO_MONITOR_RUNTIME=headed_desktop
GEOFLOW_GEO_MONITOR_ENABLED=true
GEOFLOW_GEO_MONITOR_SIDECAR_URL=http://127.0.0.1:8765
GEOFLOW_GEO_MONITOR_POC_ROOT=tools/geo-monitor-poc
```

维护命令（本机终端）：

```bash
cd tools/geo-monitor-poc
# 后台先点「同步 sidecar 账号」
./scripts/headed/maintain-profile.sh --platform doubao --account-id doubao_guest
./scripts/headed/health-check.sh --platform doubao --account-id doubao_guest
```

## profile 如何在两套环境间迁移

1. **有头本地 → 无头生产**（常见 POC 路径）
   - 本地 `headed_desktop` 完成 login/captcha
   - 打包 `profiles/<account_id>/` 上传到服务器同路径
   - 服务器 `headless_linux` 执行 health-check
   - 或服务器 noVNC 在同一 profile 上复验

2. **生产 noVNC 维护**（推荐长期运行）
   - 不迁移 profile，直接在服务器维护同一目录

## 共同要求

- 后台 **同步 sidecar 账号** → `accounts.json` 含 profile 路径与代理
- 一账号一 profile，维护期间账号不参与调度
- 完成维护前自动 Sidecar 登录态健康检查

## 脚本索引

| 脚本 | 模式 |
|------|------|
| `scripts/novnc/start-novnc.sh` | headless_linux |
| `scripts/novnc/maintain-profile.sh` | headless_linux |
| `scripts/novnc/health-check.sh` | headless_linux |
| `scripts/headed/maintain-profile.sh` | headed_desktop |
| `scripts/headed/health-check.sh` | headed_desktop |
