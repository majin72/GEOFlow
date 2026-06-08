#!/bin/sh
# 按环境变量生成 GEO 监测 noVNC 公网反代 location 片段（须被 default.conf server 块 include）。
set -eu

SNIPPET="/etc/nginx/snippets/geo-monitor-novnc-locations.conf"
LEGACY_CONF="/etc/nginx/conf.d/geo-monitor-novnc.conf"

PUBLIC_PATH="${GEOFLOW_GEO_MONITOR_NOVNC_PUBLIC_PATH:-/geo-monitor/novnc}"
PUBLIC_PATH="/${PUBLIC_PATH#/}"
PUBLIC_PATH="${PUBLIC_PATH%/}"
AUTH_LOCATION="$(dirname "$PUBLIC_PATH")/novnc-auth"
WS_PATH="${PUBLIC_PATH#/}/websockify"
WS_PATH_ENCODED=$(printf '%s' "$WS_PATH" | sed 's|/|%2F|g')
VNC_QUERY="path=${WS_PATH_ENCODED}&autoconnect=true&resize=scale"

mkdir -p /etc/nginx/snippets
rm -f "$LEGACY_CONF"

if [ "${GEOFLOW_GEO_MONITOR_NOVNC_PUBLIC_ENABLED:-false}" != "true" ]; then
  echo "# GEO monitor noVNC public access disabled" >"$SNIPPET"
  exit 0
fi

AUTH_MODE="${GEOFLOW_GEO_MONITOR_NOVNC_AUTH_MODE:-admin_session}"
ADMIN_PREFIX="${GEOFLOW_ADMIN_BASE_PATH:-geo_admin}"
UPSTREAM="${GEOFLOW_GEO_MONITOR_NOVNC_UPSTREAM:-geo-monitor-sidecar:6080}"
PHP_FPM="${GEO_MONITOR_PHP_FPM_UPSTREAM:-app:9000}"
HTPASSWD="/etc/nginx/geo-monitor-novnc.htpasswd"

append_auth_directives() {
  case "$AUTH_MODE" in
    both)
      echo "    satisfy any;"
      echo "    auth_request ${AUTH_LOCATION};"
      ;;
    admin_session)
      echo "    auth_request ${AUTH_LOCATION};"
      ;;
    basic)
      ;;
    *)
      echo "    auth_request ${AUTH_LOCATION};"
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
      echo "    auth_basic \"GEO Monitor Remote Desktop\";"
      echo "    auth_basic_user_file ${HTPASSWD};"
    fi
  fi
}

append_proxy_directives() {
  echo "    proxy_pass http://${UPSTREAM}/;"
  echo "    proxy_http_version 1.1;"
  echo "    proxy_set_header Upgrade \$http_upgrade;"
  echo "    proxy_set_header Connection \$connection_upgrade;"
  echo "    proxy_set_header Host \$host;"
  echo "    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;"
  echo "    proxy_set_header X-Forwarded-Proto \$scheme;"
  echo "    proxy_read_timeout 86400s;"
  echo "    proxy_send_timeout 86400s;"
  echo "    proxy_buffering off;"
}

append_vnc_html_proxy_directives() {
  # 精确匹配 location 不能用 proxy_pass http://upstream/; 否则 upstream 收到 GET / 导致目录列表
  echo "    proxy_pass http://${UPSTREAM}/vnc.html\$is_args\$args;"
  echo "    proxy_http_version 1.1;"
  echo "    proxy_set_header Upgrade \$http_upgrade;"
  echo "    proxy_set_header Connection \$connection_upgrade;"
  echo "    proxy_set_header Host \$host;"
  echo "    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;"
  echo "    proxy_set_header X-Forwarded-Proto \$scheme;"
  echo "    proxy_read_timeout 86400s;"
  echo "    proxy_send_timeout 86400s;"
  echo "    proxy_buffering off;"
}

{
  echo "location = ${AUTH_LOCATION} {"
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
  echo "location = ${PUBLIC_PATH} {"
  echo "    return 302 ${PUBLIC_PATH}/vnc.html?${VNC_QUERY};"
  echo "}"
  echo ""
  echo "location = ${PUBLIC_PATH}/ {"
  echo "    return 302 ${PUBLIC_PATH}/vnc.html?${VNC_QUERY};"
  echo "}"
  echo ""
  echo "location = ${PUBLIC_PATH}/vnc.html {"
  echo "    if (\$arg_path = \"\") {"
  echo "        return 302 \$scheme://\$host${PUBLIC_PATH}/vnc.html?${VNC_QUERY};"
  echo "    }"
  append_auth_directives
  append_vnc_html_proxy_directives
  echo "}"
  echo ""
  echo "location ^~ ${PUBLIC_PATH}/ {"
  append_auth_directives
  append_proxy_directives
  echo "}"
} >"$SNIPPET"
