#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

usage() {
  cat <<'EOF'
Usage: deploy.sh [--check]

Validates production settings and deploys the Docker Compose stack.
The first deployment obtains a Let's Encrypt certificate over HTTP-01.
EOF
}

check_only=false
case "${1:-}" in
  "")
    ;;
  --check)
    check_only=true
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

yy_load_env
yy_validate_site_env
yy_require_vars CERTBOT_EMAIL
yy_reject_placeholder DB_PASSWORD
yy_reject_placeholder DB_ROOT_PASSWORD
yy_reject_placeholder CERTBOT_EMAIL

if [[ "$check_only" == "true" ]]; then
  check_config="$(mktemp)"
  trap 'rm -f "$check_config"' EXIT
  yy_render_tls "$check_config"
  yy_log "Configuration check passed for https://$DOMAIN"
  exit 0
fi

yy_require_docker
yy_compose config >/dev/null
yy_log "Building the pinned WordPress image"
yy_compose build wordpress

if ! yy_certificate_exists; then
  yy_log "No certificate found; starting the HTTP stack for ACME validation"
  yy_compose_base up -d db wordpress nginx
  yy_wait_for_http || yy_die "The HTTP health check did not become ready"

  yy_log "Requesting the first certificate for $DOMAIN"
  yy_compose --profile tools run --rm certbot certonly \
    --webroot \
    --webroot-path=/var/www/certbot \
    --email "$CERTBOT_EMAIL" \
    --agree-tos \
    --no-eff-email \
    --non-interactive \
    --domains "$DOMAIN"

  yy_certificate_exists || yy_die "Certbot completed without producing a certificate"
fi

yy_render_tls
yy_compose config >/dev/null
yy_log "Validating the generated TLS configuration"
yy_compose run --rm --no-deps nginx nginx -t
yy_log "Starting or recreating the production containers"
yy_compose up -d --remove-orphans
yy_compose exec -T nginx nginx -s reload
yy_compose ps

yy_log "Deployment complete: https://$DOMAIN"
yy_log "Next: bash scripts/init-site.sh"
