FROM php:8.6.0-cli

# ── System dependencies ────────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        git \
        curl \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ─────────────────────────────────────────────────────────────
# sockets  – required by Ratchet / ReactPHP
# pcntl    – graceful signal handling
# pdo_mysql – MariaDB storage backend
RUN docker-php-ext-install sockets pcntl pdo_mysql

# ── Composer ───────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Application ────────────────────────────────────────────────────────────────
WORKDIR /app
COPY . .

RUN composer install \
        --no-dev \
        --no-interaction \
        --optimize-autoloader \
        --no-progress

# ── Runtime ────────────────────────────────────────────────────────────────────
# HTTP API  → 8081/tcp
# WebSocket → 8080/tcp
# UDP ingest → 8082/udp (UDP_ENABLED, UDP_PORT)
EXPOSE 8081 8080 8082/udp

HEALTHCHECK --interval=5s --timeout=3s --start-period=10s --retries=5 \
    CMD curl -fsS http://localhost:8081/api/health || exit 1

CMD ["php", "bin/server.php"]
