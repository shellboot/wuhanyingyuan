#!/usr/bin/env bash

set -Eeuo pipefail

TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
ENTRYPOINT="$TEST_DIR/../../docker/wordpress/yy-entrypoint.sh"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT

fail() {
  printf 'entrypoint test failed: %s\n' "$*" >&2
  exit 1
}

write_file() {
  local path="$1"
  local value="$2"

  mkdir -p -- "$(dirname -- "$path")"
  printf '%s\n' "$value" >"$path"
}

core_source="$TMP_DIR/source"
core_target="$TMP_DIR/target"

write_file "$core_source/wp-settings.php" "new core"
write_file "$core_source/wp-load.php" "new loader"
write_file "$core_source/wp-admin/admin.php" "new admin"
write_file "$core_source/wp-includes/version.php" "\$wp_version = '7.1.4';"
write_file "$core_target/wp-settings.php" "old core"
write_file "$core_target/wp-includes/version.php" "\$wp_version = '7.0.0';"
write_file "$core_target/wp-includes/obsolete.php" "old file"
write_file "$core_target/obsolete-top-level.php" "retired core entry"
write_file "$core_target/robots.txt" "user-managed robots"
write_file "$core_target/wp-content/plugins/site/plugin.php" "site plugin"
write_file "$core_target/wp-config.php" "site config"

YY_CORE_SOURCE="$core_source" \
YY_CORE_TARGET="$core_target" \
YY_DOCKER_ENTRYPOINT=true \
  "$ENTRYPOINT"

[[ "$(cat "$core_target/wp-settings.php")" == "new core" ]] \
  || fail "updated core files were not activated"
[[ ! -e "$core_target/wp-includes/obsolete.php" ]] \
  || fail "obsolete core files were retained"
[[ ! -e "$core_target/obsolete-top-level.php" ]] \
  || fail "obsolete top-level core files were retained"
[[ "$(cat "$core_target/robots.txt")" == "user-managed robots" ]] \
  || fail "a user-managed root file was modified"
[[ "$(cat "$core_target/wp-content/plugins/site/plugin.php")" == "site plugin" ]] \
  || fail "site plugins were modified"
[[ "$(cat "$core_target/wp-config.php")" == "site config" ]] \
  || fail "wp-config.php was modified"

rm -f -- "$core_source/wp-settings.php"
write_file "$core_target/wp-settings.php" "known good core"

if YY_CORE_SOURCE="$core_source" \
  YY_CORE_TARGET="$core_target" \
  YY_DOCKER_ENTRYPOINT=true \
    "$ENTRYPOINT"; then
  fail "an incomplete WordPress core was accepted"
fi

[[ "$(cat "$core_target/wp-settings.php")" == "known good core" ]] \
  || fail "an incomplete core modified the existing installation"

repair_source="$TMP_DIR/repair-source"
repair_target="$TMP_DIR/repair-target"

write_file "$repair_source/wp-settings.php" "same-version core"
write_file "$repair_source/wp-load.php" "same-version loader"
write_file "$repair_source/wp-admin/admin.php" "same-version admin"
write_file "$repair_source/wp-admin/includes.php" "same-version includes"
write_file "$repair_source/wp-includes/version.php" "\$wp_version = '7.1.4';"
write_file "$repair_target/wp-settings.php" "same-version core"
write_file "$repair_target/wp-load.php" "same-version loader"
write_file "$repair_target/wp-admin/admin.php" "damaged admin"
write_file "$repair_target/wp-admin/includes.php" "same-version includes"
write_file "$repair_target/wp-includes/version.php" "\$wp_version = '7.1.4';"
write_file "$repair_target/wp-content/plugins/site/plugin.php" "site plugin"
write_file "$repair_target/wp-config.php" "site config"

YY_CORE_SOURCE="$repair_source" \
YY_CORE_TARGET="$repair_target" \
YY_DOCKER_ENTRYPOINT=true \
  "$ENTRYPOINT"

[[ "$(cat "$repair_target/wp-admin/admin.php")" == "same-version admin" ]] \
  || fail "a damaged same-version core file was not repaired"
