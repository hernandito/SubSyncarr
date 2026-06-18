FROM debian:bookworm-slim

LABEL maintainer="SubSync" \
      description="Subtitle sync tool for Kodi/Plex media libraries"

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=America/New_York \
    PUID=99 \
    PGID=100

# ── Install all runtime dependencies in one layer ───────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    php-fpm php-sqlite3 php-curl php-mbstring php-xml \
    python3 python3-pip python3-venv \
    ffmpeg \
    supervisor \
    cron \
    curl \
    ca-certificates \
    gosu \
    && rm -rf /var/lib/apt/lists/*

# ── Install ffsubsync into a venv (keeps system clean) ──────────────────
RUN python3 -m venv /opt/ffsubsync && \
    /opt/ffsubsync/bin/pip install --no-cache-dir ffsubsync && \
    ln -s /opt/ffsubsync/bin/ffsubsync /usr/local/bin/ffsubsync && \
    ln -s /opt/ffsubsync/bin/ffs /usr/local/bin/ffs

# ── Configure PHP-FPM ───────────────────────────────────────────────────
RUN sed -i 's|listen = .*|listen = /run/php/php-fpm.sock|' /etc/php/*/fpm/pool.d/www.conf && \
    sed -i 's|;listen.mode = .*|listen.mode = 0660|' /etc/php/*/fpm/pool.d/www.conf && \
    sed -i 's|user = www-data|user = www-data|' /etc/php/*/fpm/pool.d/www.conf && \
    sed -i 's|group = www-data|group = www-data|' /etc/php/*/fpm/pool.d/www.conf && \
    sed -i 's|max_execution_time = 30|max_execution_time = 900|' /etc/php/*/fpm/php.ini && \
    sed -i 's|memory_limit = 128M|memory_limit = 256M|' /etc/php/*/fpm/php.ini && \
    mkdir -p /run/php

# ── Copy application files ──────────────────────────────────────────────
COPY root/ /
COPY app/www/ /var/www/html/

# ── Set permissions ─────────────────────────────────────────────────────
RUN rm -f /etc/nginx/sites-enabled/default && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod +x /entrypoint.sh 2>/dev/null || true && \
    mkdir -p /config && chown www-data:www-data /config && \
    mkdir -p /var/log/supervisor

# ── Cron for scheduled scraping ─────────────────────────────────────────
COPY root/etc/cron.d/subsync-cron /etc/cron.d/subsync-cron
RUN chmod 0644 /etc/cron.d/subsync-cron && crontab /etc/cron.d/subsync-cron

EXPOSE 5889

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -f http://localhost:5889/api.php?action=health || exit 1

ENTRYPOINT ["/entrypoint.sh"]
