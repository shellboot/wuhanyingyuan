#!/bin/sh

set -eu

uploads="${YY_UPLOADS_DIR:-/var/www/html/wp-content/uploads}"
mode="${1:-}"

die() {
  printf '[wuhanyingyuan] ERROR: %s\n' "$*" >&2
  exit 1
}

move_entries() {
  source_dir="$1"
  target_dir="$2"
  skip_one="${3:-}"
  skip_two="${4:-}"

  for entry in "$source_dir"/* "$source_dir"/.[!.]* "$source_dir"/..?*; do
    if [ ! -e "$entry" ] && [ ! -L "$entry" ]; then
      continue
    fi
    [ "$entry" != "$skip_one" ] || continue
    [ "$entry" != "$skip_two" ] || continue
    name="${entry##*/}"
    mv -- "$entry" "$target_dir/$name"
  done
}

remove_visible_entries() {
  source_dir="$1"
  skip_one="${2:-}"
  skip_two="${3:-}"

  for entry in "$source_dir"/* "$source_dir"/.[!.]* "$source_dir"/..?*; do
    if [ ! -e "$entry" ] && [ ! -L "$entry" ]; then
      continue
    fi
    [ "$entry" != "$skip_one" ] || continue
    [ "$entry" != "$skip_two" ] || continue
    rm -rf -- "$entry"
  done
}

validate_rollback_dir() {
  candidate="$1"
  case "$candidate" in
    "$uploads"/.yy-uploads-rollback.*) ;;
    *) die "Refusing to operate on an unexpected rollback directory: $candidate" ;;
  esac
  [ -d "$candidate/files" ] || die "Rollback data is missing: $candidate"
}

restore_rollback() {
  rollback_dir="$1"
  validate_rollback_dir "$rollback_dir"
  remove_visible_entries "$uploads" "$rollback_dir"
  move_entries "$rollback_dir/files" "$uploads"
  rmdir -- "$rollback_dir/files"
  rmdir -- "$rollback_dir"
}

case "$mode" in
  activate)
    stage="${2:-}"
    [ -d "$uploads" ] || die "Uploads directory does not exist: $uploads"
    [ -d "$stage/uploads" ] || die "Staged uploads directory does not exist: $stage/uploads"

    rollback_dir="$(mktemp -d "$uploads/.yy-uploads-rollback.XXXXXX")"
    mkdir -- "$rollback_dir/files"
    switch_active=true

    on_exit() {
      status=$?
      trap - EXIT HUP INT TERM
      if [ "$switch_active" = "true" ]; then
        printf '[wuhanyingyuan] ERROR: uploads activation failed; restoring previous files\n' >&2
        remove_visible_entries "$uploads" "$rollback_dir" "$stage"
        if [ -d "$rollback_dir/files" ]; then
          move_entries "$rollback_dir/files" "$uploads" || true
        fi
        rm -rf -- "$rollback_dir"
      fi
      exit "$status"
    }
    trap on_exit EXIT
    trap 'exit 1' HUP INT TERM

    move_entries "$uploads" "$rollback_dir/files" "$rollback_dir" "$stage"
    move_entries "$stage/uploads" "$uploads"
    rm -rf -- "$stage"

    switch_active=false
    trap - EXIT HUP INT TERM
    printf '%s\n' "$rollback_dir"
    ;;
  rollback)
    rollback_dir="${2:-}"
    [ -n "$rollback_dir" ] || die "rollback requires a rollback directory"
    restore_rollback "$rollback_dir"
    ;;
  commit)
    rollback_dir="${2:-}"
    [ -n "$rollback_dir" ] || die "commit requires a rollback directory"
    validate_rollback_dir "$rollback_dir"
    rm -rf -- "$rollback_dir"
    ;;
  *)
    die "Usage: yy-uploads-switch (activate <stage> | rollback <state> | commit <state>)"
    ;;
esac
