#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

yy_load_env
yy_validate_site_env
yy_require_docker

umask 077

backup_dir="${BACKUP_DIR:-$YY_ROOT/backups}"
if [[ "$backup_dir" != /* ]]; then
  backup_dir="$YY_ROOT/$backup_dir"
fi
mkdir -p "$backup_dir"
chmod 700 "$backup_dir"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
db_file="$backup_dir/yy-db-$stamp.sql.gz"
uploads_file="$backup_dir/yy-uploads-$stamp.tar.gz"
checksum_file="$backup_dir/yy-checksums-$stamp.sha256"
db_temp="$backup_dir/.yy-db-$stamp.sql.gz.partial.$$"
uploads_temp="$backup_dir/.yy-uploads-$stamp.tar.gz.partial.$$"
checksum_temp="$backup_dir/.yy-checksums-$stamp.sha256.partial.$$"

cleanup() {
  rm -f -- "$db_temp" "$uploads_temp" "$checksum_temp"
  yy_release_operation_lock
}
trap cleanup EXIT

yy_acquire_operation_lock

running_services="$(yy_compose ps --services --status running)"
grep -Fxq 'db' <<<"$running_services" \
  || yy_die "The db service is not running"
grep -Fxq 'wordpress' <<<"$running_services" \
  || yy_die "The wordpress service is not running"

dump_command="mariadb-dump"
if ! yy_compose exec -T db sh -c "command -v $dump_command" >/dev/null 2>&1; then
  dump_command="mysqldump"
fi

yy_log "Writing staged database backup for $db_file"
yy_compose exec -T -e "MYSQL_PWD=$DB_ROOT_PASSWORD" db \
  "$dump_command" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --default-character-set=utf8mb4 \
  -uroot "$DB_NAME" | gzip -c >"$db_temp"

yy_log "Writing staged uploads backup for $uploads_file"
yy_compose exec -T wordpress \
  tar -C /var/www/html/wp-content -czf - uploads >"$uploads_temp"

[[ -s "$db_temp" ]] || yy_die "Database backup is empty"
[[ -s "$uploads_temp" ]] || yy_die "Uploads backup is empty"
gzip -t "$db_temp"
tar -tzf "$uploads_temp" >/dev/null

chmod 600 "$db_temp" "$uploads_temp"

# Publish the uploads first and the database dump last. restore.sh selects a
# pair from database filenames, so an interrupted commit never exposes a new
# database dump without its matching uploads archive.
mv -f "$uploads_temp" "$uploads_file"
uploads_temp=""
if ! mv -f "$db_temp" "$db_file"; then
  rm -f -- "$uploads_file"
  yy_die "Failed to publish database backup"
fi
db_temp=""

if command -v sha256sum >/dev/null 2>&1; then
  (
    cd "$backup_dir"
    sha256sum "$(basename -- "$db_file")" "$(basename -- "$uploads_file")" \
      >"$checksum_temp"
  )
  chmod 600 "$checksum_temp"
  mv -f "$checksum_temp" "$checksum_file"
  checksum_temp=""
fi

retention_days="${BACKUP_RETENTION_DAYS:-30}"
if [[ "$retention_days" =~ ^[0-9]+$ ]] && [[ "$retention_days" -gt 0 ]]; then
  while IFS= read -r -d '' database_backup; do
    backup_name="${database_backup##*/}"
    backup_stamp="${backup_name#yy-db-}"
    backup_stamp="${backup_stamp%.sql.gz}"
    [[ "$backup_stamp" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || continue

    uploads_backup="$backup_dir/yy-uploads-$backup_stamp.tar.gz"
    checksum_backup="$backup_dir/yy-checksums-$backup_stamp.sha256"
    [[ -f "$uploads_backup" ]] || continue
    find "$database_backup" -maxdepth 0 -type f -mtime +"$retention_days" -print -quit \
      | grep -q . || continue

    backup_set=("$database_backup" "$uploads_backup")
    [[ ! -f "$checksum_backup" ]] || backup_set+=("$checksum_backup")
    rm -f -- "${backup_set[@]}"
  done < <(find "$backup_dir" -maxdepth 1 -type f -name 'yy-db-*.sql.gz' -print0)
fi

yy_log "Backup complete"
printf '%s\n%s\n' "$db_file" "$uploads_file"
