#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

yy_load_env
yy_validate_site_env
yy_require_docker
yy_require_vars ADMIN_USER ADMIN_EMAIL

overwrite=false
case "${1:-}" in
  "")
    ;;
  --overwrite)
    overwrite=true
    ;;
  *)
    yy_die "Usage: init-site.sh [--overwrite]"
    ;;
esac

yy_log "Starting database and WordPress"
yy_compose up -d db wordpress

db_ready=false
for _ in {1..60}; do
  if yy_wp db check >/dev/null 2>&1; then
    db_ready=true
    break
  fi
  sleep 2
done
[[ "$db_ready" == "true" ]] || yy_die "WordPress could not connect to the database"

fresh_install=false
if yy_wp core is-installed >/dev/null 2>&1; then
  yy_log "WordPress is already installed"
else
  fresh_install=true
  yy_require_vars ADMIN_PASSWORD
  yy_reject_placeholder ADMIN_PASSWORD
  yy_reject_placeholder ADMIN_EMAIL
  yy_log "Installing WordPress for $SITE_URL"
  yy_wp core install \
    --url="$SITE_URL" \
    --title="盈圆" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASSWORD" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
fi

yy_wp plugin activate wuhanyingyuan-core >/dev/null
yy_wp theme activate wuhanyingyuan >/dev/null

if [[ "${INSTALL_WORDPRESS_LANGUAGE:-true}" == "true" ]]; then
  if ! yy_wp language core install zh_CN --activate >/dev/null 2>&1; then
    yy_log "WARNING: Could not install zh_CN language files; site content is still editable"
  fi
fi

if [[ "$fresh_install" == "true" ]]; then
  for slug in hello-world sample-page; do
    if [[ "$slug" == "hello-world" ]]; then
      post_type="post"
    else
      post_type="page"
    fi
    post_ids="$(yy_wp post list --post_type="$post_type" --name="$slug" --field=ID --format=ids 2>/dev/null || true)"
    for post_id in $post_ids; do
      yy_wp post delete "$post_id" --force >/dev/null
    done
  done
fi

bootstrap_args=(yy bootstrap)
if [[ "$overwrite" == "true" ]]; then
  bootstrap_args+=(--overwrite)
fi
if [[ "$fresh_install" == "true" || "$overwrite" == "true" ]]; then
  bootstrap_args+=(--configure-wp-options)
  yy_log "Bootstrapping editable site content and WordPress core options"
else
  yy_log "Bootstrapping missing editable content while preserving existing WordPress settings"
fi
yy_wp "${bootstrap_args[@]}"
if [[ "$fresh_install" == "true" || "$overwrite" == "true" ]]; then
  yy_wp option update blog_public 1 >/dev/null
fi
yy_wp rewrite flush --hard >/dev/null

if [[ -n "${EDITOR_USER:-}" && -n "${EDITOR_PASSWORD:-}" ]]; then
  yy_reject_placeholder EDITOR_PASSWORD
  if yy_wp user get "$EDITOR_USER" --field=ID >/dev/null 2>&1; then
    yy_wp user set-role "$EDITOR_USER" yy_content_editor >/dev/null
    yy_log "Content editor role verified for $EDITOR_USER"
  else
    yy_require_vars EDITOR_EMAIL
    yy_reject_placeholder EDITOR_EMAIL
    yy_wp user create "$EDITOR_USER" "$EDITOR_EMAIL" \
      --role=yy_content_editor \
      --user_pass="$EDITOR_PASSWORD" \
      --porcelain >/dev/null
    yy_log "Created content editor account: $EDITOR_USER"
  fi
fi

yy_log "Site initialization complete"
yy_log "Admin: $SITE_URL/wp-admin/"
yy_log "Next: bash scripts/setup-publisher-user.sh"