[[ "$(cat "$repair_target/wp-content/plugins/site/plugin.php")" == "site plugin" ]] \
  || fail "same-version repair modified site plugins"

stale_source="$TMP_DIR/stale-source"
stale_target="$TMP_DIR/stale-target"

write_file "$stale_source/wp-settings.php" "matching core"
write_file "$stale_source/wp-load.php" "matching loader"
write_file "$stale_source/wp-admin/admin.php" "matching admin"
write_file "$stale_source/wp-includes/version.php" "\$wp_version = '7.1.4';"
write_file "$stale_target/wp-settings.php" "matching core"
write_file "$stale_target/wp-load.php" "matching loader"
write_file "$stale_target/wp-admin/admin.php" "matching admin"
write_file "$stale_target/wp-includes/version.php" "\$wp_version = '7.1.4';"
write_file "$stale_target/wp-includes/obsolete-core.php" "stale core file"
write_file "$stale_target/wp-content/plugins/site/plugin.php" "site plugin"
write_file "$stale_target/wp-config.php" "site config"

YY_CORE_SOURCE="$stale_source" \
YY_CORE_TARGET="$stale_target" \
YY_DOCKER_ENTRYPOINT=true \
  "$ENTRYPOINT"

[[ ! -e "$stale_target/wp-includes/obsolete-core.php" ]] \
  || fail "a same-version stale nested core file was retained"
[[ "$(cat "$stale_target/wp-content/plugins/site/plugin.php")" == "site plugin" ]] \
  || fail "same-version stale cleanup modified site plugins"

rollback_source="$TMP_DIR/rollback-source"
rollback_target="$TMP_DIR/rollback-target"

write_file "$rollback_source/wp-settings.php" "new rollback core"
write_file "$rollback_source/wp-load.php" "new rollback loader"
write_file "$rollback_source/wp-admin/admin.php" "new rollback admin"
write_file "$rollback_source/wp-includes/version.php" "\$wp_version = '7.2.0';"
write_file "$rollback_target/wp-settings.php" "old rollback core"
write_file "$rollback_target/wp-load.php" "old rollback loader"
write_file "$rollback_target/wp-admin/admin.php" "old rollback admin"
write_file "$rollback_target/wp-includes/version.php" "\$wp_version = '7.1.4';"
write_file "$rollback_target/wp-content/plugins/site/plugin.php" "site plugin"
write_file "$rollback_target/wp-config.php" "site config"

fake_bin="$TMP_DIR/bin"
real_mv="$(command -v mv)"
mkdir -p -- "$fake_bin"
{
  printf '#!/bin/sh\n'
  printf 'case "$1" in\n'
  printf '  */.yy-core-next.*/wp-settings.php) exit 1 ;;\n'
  printf 'esac\n'
  printf 'exec "%s" "$@"\n' "$real_mv"
} >"$fake_bin/mv"
chmod +x "$fake_bin/mv"

if PATH="$fake_bin:$PATH" \
  YY_CORE_SOURCE="$rollback_source" \
  YY_CORE_TARGET="$rollback_target" \
  YY_DOCKER_ENTRYPOINT=true \
    "$ENTRYPOINT"; then
  fail "a failed core activation was reported as successful"
fi

[[ "$(cat "$rollback_target/wp-settings.php")" == "old rollback core" ]] \
  || fail "rollback did not restore wp-settings.php"
[[ "$(cat "$rollback_target/wp-admin/admin.php")" == "old rollback admin" ]] \
  || fail "rollback did not restore wp-admin"
[[ "$(cat "$rollback_target/wp-includes/version.php")" == "\$wp_version = '7.1.4';" ]] \
  || fail "rollback did not restore wp-includes"
[[ "$(cat "$rollback_target/wp-content/plugins/site/plugin.php")" == "site plugin" ]] \
  || fail "rollback modified site plugins"
if find "$rollback_target" -maxdepth 1 -name '.yy-core-*' -print -quit | grep -q .; then
  fail "rollback left a core staging directory behind"
fi

printf 'Entrypoint contract passed.\n'
