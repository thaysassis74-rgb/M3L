#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /var/www/m3l
exec 9>/var/lock/m3l-backup.lock
flock -n 9 || exit 0
backup_db=$(sudo -u www-data php -r 'require "private/storage.php"; echo database()->query("SELECT DATABASE()")->fetchColumn();')
[[ "$backup_db" =~ ^[a-zA-Z0-9_]+$ ]] || { echo 'Nome de banco inválido.' >&2; exit 1; }
install -d -m 700 /var/backups/m3l
backup_file="/var/backups/m3l/${backup_db}-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
backup_tmp=$(mktemp /var/backups/m3l/.backup-XXXXXX)
trap 'rm -f "$backup_tmp"' EXIT
mysqldump --user=root --single-transaction --quick --no-tablespaces --skip-lock-tables --databases "$backup_db" | gzip > "$backup_tmp"
gzip -t "$backup_tmp"
[[ -s "$backup_tmp" ]] || exit 1
mv "$backup_tmp" "$backup_file"
echo "Backup concluído: $backup_file"
