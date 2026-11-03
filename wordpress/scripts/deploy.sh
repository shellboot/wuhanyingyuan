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
yy_validate_meituan_env
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
yy_validate_meituan_project
yy_compose config >/dev/null
yy_log "Building the pinned WordPress image"
yy_compose build wordpress

if ! yy_all_certificates_exist; then
  if ! yy_service_is_running nginx; then
    yy_log "Starting the HTTP stack for ACME validation"
    yy_compose_base up -d db wordpress nginx
  fi
  yy_wait_for_http || yy_die "The HTTP health check did not become ready"

  for cert_domain in "$DOMAIN" "$MEITUAN_DOMAIN"; do
    if ! yy_domain_certificate_exists "$cert_domain"; then
      yy_log "Requesting a certificate for $cert_domain"
      yy_compose --profile tools run --rm certbot certonly \
        --webroot \
        --webroot-path=/var/www/certbot \
        --email "$CERTBOT_EMAIL" \
        --agree-tos \
        --no-eff-email \
        --non-interactive \
        --cert-name "$cert_domain" \
        --domains "$cert_domain"

      yy_domain_certificate_exists "$cert_domain" \
        || yy_die "Certbot completed without producing a certificate for $cert_domain"
    fi
  done
fi

yy_render_tls
yy_compose config >/dev/null
yy_log "Validating the generated TLS configuration"
yy_compose run --rm --no-deps nginx nginx -t
yy_log "Starting or recreating the production containers"
yy_compose up -d --remove-orphans
yy_log "Recreating Nginx to attach the freshly generated TLS configuration"
yy_compose up -d --force-recreate nginx
yy_compose exec -T nginx nginx -s reload
yy_compose ps

yy_log "Deployment complete: https://$DOMAIN and https://$MEITUAN_DOMAIN"
yy_log "Next: bash scripts/init-site.sh"
