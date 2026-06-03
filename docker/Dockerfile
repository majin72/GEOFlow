# --- 前端资产构建阶段：编译 Vite 产物到 public/build，供运行时镜像复制 ---
FROM node:22-bookworm-slim AS assets

WORKDIR /app

# Vite 在打包时把 VITE_* 直接内联进 JS 产物；Docker 构建需显式传入 Reverb 公开配置。
ARG VITE_REVERB_APP_KEY=""
ARG VITE_REVERB_HOST=""
ARG VITE_REVERB_PORT=""
ARG VITE_REVERB_SCHEME=""
ARG VITE_REVERB_PATH=""
ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME \
    VITE_REVERB_PATH=$VITE_REVERB_PATH

# npmmirror 中国镜像加速 npm install（保持与 composer 镜像策略一致）
RUN npm config set registry https://registry.npmmirror.com/

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# 仅拷贝 Vite 构建所需的源文件，减少缓存失效面
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

RUN npm run build

ARG PHP_CLI_IMAGE=php:8.4-cli-bookworm

FROM ${PHP_CLI_IMAGE}

WORKDIR /var/www/html

# 使用国内 Debian 源，加速 apt 安装（Bookworm：deb822 或经典 sources.list）
RUN set -eux; \
    if [ -f /etc/apt/sources.list.d/debian.sources ]; then \
        sed -i 's|deb.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources; \
        sed -i 's|security.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources; \
    elif [ -f /etc/apt/sources.list ]; then \
        sed -i 's|deb.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list; \
        sed -i 's|security.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list; \
    fi

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev \
        postgresql-client \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        gd \
        mbstring \
        pdo_pgsql \
        pdo_sqlite \
        pcntl \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_PROCESS_TIMEOUT=2000

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-dev --no-scripts

COPY . .

# 将前端构建产物注入到镜像 public/build，供 Blade @vite 在容器内独立运行时使用
COPY --from=assets /app/public/build ./public/build

# 仅刷新 autoload；不调 artisan / composer 脚本（镜像为 --no-dev，构建期无 Boost 等 dev 包）
RUN composer dump-autoload --optimize --no-interaction --no-scripts

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/opcache-dev.ini /usr/local/etc/php/conf.d/zz-opcache-dev.ini
COPY docker/php/php-docker-overrides.ini /usr/local/etc/php/conf.d/99-overrides.ini

RUN cp docker/entrypoint.sh /usr/local/bin/geoflow-entrypoint \
    && chmod +x /usr/local/bin/geoflow-entrypoint

ENTRYPOINT ["/usr/local/bin/geoflow-entrypoint"]
CMD ["php", "artisan", "about"]
