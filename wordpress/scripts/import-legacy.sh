#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

usage() {
  cat <<'EOF'
Usage: import-legacy.sh --articles <articles.json> [--uploads <directory>]

Imports legacy articles through the wp yy import-legacy command. By default,
the uploads directory is resolved as <articles-directory>/uploads.
EOF
}

articles_file=""
uploads_dir=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --articles)
      articles_file="${2:-}"
      shift 2
      ;;
    --uploads)
      uploads_dir="${2:-}"
      shift 2
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

[[ -n "$articles_file" ]] || {
  usage >&2
  exit 2
}
[[ -f "$articles_file" ]] || yy_die "Articles file does not exist: $articles_file"

articles_file="$(cd -- "$(dirname -- "$articles_file")" >/dev/null 2>&1 && pwd)/$(basename -- "$articles_file")"
if [[ -z "$uploads_dir" ]]; then
  uploads_dir="$(dirname -- "$articles_file")/uploads"
fi
[[ -d "$uploads_dir" ]] || yy_die "Legacy uploads directory does not exist: $uploads_dir"
uploads_dir="$(cd -- "$uploads_dir" >/dev/null 2>&1 && pwd)"

yy_load_env
yy_validate_site_env
yy_require_docker

yy_log "Importing legacy articles from $articles_file"
yy_compose --profile tools run --rm -T \
  --volume "$articles_file:/import/articles.json:ro" \
  --volume "$uploads_dir:/import/uploads:ro" \
  wpcli wp yy import-legacy \
  --articles=/import/articles.json \
  --uploads=/import/uploads
