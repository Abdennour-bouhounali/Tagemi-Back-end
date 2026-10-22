#!/usr/bin/env bash
# Daily encrypted backup of the production database.
#
# Runs as root from cron: 30 2 * * * /var/www/Tagemi-Back-end/deploy/backup-db.sh
#   1. dumps the database with mariadb-dump (consistent, no table locks)
#   2. encrypts it with the passphrase in /root/.backup-passphrase
#   3. keeps 14 days on the server and 30 days on Google Drive (rclone remote "gdrive")
#
# Restore: gpg -d FILE.sql.gz.gpg | gunzip | mariadb DBNAME
set -euo pipefail

DB_NAME="${DB_NAME:-u412486568_tagemi}"
LOCAL_DIR="${LOCAL_DIR:-/root/backups/daily}"
REMOTE="${REMOTE:-gdrive:tagemi-backups}"
PASSPHRASE_FILE="${PASSPHRASE_FILE:-/root/.backup-passphrase}"

mkdir -p "$LOCAL_DIR"
chmod 700 "$LOCAL_DIR"
FILE="$LOCAL_DIR/${DB_NAME}-$(date +%F-%H%M).sql.gz.gpg"

mariadb-dump --single-transaction --quick --routines --triggers "$DB_NAME" \
  | gzip -9 \
  | gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase-file "$PASSPHRASE_FILE" -o "$FILE"

# A dump of a few KB means something went wrong (empty DB, auth error...)
if [ "$(stat -c %s "$FILE")" -lt 10240 ]; then
  echo "Backup suspiciously small: $FILE" >&2
  exit 1
fi

find "$LOCAL_DIR" -name '*.sql.gz.gpg' -mtime +14 -delete

rclone copy "$FILE" "$REMOTE"
rclone delete "$REMOTE" --min-age 30d

echo "Backup OK: $FILE ($(du -h "$FILE" | cut -f1))"
