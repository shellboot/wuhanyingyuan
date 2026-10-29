#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

usage() {
  cat <<'EOF'
Usage: restore.sh (--latest | --database <db.sql.gz> --uploads <uploads.tar.gz>) [options]

Options:
  --latest              Restore the newest backup pair.
  --database <file>     Database dump to restore.
  --uploads <file>      Uploads archive to restore.
  --dry-run             Validate the selected files without changing the site.
  --yes                 Required for a real restore.
  --no-safety-backup    Skip the automatic pre-restore backup.
EOF
}

latest=false
dry_run=false
assume_yes=false
safety_backup=true
database_file=""
uploads_file=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --latest)
      latest=true
      shift
      ;;
    --database)
      database_file="${2:-}"
      shift 2
      ;;
    --uploads)
      uploads_file="${2:-}"
      shift 2
      ;;
    --dry-run)
      dry_run=true
      shift
      ;;
    --yes)
      assume_yes=true
      shift
      ;;
    --no-safety-backup)
      safety_backup=false
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      usage >&2
      exit 2
      ;;
  esac
done

yy_load_env
yy_validate_site_env
yy_require_docker

yy_validate_uploads_archive() {
  local archive="$1"
  local listing
  local member
  local component
  local unsafe_type
  local -a components

  listing="$(LC_ALL=C tar --quoting-style=escape -tzf "$archive")" \
    || yy_die "Uploads backup is not a readable tar.gz archive: $archive"
  [[ -n "$listing" ]] || yy_die "Uploads backup archive is empty: $archive"

  while IFS= read -r member; do
    [[ -n "$member" ]] || yy_die "Uploads backup contains an empty path"
    case "$member" in
      uploads|uploads/) ;;
      uploads/*) ;;
      /*) yy_die "Uploads backup contains an absolute path: $member" ;;
      *) yy_die "Uploads backup contains an entry outside uploads/: $member" ;;
    esac

    IFS='/' read -r -a components <<<"$member"
    for component in "${components[@]}"; do
      [[ "$component" != ".." ]] \
        || yy_die "Uploads backup contains a parent-directory path: $member"
    done
  done <<<"$listing"

  unsafe_type="$(
    LC_ALL=C tar --quoting-style=escape -tvzf "$archive" \
      | awk 'substr($0, 1, 1) != "d" && substr($0, 1, 1) != "-" { print; exit }'
  )" || yy_die "Uploads backup entries could not be inspected: $archive"
  [[ -z "$unsafe_type" ]] \
    || yy_die "Uploads backup contains a link or special file: ${unsafe_type#* }"
}

if [[ "$latest" == "true" ]]; then
  [[ -z "$database_file" && -z "$uploads_file" ]] \
    || yy_die "--latest cannot be combined with --database or --uploads"
  backup_dir="${BACKUP_DIR:-$YY_ROOT/backups}"
  if [[ "$backup_dir" != /* ]]; then
    backup_dir="$YY_ROOT/$backup_dir"
  fi
  latest_db=""
  while IFS= read -r -d '' candidate; do
    if [[ -z "$latest_db" || "$candidate" -nt "$latest_db" ]]; then
      latest_db="$candidate"
    fi
  done < <(find "$backup_dir" -maxdepth 1 -type f -name 'yy-db-*.sql.gz' -print0 2>/dev/null)
  [[ -n "$latest_db" ]] || yy_die "No database backups found in $backup_dir"
  stamp="$(basename -- "$latest_db")"
  stamp="${stamp#yy-db-}"
  stamp="${stamp%.sql.gz}"
  database_file="$latest_db"
  uploads_file="$backup_dir/yy-uploads-$stamp.tar.gz"
fi

[[ -n "$database_file" && -n "$uploads_file" ]] \
  || { usage >&2; exit 2; }
[[ -f "$database_file" ]] || yy_die "Database backup not found: $database_file"
[[ -f "$uploads_file" ]] || yy_die "Uploads backup not found: $uploads_file"

yy_log "Validating backup files"
gzip -t "$database_file"
yy_validate_uploads_archive "$uploads_file"

backup_dir="$(cd -- "$(dirname -- "$database_file")" >/dev/null 2>&1 && pwd)"
checksum_file=""
for candidate in "$backup_dir"/yy-checksums-*.sha256; do
  [[ -f "$candidate" ]] || continue
  if grep -Fq "$(basename -- "$database_file")" "$candidate" \
    && grep -Fq "$(basename -- "$uploads_file")" "$candidate"; then
    checksum_file="$candidate"
    break
  fi
done
if [[ -n "$checksum_file" ]] && command -v sha256sum >/dev/null 2>&1; then
  (cd "$backup_dir" && sha256sum -c "$(basename -- "$checksum_file")") >/dev/null
  yy_log "Checksums verified"
fi

if [[ "$dry_run" == "true" ]]; then
  printf 'database=%s\nuploads=%s\n' "$database_file" "$uploads_file"
  yy_log "Dry run completed; no site data was changed"
  exit 0
fi

[[ "$assume_yes" == "true" ]] \
  || yy_die "Refusing to restore without --yes"

maintenance_active=false
restore_stage=""
uploads_rollback=""
database_restore_attempted=false
safety_database_file=""
safety_uploads_file=""

restore_database() {
  local file="$1"

  gzip -dc "$file" | yy_compose exec -T \
    -e "MYSQL_PWD=$DB_ROOT_PASSWORD" db \
    mariadb --default-character-set=utf8mb4 -uroot "$DB_NAME"
}

cleanup() {
  exit_code=$?
  safe_to_reopen=true
  trap - EXIT

  if [[ -n "$uploads_rollback" ]]; then
    if yy_compose exec -T wordpress yy-uploads-switch rollback "$uploads_rollback"; then
      uploads_rollback=""
    else
      yy_log "ERROR: failed to roll back uploads; inspect the site before reopening it"
      safe_to_reopen=false
    fi
  fi

  if [[ "$database_restore_attempted" == "true" ]]; then
    if [[ -n "$safety_database_file" && -f "$safety_database_file" ]]; then
      yy_log "Restoring the database from the safety backup"
      if restore_database "$safety_database_file"; then
        database_restore_attempted=false
      else
        yy_log "ERROR: failed to roll back the database; restore the safety backup manually"
        safe_to_reopen=false
      fi
    else
      yy_log "ERROR: database restore failed without a safety backup available"
      safe_to_reopen=false
    fi
  fi

  if [[ -n "$restore_stage" ]]; then
    yy_compose exec -T wordpress rm -rf -- "$restore_stage" >/dev/null 2>&1 || true
  fi
  if [[ "$maintenance_active" == "true" ]]; then
    if [[ "$safe_to_reopen" == "true" ]]; then
      if ! yy_wp maintenance-mode deactivate >/dev/null 2>&1; then
        yy_log "ERROR: failed to disable WordPress maintenance mode"
      fi
    else
      yy_log "ERROR: restore was not fully rolled back; maintenance mode remains active"
    fi
  fi

  yy_release_operation_lock
  exit "$exit_code"
}
trap cleanup EXIT

yy_acquire_operation_lock

# Mark the mode as active before the command so a partial activation failure is
# always followed by a best-effort deactivation.
maintenance_active=true
yy_wp maintenance-mode activate >/dev/null

if [[ "$safety_backup" == "true" ]]; then
  yy_log "Creating a safety backup before restore"
  safety_output="$(YY_OPERATION_LOCK_HELD=1 bash "$SCRIPT_DIR/backup.sh")"
  safety_database_file="$(printf '%s\n' "$safety_output" | sed -n '1p')"
  safety_uploads_file="$(printf '%s\n' "$safety_output" | sed -n '2p')"
  [[ -f "$safety_database_file" && -f "$safety_uploads_file" ]] \
    || yy_die "The pre-restore safety backup did not return a valid file pair"
else
  yy_log "WARNING: database rollback will be unavailable because the safety backup is disabled"
fi

restore_id="$(date -u +%Y%m%dT%H%M%SZ)-$$"
restore_stage="/var/www/html/wp-content/uploads/.yy-restore-$restore_id"
yy_log "Staging restored uploads"
yy_compose exec -T wordpress mkdir -p -- "$restore_stage"
yy_compose exec -T wordpress tar -xzf - -C "$restore_stage" <"$uploads_file"
yy_compose exec -T wordpress test -d "$restore_stage/uploads"

yy_log "Activating restored uploads with rollback protection"
uploads_rollback="$(yy_compose exec -T wordpress yy-uploads-switch activate "$restore_stage")"
[[ -n "$uploads_rollback" ]] || yy_die "Uploads activation did not return a rollback path"
restore_stage=""
yy_compose exec -T --user root wordpress \
  chown -R www-data:www-data /var/www/html/wp-content/uploads >/dev/null

yy_log "Restoring the database"
database_restore_attempted=true
restore_database "$database_file"

yy_wp cache flush >/dev/null
yy_wp rewrite flush --hard >/dev/null
yy_compose exec -T wordpress yy-uploads-switch commit "$uploads_rollback"
uploads_rollback=""
database_restore_attempted=false

if ! yy_wp maintenance-mode deactivate >/dev/null; then
  yy_log "ERROR: restored data is committed, but maintenance mode is still active"
  exit 1
fi
maintenance_active=false

yy_log "Restore complete"
