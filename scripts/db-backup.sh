#!/usr/bin/env bash
# Runs inside the db-backup container: dumps the database on an interval and
# keeps the newest $BACKUP_KEEP dumps in /backups (bind-mounted to ./backups).
#
# Restore:  docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" app' < backups/<file>.sql
set -uo pipefail

: "${DB_HOST:?}" "${DB_NAME:?}" "${MYSQL_PWD:?}"
INTERVAL="${BACKUP_INTERVAL_SECONDS:-86400}"
KEEP="${BACKUP_KEEP:-7}"

log() { printf '{"time":"%s","level":"%s","message":"%s"}\n' "$(date -u +%FT%TZ)" "$1" "$2"; }

while true; do
  file="/backups/${DB_NAME}-$(date -u +%Y%m%d-%H%M%S).sql"

  # --single-transaction: consistent snapshot of InnoDB tables without locking writes
  if mysqldump -h "$DB_HOST" -u root --single-transaction --routines --triggers \
       --set-gtid-purged=OFF "$DB_NAME" > "${file}.partial"; then
    mv "${file}.partial" "$file"
    log info "Backup written: $(basename "$file")"
  else
    rm -f "${file}.partial"
    log error "Backup failed"
  fi

  # Retention: delete everything but the newest $KEEP dumps
  ls -1t /backups/"${DB_NAME}"-*.sql 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do
    rm -f "$old"
  done

  sleep "$INTERVAL"
done
