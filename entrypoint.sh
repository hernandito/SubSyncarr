#!/bin/bash
set -e

if [ ! -f /config/subsync.db ]; then
    echo "[subsyncarr] Initializing database..."
    php -r "
        \$db = new PDO('sqlite:/config/subsync.db');
        \$db->exec(file_get_contents('/var/www/html/includes/schema.sql'));
        echo \"[subsyncarr] Database created.\n\";
    "
    chown www-data:www-data /config/subsync.db
fi

mkdir -p /run/php /var/log/nginx /var/log/supervisor /config
chown www-data:www-data /config

SCRAPE_INTERVAL=${SCRAPE_INTERVAL:-12}
case $SCRAPE_INTERVAL in
    6)  CRON_EXPR="0 */6 * * *" ;;
    12) CRON_EXPR="0 */12 * * *" ;;
    24) CRON_EXPR="0 3 * * *" ;;
    *)  CRON_EXPR="0 */12 * * *" ;;
esac
echo "$CRON_EXPR www-data curl -s http://localhost:5889/api.php?action=scrape > /dev/null 2>&1" > /etc/cron.d/subsync-cron
echo "" >> /etc/cron.d/subsync-cron
chmod 0644 /etc/cron.d/subsync-cron
crontab /etc/cron.d/subsync-cron

echo "[subsyncarr] Starting SubSync on port 5889..."
echo "[subsyncarr] Scrape interval: every ${SCRAPE_INTERVAL}h"

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/subsync.conf
