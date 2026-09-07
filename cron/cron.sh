#!/bin/sh
# ═════════════════════════════════════════════════════════════════════
#  MirzaBot — Railway CRON-only container entrypoint
#
#  This container does NOT run the web server. It only runs the
#  cronbot/*.php jobs (same cadence as install.sh crontab) plus the
#  SOVRA maintenance scripts (cleanup-invoices, db-backup).
#  Railway has no crontab, so we run the PHP scripts in background loops.
# ═════════════════════════════════════════════════════════════════════

cd /var/www/html || exit 1

# run_loop <interval_minutes> <full_php_path> [<full_php_path> ...]
# Each job gets its own background loop: run once, then wait <interval>.
run_loop() {
    interval=$1
    shift
    for job in "$@"; do
        (
            while :; do
                php "$job" >/dev/null 2>&1
                sleep $((interval * 60))
            done
        ) &
    done
}

echo "[cron.sh] $(date -Is) starting cron loops ..."

# ── cronbot jobs (canonical cadence from install.sh / start.sh) ─────
run_loop 1   /var/www/html/cronbot/croncard.php \
             /var/www/html/cronbot/NoticationsService.php \
             /var/www/html/cronbot/sendmessage.php \
             /var/www/html/cronbot/activeconfig.php \
             /var/www/html/cronbot/disableconfig.php \
             /var/www/html/cronbot/iranpay1.php

run_loop 2   /var/www/html/cronbot/gift.php \
             /var/www/html/cronbot/configtest.php

run_loop 3   /var/www/html/cronbot/plisio.php

run_loop 5   /var/www/html/cronbot/payment_expire.php

run_loop 15  /var/www/html/cronbot/statusday.php \
             /var/www/html/cronbot/on_hold.php \
             /var/www/html/cronbot/uptime_node.php \
             /var/www/html/cronbot/uptime_panel.php

run_loop 30  /var/www/html/cronbot/expireagent.php

run_loop 300 /var/www/html/cronbot/backupbot.php

run_loop 60  /var/www/html/usage-snapshot.php

# ── SOVRA maintenance scripts (formerly separate Railway services) ──
run_loop 30  /var/www/html/cleanup-invoices.php
run_loop 300 /var/www/html/db-backup.php

echo "[cron.sh] $(date -Is) all cron loops started"

# Keep the container alive; each child loop dies/restarts on its own.
while :; do
    sleep 3600
done
