#!/usr/bin/env bash

set -Eeuo pipefail

TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
RESTORE_SCRIPT="$TEST_DIR/../../scripts/restore.sh"
SWITCH_SCRIPT="$TEST_DIR/../../docker/wordpress/yy-uploads-switch.sh"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT

fail() {
  printf 'restore test failed: %s\n' "$*" >&2
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

containerize_path() {
  local host_path="$1"

  [[ "$host_path" == "$FAKE_HOST_WP_ROOT" || "$host_path" == "$FAKE_HOST_WP_ROOT"/* ]] \
    || {
      printf 'unexpected host path: %s\n' "$host_path" >&2
      exit 70
    }
  printf '%s\n' "${host_path#"$FAKE_HOST_WP_ROOT"}"
}

hostify_path() {
  local container_path="$1"

  [[ "$container_path" == "/var/www/html" || "$container_path" == "/var/www/html"/* ]] \
    || {
      printf 'unexpected container path: %s\n' "$container_path" >&2
      exit 71
    }
  printf '%s%s\n' "$FAKE_HOST_WP_ROOT" "$container_path"
}

log_call() {
  printf '%s\n' "$*" >>"$FAKE_STATE_DIR/docker.log"
}

map_tar_paths() {
  local -a mapped=()
  local path

  while [[ $# -gt 0 ]]; do
    if [[ "$1" == "-C" ]]; then
      mapped+=("-C")
      shift
      path="$(hostify_path "$1")"
      mapped+=("$path")
      shift
    else
      mapped+=("$1")
      shift
    fi
  done

  printf '%s\0' "${mapped[@]}"
}

[[ "${1:-}" == "compose" ]] || exit 64
shift
[[ "${1:-}" == "version" ]] && exit 0

while [[ $# -gt 0 ]]; do
  case "$1" in
    -f|--profile)
      shift 2
      ;;
    *)
      break
      ;;
  esac
done

command_name="${1:-}"
shift || true
log_call "$command_name" "$@"

case "$command_name" in
  ps)
    printf 'db\nwordpress\n'
    ;;
  exec)
    env_name=""
    while [[ $# -gt 0 ]]; do
      case "$1" in
        -T)
          shift
          ;;
        -e)
          env_name="$2"
          shift 2
          ;;
        --user)
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

    if [[ "$service" == "db" && "${1:-}" == "mariadb-dump" ]]; then
      cat "$FAKE_STATE_DIR/database"
      exit 0
    fi

    if [[ "$service" == "db" && ( "${1:-}" == "mariadb" || "${1:-}" == "mysql" ) ]]; then
      payload="$(cat)"
      printf '%s' "$payload" >"$FAKE_STATE_DIR/database"
      printf '%s\n' "$payload" >>"$FAKE_STATE_DIR/database-imports"
      if [[ "$payload" == *"TARGET_DB_FAIL"* ]]; then
        exit 17
      fi
      exit 0
    fi

    if [[ "$service" == "wordpress" && "${1:-}" == "mkdir" ]]; then
      shift 3
      target="$(hostify_path "$1")"
      mkdir -p -- "$target"
      exit 0
    fi

    if [[ "$service" == "wordpress" && "${1:-}" == "tar" ]]; then
      mapfile -d '' -t tar_args < <(map_tar_paths "${@:2}")
      tar "${tar_args[@]}"
      exit $?
    fi

    if [[ "$service" == "wordpress" && "${1:-}" == "test" ]]; then
      shift 2
      target="$(hostify_path "$1")"
      test -d "$target"
      exit $?
    fi

    if [[ "$service" == "wordpress" && "${1:-}" == "yy-uploads-switch" ]]; then
      mode="$2"
      target="${3:-}"
      printf 'uploads-switch %s\n' "$mode" >>"$FAKE_STATE_DIR/event.log"
      if [[ "$mode" == "commit" \
        && "${FAKE_FAIL_STAGE:-}" == "uploads-commit" ]]; then
        exit 21
      fi
      host_target=""
      if [[ -n "$target" ]]; then
        host_target="$(hostify_path "$target")"
      fi
      output="$(
        YY_UPLOADS_DIR="$FAKE_HOST_WP_ROOT/var/www/html/wp-content/uploads" \
          "$FAKE_SWITCH_SCRIPT" "$mode" "$host_target"
      )"
      if [[ "$mode" == "activate" && -n "$output" ]]; then
        containerize_path "$output"
      fi
      exit 0
    fi

    if [[ "$service" == "wordpress" && "${1:-}" == "chown" ]]; then
      if [[ "${FAKE_FAIL_STAGE:-}" == "chown" ]]; then
        exit 19
      fi
      exit 0
    fi

    if [[ "$service" == "wordpress" && "${1:-}" == "rm" ]]; then
      shift 3
      target="$(hostify_path "$1")"
      rm -rf -- "$target"
      exit 0
    fi

    exit 66
    ;;
  run)
    while [[ $# -gt 0 ]]; do
      case "$1" in
        --rm|-T)
          shift
          ;;
        wpcli)
          shift
          break
          ;;
        *)
          exit 67
          ;;
      esac
    done
    [[ "${1:-}" == "wp" ]] || exit 68
    shift
    printf '%s\n' "$*" >>"$FAKE_STATE_DIR/wp.log"
    printf 'wp %s\n' "$*" >>"$FAKE_STATE_DIR/event.log"
    if [[ "$*" == "maintenance-mode activate" \
      && "${FAKE_FAIL_STAGE:-}" == "maintenance-activate" ]]; then
      exit 20
    fi
    exit 0
    ;;
  *)
    exit 69
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

create_target_uploads() {
  local archive="$1"
  local fixture="$2"

  mkdir -p -- "$fixture/uploads/2026/10"
  printf 'new image\n' >"$fixture/uploads/2026/10/new.jpg"
  tar -czf "$archive" -C "$fixture" uploads
}

run_case() {
  local name="$1"
  local failure_stage="$2"
  local expected_status="$3"
  local case_root="$TMP_DIR/$name"
  local state_dir="$case_root/state"
  local host_wp_root="$case_root/wp-root"
  local uploads_dir="$host_wp_root/var/www/html/wp-content/uploads"
  local backup_dir="$case_root/backups"
  local target_database="$backup_dir/yy-db-20261029T120000Z.sql.gz"
  local target_uploads="$backup_dir/yy-uploads-20261029T120000Z.tar.gz"
  local env_file="$case_root/.env"
  local status=0
  local database

  mkdir -p -- "$state_dir" "$uploads_dir/2025/10" "$backup_dir"
  printf 'old image\n' >"$uploads_dir/2025/10/old.jpg"
  printf 'CURRENT_DB\n' >"$state_dir/database"
  if [[ "$failure_stage" == "database" ]]; then
    printf 'TARGET_DB_FAIL\n' | gzip -c >"$target_database"
  else
    printf 'TARGET_DB\n' | gzip -c >"$target_database"
  fi
  create_target_uploads "$target_uploads" "$case_root/archive-fixture"
  write_env "$env_file"

  PATH="$fake_bin:$PATH" \
  YY_ENV_FILE="$env_file" \
  BACKUP_DIR="$backup_dir" \
  YY_OPERATION_LOCK_FILE="$case_root/operation.lock" \
  FAKE_FLOCK_STATE="$state_dir/flock-state" \
  FAKE_STATE_DIR="$state_dir" \
  FAKE_HOST_WP_ROOT="$host_wp_root" \
  FAKE_SWITCH_SCRIPT="$SWITCH_SCRIPT" \
  FAKE_FAIL_STAGE="$failure_stage" \
    "$RESTORE_SCRIPT" \
      --database "$target_database" \
      --uploads "$target_uploads" \
      --yes >"$case_root/restore.log" 2>&1 || status=$?

  if [[ "$expected_status" == "success" && "$status" -ne 0 ]]; then
    cat "$case_root/restore.log" >&2
    fail "$name restore unexpectedly failed with status $status"
  fi
  if [[ "$expected_status" == "failure" && "$status" -eq 0 ]]; then
    fail "$name restore unexpectedly succeeded"
  fi

  [[ ! -e "$state_dir/flock-state" ]] \
    || fail "$name restore did not release the operation lock"

  database="$(cat "$state_dir/database")"
  if [[ "$expected_status" == "success" ]]; then
    [[ "$database" == "TARGET_DB" ]] \
      || fail "$name restore did not leave the requested database active"
    [[ -f "$uploads_dir/2026/10/new.jpg" ]] \
      || fail "$name restore did not activate the requested uploads"
    [[ ! -e "$uploads_dir/2025/10/old.jpg" ]] \
      || fail "$name restore left the previous uploads visible after commit"
  else
    [[ "$database" == "CURRENT_DB" ]] \
      || fail "$name restore did not roll the database back to the safety backup"
    [[ -f "$uploads_dir/2025/10/old.jpg" ]] \
      || fail "$name restore did not roll the previous uploads back"
    [[ ! -e "$uploads_dir/2026/10/new.jpg" ]] \
      || fail "$name failed restore left the requested uploads visible"
  fi

  if find "$uploads_dir" -maxdepth 1 \
    \( -name '.yy-restore-*' -o -name '.yy-uploads-rollback.*' \) \
    -print -quit | grep -q .; then
    fail "$name restore left staging or rollback state behind"
  fi
  grep -Fxq 'maintenance-mode activate' "$state_dir/wp.log" \
    || fail "$name restore did not enter maintenance mode"
  grep -Fxq 'maintenance-mode deactivate' "$state_dir/wp.log" \
    || fail "$name restore did not leave maintenance mode"
}

run_case success none success
run_case database-failure database failure
run_case chown-failure chown failure
run_case maintenance-failure maintenance-activate failure
run_case uploads-commit-failure uploads-commit failure

chown_failure_dir="$TMP_DIR/chown-failure"
if grep -Fq 'TARGET_DB' "$chown_failure_dir/state/database-imports" 2>/dev/null; then
  fail "chown failure imported the target database before rolling back uploads"
fi

commit_failure_events="$TMP_DIR/uploads-commit-failure/state/event.log"
commit_line="$(grep -nFx 'uploads-switch commit' "$commit_failure_events" | cut -d: -f1)"
deactivate_line="$(grep -nFx 'wp maintenance-mode deactivate' "$commit_failure_events" | cut -d: -f1)"
[[ -n "$commit_line" && -n "$deactivate_line" ]] \
  || fail "uploads commit failure did not exercise the expected restore sequence"
[[ "$commit_line" -lt "$deactivate_line" ]] \
  || fail "restore left maintenance mode before committing the restored uploads"

no_safety_case="$TMP_DIR/no-safety-backup-failure"
no_safety_state="$no_safety_case/state"
no_safety_wp_root="$no_safety_case/wp-root"
no_safety_uploads="$no_safety_wp_root/var/www/html/wp-content/uploads"
no_safety_backups="$no_safety_case/backups"
mkdir -p -- "$no_safety_state" "$no_safety_uploads/2025/10" "$no_safety_backups"
printf 'old image\n' >"$no_safety_uploads/2025/10/old.jpg"
printf 'CURRENT_DB\n' >"$no_safety_state/database"
printf 'TARGET_DB_FAIL\n' | gzip -c \
  >"$no_safety_backups/yy-db-20261029T150000Z.sql.gz"
create_target_uploads \
  "$no_safety_backups/yy-uploads-20261029T150000Z.tar.gz" \
  "$no_safety_case/archive-fixture"
write_env "$no_safety_case/.env"

no_safety_status=0
PATH="$fake_bin:$PATH" \
YY_ENV_FILE="$no_safety_case/.env" \
BACKUP_DIR="$no_safety_backups" \
YY_OPERATION_LOCK_FILE="$no_safety_case/operation.lock" \
FAKE_FLOCK_STATE="$no_safety_state/flock-state" \
FAKE_STATE_DIR="$no_safety_state" \
FAKE_HOST_WP_ROOT="$no_safety_wp_root" \
FAKE_SWITCH_SCRIPT="$SWITCH_SCRIPT" \
  "$RESTORE_SCRIPT" \
    --database "$no_safety_backups/yy-db-20261029T150000Z.sql.gz" \
    --uploads "$no_safety_backups/yy-uploads-20261029T150000Z.tar.gz" \
    --no-safety-backup --yes >"$no_safety_case/restore.log" 2>&1 \
  || no_safety_status=$?

[[ "$no_safety_status" -ne 0 ]] \
  || fail "restore without a safety backup unexpectedly ignored the database failure"
[[ "$(cat "$no_safety_state/database")" == "TARGET_DB_FAIL" ]] \
  || fail "no-safety-backup failure did not exercise the partial database state"
[[ -f "$no_safety_uploads/2025/10/old.jpg" ]] \
  || fail "no-safety-backup failure did not roll the previous uploads back"
[[ ! -e "$no_safety_uploads/2026/10/new.jpg" ]] \
  || fail "no-safety-backup failure left the requested uploads visible"
grep -Fxq 'wp maintenance-mode activate' "$no_safety_state/event.log" \
  || fail "no-safety-backup failure did not enter maintenance mode"
if grep -Fxq 'wp maintenance-mode deactivate' "$no_safety_state/event.log"; then
  fail "restore reopened an inconsistent site after database rollback was unavailable"
fi
[[ ! -e "$no_safety_state/flock-state" ]] \
  || fail "no-safety-backup failure did not release the operation lock"

create_unsafe_archive() {
  local name="$1"
  local transform="$2"
  local archive="$TMP_DIR/$name.tar.gz"
  local fixture="$TMP_DIR/$name-fixture"

  mkdir -p -- "$fixture"
  printf 'unsafe payload\n' >"$fixture/payload"
  tar -czf "$archive" --transform="$transform" -C "$fixture" payload
  printf '%s\n' "$archive"
}

run_rejected_archive_case() {
  local name="$1"
  local unsafe_archive="$2"
  local case_root="$TMP_DIR/$name"
  local state_dir="$case_root/state"
  local host_wp_root="$case_root/wp-root"
  local uploads_dir="$host_wp_root/var/www/html/wp-content/uploads"
  local backup_dir="$case_root/backups"
  local target_database="$backup_dir/yy-db-20261029T130000Z.sql.gz"
  local env_file="$case_root/.env"
  local status=0

  mkdir -p -- "$state_dir" "$uploads_dir/2025/10" "$backup_dir"
  printf 'old image\n' >"$uploads_dir/2025/10/old.jpg"
  printf 'CURRENT_DB\n' >"$state_dir/database"
  printf 'TARGET_DB\n' | gzip -c >"$target_database"
  write_env "$env_file"

  PATH="$fake_bin:$PATH" \
  YY_ENV_FILE="$env_file" \
  BACKUP_DIR="$backup_dir" \
  YY_OPERATION_LOCK_FILE="$case_root/operation.lock" \
  FAKE_FLOCK_STATE="$state_dir/flock-state" \
  FAKE_STATE_DIR="$state_dir" \
  FAKE_HOST_WP_ROOT="$host_wp_root" \
  FAKE_SWITCH_SCRIPT="$SWITCH_SCRIPT" \
    "$RESTORE_SCRIPT" \
      --database "$target_database" \
      --uploads "$unsafe_archive" \
      --yes >"$case_root/restore.log" 2>&1 || status=$?

  [[ "$status" -ne 0 ]] || fail "$name unsafe uploads archive was accepted"
  [[ "$(cat "$state_dir/database")" == "CURRENT_DB" ]] \
    || fail "$name changed the database while rejecting an unsafe archive"
  cmp -s <(printf 'old image\n') "$uploads_dir/2025/10/old.jpg" \
    || fail "$name changed current uploads while rejecting an unsafe archive"
  [[ ! -e "$state_dir/wp.log" ]] \
    || fail "$name entered WordPress maintenance mode before archive validation"
  [[ ! -e "$state_dir/flock-state" ]] \
    || fail "$name left the operation lock held after archive rejection"
}

run_rejected_archive_case \
  path-traversal \
  "$(create_unsafe_archive path-traversal 's|^payload$|../outside.jpg|')"
run_rejected_archive_case \
  absolute-path \
  "$(create_unsafe_archive absolute-path 's|^payload$|/outside.jpg|')"
run_rejected_archive_case \
  outside-uploads \
  "$(create_unsafe_archive outside-uploads 's|^payload$|themes/payload.php|')"

link_archive="$TMP_DIR/symlink.tar.gz"
printf '%s' \
  'H4sIAH3T4moC/+3RTQ5AMBBA4TmKC4i/Vs/TYCEEUY3ra8SGWNiw8b7NzG4meX7qR1u7RF6UBkbrfQbXebMblSmJtHzAu8XO4aT8kz/6T9a5tY77duhe6V8q9bx/VpSFkShPmqU6PqM/AAAAAAAAAAAAAAAAAJxtwMw+9gAoAAA=' \
  | base64 -d >"$link_archive"
run_rejected_archive_case symlink "$link_archive"

hardlink_fixture="$TMP_DIR/hardlink-fixture"
hardlink_archive="$TMP_DIR/hardlink.tar.gz"
mkdir -p -- "$hardlink_fixture/uploads"
printf 'hardlink payload\n' >"$hardlink_fixture/uploads/original.jpg"
ln "$hardlink_fixture/uploads/original.jpg" "$hardlink_fixture/uploads/hardlink.jpg"
tar -czf "$hardlink_archive" -C "$hardlink_fixture" uploads
run_rejected_archive_case hardlink "$hardlink_archive"

lock_case="$TMP_DIR/lock-contended"
lock_state="$lock_case/state/flock-state"
mkdir -p -- "$lock_case/state" "$lock_case/wp-root/var/www/html/wp-content/uploads/2025/10" \
  "$lock_case/backups"
printf 'old image\n' >"$lock_case/wp-root/var/www/html/wp-content/uploads/2025/10/old.jpg"
printf 'CURRENT_DB\n' >"$lock_case/state/database"
printf 'TARGET_DB\n' | gzip -c >"$lock_case/backups/yy-db-20261029T140000Z.sql.gz"
create_target_uploads "$lock_case/backups/yy-uploads-20261029T140000Z.tar.gz" \
  "$lock_case/archive-fixture"
write_env "$lock_case/.env"
printf 'another operation owns the lock\n' >"$lock_state"

lock_status=0
PATH="$fake_bin:$PATH" \
YY_ENV_FILE="$lock_case/.env" \
BACKUP_DIR="$lock_case/backups" \
YY_OPERATION_LOCK_FILE="$lock_case/operation.lock" \
FAKE_FLOCK_STATE="$lock_state" \
FAKE_STATE_DIR="$lock_case/state" \
FAKE_HOST_WP_ROOT="$lock_case/wp-root" \
FAKE_SWITCH_SCRIPT="$SWITCH_SCRIPT" \
  "$RESTORE_SCRIPT" \
    --database "$lock_case/backups/yy-db-20261029T140000Z.sql.gz" \
    --uploads "$lock_case/backups/yy-uploads-20261029T140000Z.tar.gz" \
    --yes >"$lock_case/restore.log" 2>&1 || lock_status=$?

[[ "$lock_status" -ne 0 ]] || fail "restore ignored an operation lock held by another process"
[[ "$(cat "$lock_case/state/database")" == "CURRENT_DB" ]] \
  || fail "a lock-contended restore changed the database"
[[ -f "$lock_case/wp-root/var/www/html/wp-content/uploads/2025/10/old.jpg" ]] \
  || fail "a lock-contended restore changed current uploads"
[[ ! -e "$lock_case/state/wp.log" ]] \
  || fail "a lock-contended restore entered maintenance mode"
[[ -f "$lock_state" ]] \
  || fail "a losing restore removed the operation lock owned by another process"

printf 'Restore behavior contract passed.\n'
