#!/usr/bin/env bash

set -Eeuo pipefail

TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
SWITCH_SCRIPT="$TEST_DIR/../../docker/wordpress/yy-uploads-switch.sh"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT

fail() {
  printf 'uploads switch test failed: %s\n' "$*" >&2
  exit 1
}

new_fixture() {
  local root="$1"
  local uploads="$root/uploads"
  local stage="$root/stage"

  mkdir -p -- "$uploads/2025/10" "$stage/uploads/2026/10"
  printf 'old image\n' >"$uploads/2025/10/old.jpg"
  printf 'old rules\n' >"$uploads/.htaccess"
  printf 'new image\n' >"$stage/uploads/2026/10/new.jpg"
}

committed_root="$TMP_DIR/committed"
new_fixture "$committed_root"
uploads_dir="$committed_root/uploads"
stage_dir="$committed_root/stage"

rollback_dir="$(
  YY_UPLOADS_DIR="$uploads_dir" \
    "$SWITCH_SCRIPT" activate "$stage_dir"
)"

[[ -n "$rollback_dir" && -d "$rollback_dir" ]] \
  || fail "activation did not return a rollback directory"
[[ -f "$uploads_dir/2026/10/new.jpg" ]] \
  || fail "activated uploads were not moved into place"
[[ ! -e "$uploads_dir/2025/10/old.jpg" ]] \
  || fail "old uploads remained visible after activation"
[[ -f "$rollback_dir/files/2025/10/old.jpg" ]] \
  || fail "old uploads were not retained for rollback"

YY_UPLOADS_DIR="$uploads_dir" "$SWITCH_SCRIPT" commit "$rollback_dir"
[[ ! -e "$rollback_dir" ]] || fail "commit left the rollback directory behind"
[[ -f "$uploads_dir/2026/10/new.jpg" ]] \
  || fail "commit removed the activated uploads"

rolled_back_root="$TMP_DIR/rolled-back"
new_fixture "$rolled_back_root"
uploads_dir="$rolled_back_root/uploads"
stage_dir="$rolled_back_root/stage"
rollback_dir="$(
  YY_UPLOADS_DIR="$uploads_dir" \
    "$SWITCH_SCRIPT" activate "$stage_dir"
)"

YY_UPLOADS_DIR="$uploads_dir" "$SWITCH_SCRIPT" rollback "$rollback_dir"
[[ -f "$uploads_dir/2025/10/old.jpg" ]] \
  || fail "explicit rollback did not restore the old uploads"
[[ -f "$uploads_dir/.htaccess" ]] \
  || fail "explicit rollback did not restore hidden upload files"
[[ ! -e "$uploads_dir/2026/10/new.jpg" ]] \
  || fail "explicit rollback left activated uploads visible"
[[ ! -e "$rollback_dir" ]] || fail "explicit rollback left its state behind"

failed_root="$TMP_DIR/failed"
new_fixture "$failed_root"
uploads_dir="$failed_root/uploads"
stage_dir="$failed_root/stage"
fake_bin="$TMP_DIR/failing-bin"
real_mv="$(command -v mv)"
mkdir -p -- "$fake_bin"
{
  printf '#!/usr/bin/env bash\n'
  printf 'set -Eeuo pipefail\n'
  printf 'if [[ "${2:-}" == */stage/uploads/* ]]; then exit 19; fi\n'
  printf 'exec "%s" "$@"\n' "$real_mv"
} >"$fake_bin/mv"
chmod +x "$fake_bin/mv"

if PATH="$fake_bin:$PATH" \
  YY_UPLOADS_DIR="$uploads_dir" \
    "$SWITCH_SCRIPT" activate "$stage_dir" >/dev/null 2>&1; then
  fail "a failed uploads activation was reported as successful"
fi
[[ -f "$uploads_dir/2025/10/old.jpg" ]] \
  || fail "failed activation did not restore the old uploads"
[[ -f "$uploads_dir/.htaccess" ]] \
  || fail "failed activation did not restore hidden upload files"
[[ ! -e "$uploads_dir/2026/10/new.jpg" ]] \
  || fail "failed activation left new uploads visible"
if find "$uploads_dir" -maxdepth 1 -type d -name '.yy-uploads-rollback.*' -print -quit | grep -q .; then
  fail "failed activation left its rollback state behind"
fi

printf 'Uploads switch behavior contract passed.\n'
