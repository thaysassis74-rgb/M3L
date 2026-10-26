#!/usr/bin/env bash
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo 'Execute como root na VPS.' >&2; exit 1; }
cd /var/www/m3l
bash bin/backup-mysql.sh
sudo -u www-data php tests/integrations.php
sudo -u www-data php bin/reconcile.php
systemctl restart m3l-worker@activecampaign m3l-worker@sheets
install -m 644 deploy/m3l-backup.service deploy/m3l-backup.timer deploy/m3l-health.service deploy/m3l-health.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now m3l-backup.timer m3l-health.timer
systemctl is-active m3l-worker@activecampaign m3l-worker@sheets
php bin/health.php
