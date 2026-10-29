#!/bin/sh
set -eu

core_source="${YY_CORE_SOURCE:-/usr/src/wordpress}"
core_target="${YY_CORE_TARGET:-/var/www/html}"
entrypoint="${YY_DOCKER_ENTRYPOINT:-docker-entrypoint.sh}"
core_stage=""
core_backup=""
core_files=""
core_target_files=""

cleanup() {
  [ -z "$core_stage" ] || rm -rf "$core_stage"
  [ -z "$core_backup" ] || rm -rf "$core_backup"
  [ -z "$core_files" ] || rm -f "$core_files"
  [ -z "$core_target_files" ] || rm -f "$core_target_files"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

core_version() {
  sed -n "s/^\\\$wp_version = '\([^']*\)';.*/\1/p" "$1/wp-includes/version.php" \
    | sed -n '1p'
}

core_matches() {
  source_dir="$1"
  target_dir="$2"
  matched=true

  core_files="$(mktemp "$target_dir/.yy-core-files.XXXXXX")"
  core_target_files="$(mktemp "$target_dir/.yy-core-target-files.XXXXXX")"
  if ! (
    cd "$source_dir"
    find . -type f \
      ! -path './wp-content/*' \
      ! -path './wp-config.php' \
      ! -path './.htaccess' \
      ! -path './robots.txt' \
      ! -path './favicon.ico' \
      ! -path './.user.ini' \
      ! -path './.well-known/*' \
      | sort
  ) >"$core_files"; then
    rm -f "$core_files"
    rm -f "$core_target_files"
    core_files=""
    core_target_files=""
    return 1
  fi
  if ! (
    cd "$target_dir"
    find . -type f \
      ! -path './wp-content/*' \
      ! -path './wp-config.php' \
      ! -path './.htaccess' \
      ! -path './robots.txt' \
      ! -path './favicon.ico' \
      ! -path './.user.ini' \
      ! -path './.well-known/*' \
      ! -path './.yy-core-*' \
      | sort
  ) >"$core_target_files"; then
    rm -f "$core_files"
    rm -f "$core_target_files"
    core_files=""
    core_target_files=""
    return 1
  fi

  if ! cmp -s "$core_files" "$core_target_files"; then
    matched=false
  else
    while IFS= read -r relative; do
      [ -n "$relative" ] || continue
      if [ ! -f "$target_dir/$relative" ] \
        || ! cmp -s "$source_dir/$relative" "$target_dir/$relative"; then
        matched=false
        break
      fi
    done <"$core_files"
  fi

  rm -f "$core_files"
  rm -f "$core_target_files"
  core_files=""
  core_target_files=""
  [ "$matched" = "true" ]
}

rollback_core() {
  name=""

  while IFS= read -r name; do
    rm -rf "$core_target/$name"
    if [ -e "$core_backup/$name" ] || [ -L "$core_backup/$name" ]; then
      cp -a "$core_backup/$name" "$core_target/$name"
    fi
  done <"$core_stage/.names"
}

if [ -d "$core_source" ]; then
  for required in wp-settings.php wp-load.php wp-admin/admin.php wp-includes/version.php; do
    if [ ! -f "$core_source/$required" ]; then
      printf '[wuhanyingyuan] ERROR: incomplete WordPress core image; missing %s\n' \
        "$required" >&2
      exit 1
    fi
  done
fi

# The official image only seeds an empty data volume. Replace a pinned image's
# core through a staged update when the persistent volume is from another
# release or no longer matches the immutable image files.
if [ -d "$core_source" ] \
  && [ -f "$core_target/wp-config.php" ] \
  && { [ ! -f "$core_target/wp-includes/version.php" ] \
    || [ "$(core_version "$core_source")" != "$(core_version "$core_target")" ] \
    || ! core_matches "$core_source" "$core_target"; }; then
  core_stage="$(mktemp -d "$core_target/.yy-core-next.XXXXXX")"
  core_backup="$(mktemp -d "$core_target/.yy-core-previous.XXXXXX")"
  : >"$core_stage/.names"

  for source in "$core_source"/* "$core_source"/.[!.]* "$core_source"/..?*; do
    [ -e "$source" ] || [ -L "$source" ] || continue
    name="${source##*/}"
    case "$name" in
      wp-content|wp-config.php|.htaccess|robots.txt|favicon.ico|.user.ini|.well-known)
        continue
        ;;
    esac
    printf '%s\n' "$name" >>"$core_stage/.names"
    cp -a "$source" "$core_stage/$name"
  done

  for stale in "$core_target"/* "$core_target"/.[!.]* "$core_target"/..?*; do
    if [ ! -e "$stale" ] && [ ! -L "$stale" ]; then
      continue
    fi
    name="${stale##*/}"
    case "$name" in
      wp-content|wp-config.php|.htaccess|robots.txt|favicon.ico|.user.ini|.well-known)
        continue
        ;;
      .yy-core-next.*|.yy-core-previous.*|.yy-core-files.*)
        continue
        ;;
    esac
    grep -Fxq "$name" "$core_stage/.names" \
      || printf '%s\n' "$name" >>"$core_stage/.names"
  done

  for required in wp-settings.php wp-load.php wp-admin/admin.php wp-includes/version.php; do
    if [ ! -f "$core_stage/$required" ]; then
      printf '[wuhanyingyuan] ERROR: staged WordPress core is incomplete; missing %s\n' \
        "$required" >&2
      exit 1
    fi
  done

  while IFS= read -r name; do
    if [ -e "$core_target/$name" ] || [ -L "$core_target/$name" ]; then
      cp -a "$core_target/$name" "$core_backup/$name"
    fi
  done <"$core_stage/.names"

  while IFS= read -r name; do
    rm -rf "$core_target/$name"
    if [ ! -e "$core_stage/$name" ] && [ ! -L "$core_stage/$name" ]; then
      continue
    fi
    if ! mv "$core_stage/$name" "$core_target/$name"; then
      printf '[wuhanyingyuan] ERROR: failed to activate WordPress core; rolling back\n' >&2
      rollback_core
      exit 1
    fi
  done <"$core_stage/.names"

  rm -rf "$core_stage" "$core_backup"
  core_stage=""
  core_backup=""
fi

exec "$entrypoint" "$@"
