#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

usage() {
  cat <<'EOF'
Usage: setup-publisher-user.sh [--rotate]

Creates or reuses PUBLISHER_USER, ensures the yy_publisher role, and prints
the one-time WordPress Application Password used by the external tool.
Use --rotate to revoke existing application passwords before creating one.
EOF
}

rotate=false
case "${1:-}" in
  "")
    ;;
  --rotate)
    rotate=true
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
yy_require_docker
yy_require_vars PUBLISHER_USER PUBLISHER_EMAIL NEWS_CATEGORY_SLUG
yy_reject_placeholder PUBLISHER_EMAIL

yy_wp plugin is-active wuhanyingyuan-core >/dev/null 2>&1 \
  || yy_die "Run scripts/init-site.sh before creating the publisher account"

category_id="$(yy_wp term get category "$NEWS_CATEGORY_SLUG" --by=slug --field=term_id 2>/dev/null || true)"
[[ "$category_id" =~ ^[0-9]+$ ]] \
  || yy_die "Could not resolve category term ID for $NEWS_CATEGORY_SLUG"

publisher_exists=false
if yy_wp user get "$PUBLISHER_USER" --field=ID >/dev/null 2>&1; then
  publisher_exists=true
  app_count="$(yy_wp user application-password list "$PUBLISHER_USER" --format=count 2>/dev/null || printf '0')"
  if [[ "$app_count" != "0" && "$rotate" != "true" ]]; then
    yy_die "Publisher already has an Application Password. Rerun with --rotate to revoke it and issue a new one."
  fi
fi

if [[ "$publisher_exists" == "true" ]]; then
  yy_wp user set-role "$PUBLISHER_USER" yy_publisher >/dev/null
  yy_wp user update "$PUBLISHER_USER" --user_email="$PUBLISHER_EMAIL" >/dev/null
  yy_log "Reusing publisher account: $PUBLISHER_USER"
else
  generated_password="$(yy_wp eval 'echo wp_generate_password(48, true, true);')"
  yy_wp user create "$PUBLISHER_USER" "$PUBLISHER_EMAIL" \
    --role=yy_publisher \
    --user_pass="$generated_password" \
    --porcelain >/dev/null
  unset generated_password
  yy_log "Created publisher account: $PUBLISHER_USER"
fi

if [[ "$rotate" == "true" && "$publisher_exists" == "true" ]]; then
  yy_wp user application-password delete "$PUBLISHER_USER" --all >/dev/null
fi

app_name="${PUBLISHER_APP_NAME:-新闻发布工具}"
app_password="$(yy_wp user application-password create "$PUBLISHER_USER" "$app_name" --porcelain)"
app_password="${app_password//$'\r'/}"
[[ -n "$app_password" ]] || yy_die "WordPress did not return an Application Password"

printf 'SITE_URL=%s\n' "$SITE_URL"
printf 'API_KEY=%s\n' "$PUBLISHER_USER"
printf 'API_PASS=%s\n' "$app_password"
printf 'NEWS_CATEGORY_ID=%s\n' "$category_id"
printf 'AUTH_MODE=WordPress Application Password (HTTP Basic)\n'
