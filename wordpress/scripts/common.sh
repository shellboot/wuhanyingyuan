#!/usr/bin/env bash

set -Eeuo pipefail

YY_SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
YY_ROOT="$(cd -- "$YY_SCRIPT_DIR/.." >/dev/null 2>&1 && pwd)"
YY_ENV_FILE="${YY_ENV_FILE:-$YY_ROOT/.env}"
YY_BASE_COMPOSE="$YY_ROOT/docker-compose.yml"
YY_PROD_COMPOSE="$YY_ROOT/docker-compose.prod.yml"
YY_OPERATION_LOCK_FILE="${YY_OPERATION_LOCK_FILE:-$YY_ROOT/.yy-operation.lock}"

yy_operation_lock_owned=false

yy_log() {
  printf '[wuhanyingyuan] %s\n' "$*" >&2
}

yy_die() {
  printf '[wuhanyingyuan] ERROR: %s\n' "$*" >&2
  exit 1
}

yy_load_env() {
  [[ -f "$YY_ENV_FILE" ]] || yy_die "Missing environment file: $YY_ENV_FILE"

  set -a
  # shellcheck disable=SC1090
  source "$YY_ENV_FILE"
  set +a
}

yy_require_vars() {
  local name
  for name in "$@"; do
    [[ -n "${!name:-}" ]] || yy_die "Required environment variable is empty: $name"
  done
}

yy_reject_placeholder() {
  local name="$1"
  local value="${!name:-}"

  case "$value" in
    ""|replace-*|*@example.com)
      yy_die "Replace the placeholder value for $name in $YY_ENV_FILE"
      ;;
  esac
}

yy_validate_site_env() {
  yy_require_vars SITE_URL DOMAIN DB_NAME DB_USER DB_PASSWORD DB_ROOT_PASSWORD

  [[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] \
    || yy_die "DOMAIN is not a valid hostname: $DOMAIN"
  [[ "$SITE_URL" == "https://$DOMAIN" ]] \
    || yy_die "SITE_URL must be exactly https://$DOMAIN without a trailing slash"
}

yy_require_docker() {
  command -v docker >/dev/null 2>&1 || yy_die "Docker is not installed"
  docker compose version >/dev/null 2>&1 || yy_die "Docker Compose v2 is required"
}

yy_acquire_operation_lock() {
  local lock_dir

  if [[ "${YY_OPERATION_LOCK_HELD:-0}" == "1" ]]; then
    return 0
  fi

  command -v flock >/dev/null 2>&1 \
    || yy_die "flock is required to coordinate backup and restore operations"
  lock_dir="$(dirname -- "$YY_OPERATION_LOCK_FILE")"
  mkdir -p -- "$lock_dir"
  exec 9>"$YY_OPERATION_LOCK_FILE"
  if ! flock -n 9; then
    exec 9>&-
    yy_die "Another backup or restore operation is already running"
  fi
  yy_operation_lock_owned=true
}

yy_release_operation_lock() {
  if [[ "$yy_operation_lock_owned" != "true" ]]; then
    return 0
  fi

  flock -u 9 >/dev/null 2>&1 || true
  exec 9>&-
  yy_operation_lock_owned=false
}

yy_compose() {
  (
    cd "$YY_ROOT"
    docker compose -f "$YY_BASE_COMPOSE" -f "$YY_PROD_COMPOSE" "$@"
  )
}

yy_compose_base() {
  (
    cd "$YY_ROOT"
    docker compose -f "$YY_BASE_COMPOSE" "$@"
  )
}

yy_wp() {
  yy_compose --profile tools run --rm -T wpcli wp "$@"
}

yy_render_tls() {
  local target="${1:-$YY_ROOT/docker/nginx/tls.generated.conf}"
  local template="$YY_ROOT/docker/nginx/tls.conf.template"
  local temp_file

  [[ -f "$template" ]] || yy_die "Missing TLS template: $template"
  mkdir -p "$(dirname -- "$target")"
  temp_file="$(mktemp "${target}.tmp.XXXXXX")"
  sed "s|\${DOMAIN}|$DOMAIN|g" "$template" >"$temp_file"

  # shellcheck disable=SC2016 # Match the literal placeholder in the rendered file.
  if grep -Fq '${DOMAIN}' "$temp_file"; then
    rm -f "$temp_file"
    yy_die "TLS template still contains unresolved domain placeholders"
  fi

  mv -f "$temp_file" "$target"
}

yy_certificate_exists() {
  [[ -s "$YY_ROOT/certs/live/$DOMAIN/fullchain.pem" ]] \
    && [[ -s "$YY_ROOT/certs/live/$DOMAIN/privkey.pem" ]]
}

yy_wait_for_http() {
  local attempts="${DEPLOY_HEALTHCHECK_ATTEMPTS:-30}"
  local attempt

  for ((attempt = 1; attempt <= attempts; attempt++)); do
    if yy_compose_base exec -T nginx wget -q -T 2 -O /dev/null http://127.0.0.1/healthz; then
      return 0
    fi
    sleep 2
  done

  return 1
}
