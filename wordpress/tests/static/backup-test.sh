#!/usr/bin/env bash

set -Eeuo pipefail

TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
BACKUP_SCRIPT="$TEST_DIR/../../scripts/backup.sh"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT

fail() {
  printf 'backup test failed: %s\n' "$*" >&2
  exit 1
}

fake_bin="$TMP_DIR/bin"
mkdir -p -- "$fake_bin"

cat >"$fake_bin/flock" <<'EOF'
#!/usr/bin/env bash

set -Eeuo pipefail

[[ -n "${FAKE_FLOCK_STATE:-}" ]] || exit 70

case "${1:-}" in
  -n)
    [[ "${2:-}" == "9" ]] || exit 71
    [[ ! -e "$FAKE_FLOCK_STATE" ]] || exit 1
    printf '%s\n' "$$" >"$FAKE_FLOCK_STATE"
    ;;
  -u)
    [[ "${2:-}" == "9" ]] || exit 72
    rm -f -- "$FAKE_FLOCK_STATE"
    ;;
  *)
    exit 73
    ;;
esac
EOF
chmod +x "$fake_bin/flock"

cat >"$fake_bin/docker" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

[[ "${1:-}" == "compose" ]] || exit 64
shift
[[ "${1:-}" == "version" ]] && exit 0

while [[ "${1:-}" == "-f" ]]; do
  shift 2
done

command_name="${1:-}"
shift || true

case "$command_name" in
  ps)
    printf 'db\nwordpress\n'
    ;;
  exec)
    service=""
    while [[ $# -gt 0 ]]; do
      case "$1" in
        -T)
          shift
          ;;
        -e)
          shift 2
          ;;
        db|wordpress)
          service="$1"
          shift
          break
          ;;
        *)
          exit 65
          ;;
      esac
    done

    if [[ "$service" == "db" && "${1:-}" == "sh" ]]; then
      printf '/usr/bin/mariadb-dump\n'
      exit 0
    fi
    if [[ "$service" == "db" ]]; then
      printf 'CREATE TABLE wp_posts (ID bigint);\n'
      [[ "${FAKE_FAIL_STAGE:-}" != "database" ]] || exit 17
      exit 0
    fi
    if [[ "$service" == "wordpress" && "${1:-}" == "tar" ]]; then
      tar -C "$FAKE_UPLOADS_PARENT" -czf - uploads
      [[ "${FAKE_FAIL_STAGE:-}" != "uploads" ]] || exit 18
      exit 0
    fi
    exit 66
    ;;
  *)
    exit 67
    ;;
esac
EOF
chmod +x "$fake_bin/docker"

write_env() {
  local path="$1"

  cat >"$path" <<'EOF'
SITE_URL=https://example.test
DOMAIN=example.test
DB_NAME=wordpress
DB_USER=wordpress
DB_PASSWORD=test-db-password
DB_ROOT_PASSWORD=test-root-password
BACKUP_RETENTION_DAYS=30
EOF
}

fixture_parent="$TMP_DIR/uploads-parent"
mkdir -p -- "$fixture_parent/uploads/2026/10"
printf 'fixture image\n' >"$fixture_parent/uploads/2026/10/image.jpg"

run_backup() {
  local env_file="$1"
  local backup_dir="$2"
  local failure_stage="${3:-}"

  PATH="$fake_bin:$PATH" \
  YY_ENV_FILE="$env_file" \
  BACKUP_DIR="$backup_dir" \
  YY_OPERATION_LOCK_FILE="$TMP_DIR/operation.lock" \
  FAKE_FLOCK_STATE="$TMP_DIR/flock-state" \
  FAKE_UPLOADS_PARENT="$fixture_parent" \
  FAKE_FAIL_STAGE="$failure_stage" \
    "$BACKUP_SCRIPT"
}

env_file="$TMP_DIR/.env"
write_env "$env_file"

blocked_dir="$TMP_DIR/blocked"
printf 'another operation owns the lock\n' >"$TMP_DIR/flock-state"
if run_backup "$env_file" "$blocked_dir" >/dev/null 2>&1; then
  fail "backup ignored an operation lock held by another process"
fi
if find "$blocked_dir" -mindepth 1 -print -quit 2>/dev/null | grep -q .; then
  fail "a lock-contended backup created output files"
fi
rm -f -- "$TMP_DIR/flock-state"

database_failure_dir="$TMP_DIR/database-failure"
if run_backup "$env_file" "$database_failure_dir" database >/dev/null 2>&1; then
  fail "a failed database dump was reported as successful"
