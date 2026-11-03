#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

yy_load_env
yy_validate_site_env
yy_validate_meituan_env
yy_require_docker
yy_all_certificates_exist \
  || yy_die "Certificates do not exist for both $DOMAIN and $MEITUAN_DOMAIN"

yy_compose --profile tools run --rm certbot renew \
  --webroot \
  --webroot-path=/var/www/certbot \
  --quiet

yy_compose exec -T nginx nginx -t
yy_compose exec -T nginx nginx -s reload
yy_log "Certificate renewal check completed; Nginx reloaded"
