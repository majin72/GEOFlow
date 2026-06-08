#!/bin/sh
# 按环境变量生成 GEO 监测 noVNC 公网反代配置（可选）。
set -eu

if [ "${GEOFLOW_GEO_MONITOR_NOVNC_PUBLIC_ENABLED:-false}" != "true" ]; then
  exit 0
fi

AUTH_MODE="${GEOFLOW_GEO_MONITOR_NOVNC_AUTH_MODE:-admin_session}"
ADMIN_PREFIX="${GEOFLOW_ADMIN_BASE_PATH:-geo_admin}"
UPSTREAM="${GEOFLOW_GEO_MONITOR_NOVNC_UPSTREAM:-geo-monitor-sidecar:6080}"
PHP_FPM="${GEO_MONITOR_PHP_FPM_UPSTREAM:-app:9000}"
CONF="/etc/nginx/conf.d/geo-monitor-novnc.conf"
HTPASSWD="/etc/nginx/geo-monitor-novnc.htpasswd"

{
  echo "location = /geo-monitor/novnc-auth {"
  echo "    internal;"
  echo "    include fastcgi_params;"
  echo "    fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;"
  echo "    fastcgi_param DOCUMENT_ROOT /var/www/html/public;"
  echo "    fastcgi_param REQUEST_URI /${ADMIN_PREFIX}/internal/geo-monitor/novnc-auth;"
  echo "    fastcgi_param QUERY_STRING \"\";"
  echo "    fastcgi_pass ${PHP_FPM};"
  echo "    fastcgi_pass_request_body off;"
  echo "    fastcgi_param CONTENT_LENGTH \"\";"
  echo "}"
  echo ""
  echo "location /geo-monitor/novnc/ {"
} >"$CONF"

case "$AUTH_MODE" in
  both)
    echo "    satisfy any;" >>"$CONF"
    echo "    auth_request /geo-monitor/novnc-auth;" >>"$CONF"
    ;;
  admin_session)
    echo "    auth_request /geo-monitor/novnc-auth;" >>"$CONF"
    ;;
  basic)
    ;;
  *)
    echo "    auth_request /geo-monitor/novnc-auth;" >>"$CONF"
    ;;
esac

if [ "$AUTH_MODE" = "basic" ] || [ "$AUTH_MODE" = "both" ]; then
  BASIC_USER="${GEOFLOW_GEO_MONITOR_NOVNC_BASIC_USER:-}"
  BASIC_PASS="${GEOFLOW_GEO_MONITOR_NOVNC_BASIC_PASSWORD:-}"
  if [ -n "$BASIC_USER" ] && [ -n "$BASIC_PASS" ]; then
    if ! command -v htpasswd >/dev/null 2>&1; then
      apk add --no-cache apache2-utils >/dev/null
    fi
    htpasswd -cb "$HTPASSWD" "$BASIC_USER" "$BASIC_PASS"
    echo "    auth_basic \"GEO Monitor Remote Desktop\";" >>"$CONF"
    echo "    auth_basic_user_file ${HTPASSWD};" >>"$CONF"
  fi
fi

{
  echo "    proxy_pass http://${UPSTREAM}/;"
  echo "    proxy_http_version 1.1;"
  echo "    proxy_set_header Upgrade \$http_upgrade;"
  echo "    proxy_set_header Connection \"upgrade\";"
  echo "    proxy_set_header Host \$host;"
  echo "    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;"
  echo "    proxy_set_header X-Forwarded-Proto \$scheme;"
  echo "    proxy_read_timeout 86400s;"
  echo "    proxy_send_timeout 86400s;"
  echo "}"
} >>"$CONF"