fi
[[ ! -e "$TMP_DIR/flock-state" ]] \
  || fail "a failed backup did not release the operation lock"
if find "$database_failure_dir" -mindepth 1 -print -quit | grep -q .; then
  fail "a failed database dump left a candidate backup file behind"
fi

uploads_failure_dir="$TMP_DIR/uploads-failure"
if run_backup "$env_file" "$uploads_failure_dir" uploads >/dev/null 2>&1; then
  fail "a failed uploads archive was reported as successful"
fi
[[ ! -e "$TMP_DIR/flock-state" ]] \
  || fail "a failed uploads backup did not release the operation lock"
if find "$uploads_failure_dir" -mindepth 1 -print -quit | grep -q .; then
  fail "a failed uploads archive left a candidate backup file behind"
fi

success_dir="$TMP_DIR/success"
mkdir -p -- "$success_dir"

old_stamp="20200101T000000Z"
old_db="$success_dir/yy-db-$old_stamp.sql.gz"
old_uploads="$success_dir/yy-uploads-$old_stamp.tar.gz"
old_checksum="$success_dir/yy-checksums-$old_stamp.sha256"
printf 'old database\n' >"$old_db"
printf 'old uploads\n' >"$old_uploads"
printf 'old checksum\n' >"$old_checksum"
touch -d '60 days ago' "$old_db" "$old_uploads" "$old_checksum"

recent_stamp="20200102T000000Z"
recent_db="$success_dir/yy-db-$recent_stamp.sql.gz"
recent_uploads="$success_dir/yy-uploads-$recent_stamp.tar.gz"
recent_checksum="$success_dir/yy-checksums-$recent_stamp.sha256"
printf 'recent database\n' >"$recent_db"
printf 'recent uploads\n' >"$recent_uploads"
printf 'recent checksum\n' >"$recent_checksum"
touch -d '5 days ago' "$recent_db" "$recent_uploads" "$recent_checksum"

incomplete_stamp="20200103T000000Z"
incomplete_db="$success_dir/yy-db-$incomplete_stamp.sql.gz"
incomplete_uploads="$success_dir/yy-uploads-$incomplete_stamp.tar.gz"
incomplete_checksum="$success_dir/yy-checksums-$incomplete_stamp.sha256"
printf 'incomplete database\n' >"$incomplete_db"
printf 'incomplete checksum\n' >"$incomplete_checksum"
touch -d '60 days ago' "$incomplete_db" "$incomplete_checksum"

unrelated_file="$success_dir/manual-notes.txt"
printf 'operator notes\n' >"$unrelated_file"
touch -d '90 days ago' "$unrelated_file"

backup_output="$(run_backup "$env_file" "$success_dir" 2>/dev/null)"
[[ ! -e "$TMP_DIR/flock-state" ]] \
  || fail "a successful backup did not release the operation lock"

mapfile -t backup_files <<<"$backup_output"
[[ "${#backup_files[@]}" -eq 2 ]] \
  || fail "successful backup did not report exactly one database and uploads archive"
database_file="${backup_files[0]}"
uploads_file="${backup_files[1]}"
checksum_stamp="${database_file##*/yy-db-}"
checksum_stamp="${checksum_stamp%.sql.gz}"
checksum_file="$success_dir/yy-checksums-$checksum_stamp.sha256"

[[ ! -e "$old_db" && ! -e "$old_uploads" && ! -e "$old_checksum" ]] \
  || fail "retention did not remove an old complete backup set together"
[[ -e "$recent_db" && -e "$recent_uploads" && -e "$recent_checksum" ]] \
  || fail "retention removed a recent complete backup set"
[[ -e "$incomplete_db" && ! -e "$incomplete_uploads" && -e "$incomplete_checksum" ]] \
  || fail "retention partially removed an incomplete backup set"
[[ -e "$unrelated_file" ]] \
  || fail "retention removed an unrelated old file"

[[ -n "$database_file" ]] || fail "successful backup did not create a database archive"
[[ -n "$uploads_file" ]] || fail "successful backup did not create an uploads archive"
[[ -n "$checksum_file" ]] || fail "successful backup did not create a checksum file"
gzip -t "$database_file"
tar -tzf "$uploads_file" >/dev/null
(
  cd "$success_dir"
  sha256sum -c "$(basename -- "$checksum_file")" >/dev/null
)
if find "$success_dir" -mindepth 1 -maxdepth 1 -name '.*' -print -quit | grep -q .; then
  fail "successful backup left a temporary file behind"
fi

printf 'Backup behavior contract passed.\n'
