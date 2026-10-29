import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..', '..');
const read = (file) => readFileSync(resolve(root, file), 'utf8');

const requiredFiles = [
  '.env.example',
  '.gitignore',
  'docker-compose.yml',
  'docker-compose.prod.yml',
  'docker/nginx/default.conf',
  'docker/nginx/php-fpm.conf',
  'docker/nginx/security-headers.conf',
  'docker/nginx/tls.conf.template',
  'docker/wordpress/yy-entrypoint.sh',
  'docker/wordpress/yy-uploads-switch.sh',
  'scripts/common.sh',
  'scripts/deploy.sh',
  'scripts/init-site.sh',
  'scripts/setup-publisher-user.sh',
  'scripts/import-legacy.sh',
  'scripts/backup.sh',
  'scripts/restore.sh',
  'scripts/renew-cert.sh',
  'docs/deploy.md',
  'docs/publisher-integration.md',
  'tests/php/content-model-test.php',
  'tests/static/entrypoint-test.sh',
  'tests/static/init-site-test.sh',
  'tests/static/setup-publisher-user-test.sh',
  'tests/static/backup-test.sh',
  'tests/static/restore-test.sh',
  'tests/static/uploads-switch-test.sh',
];

for (const file of requiredFiles) {
  assert.ok(existsSync(resolve(root, file)), `Missing deployment file: ${file}`);
}

const baseCompose = read('docker-compose.yml');
const prodCompose = read('docker-compose.prod.yml');
const defaultNginx = read('docker/nginx/default.conf');
const phpFpm = read('docker/nginx/php-fpm.conf');
const tlsTemplate = read('docker/nginx/tls.conf.template');
const gitignore = read('.gitignore');
const dockerfile = read('docker/wordpress/Dockerfile');
const coreEntrypoint = read('docker/wordpress/yy-entrypoint.sh');
const uploadsSwitch = read('docker/wordpress/yy-uploads-switch.sh');
const bootstrap = read('wp-content/plugins/wuhanyingyuan-core/inc/bootstrap.php');
const contentTypes = read('wp-content/plugins/wuhanyingyuan-core/inc/content-types.php');
const roles = read('wp-content/plugins/wuhanyingyuan-core/inc/publisher-role.php');
const plugin = read('wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php');
const deploy = read('scripts/deploy.sh');
const common = read('scripts/common.sh');
const initSite = read('scripts/init-site.sh');
const publisherScript = read('scripts/setup-publisher-user.sh');
const backup = read('scripts/backup.sh');
const restore = read('scripts/restore.sh');
const playwrightConfig = read('tests/playwright.config.ts');
const metaFields = read('wp-content/plugins/wuhanyingyuan-core/inc/meta-fields.php');
const contentModelTest = read('tests/php/content-model-test.php');

const themeMount = /\.\/wp-content\/themes\/wuhanyingyuan:\/var\/www\/html\/wp-content\/themes\/wuhanyingyuan:ro/g;
const pluginMount = /\.\/wp-content\/plugins\/wuhanyingyuan-core:\/var\/www\/html\/wp-content\/plugins\/wuhanyingyuan-core:ro/g;
assert.equal(baseCompose.match(themeMount)?.length, 3, 'Theme source must be mounted into WordPress, Nginx, and WP-CLI');
assert.equal(baseCompose.match(pluginMount)?.length, 3, 'Plugin source must be mounted into WordPress, Nginx, and WP-CLI');
assert.match(
  baseCompose,
  /\.\/tests:\/var\/www\/html\/tests:ro/,
  'PHP tests must be mounted into WP-CLI so eval-file can access them'
);
assert.match(prodCompose, /tls\.generated\.conf/);
assert.doesNotMatch(prodCompose, /tls\.conf:\/etc\/nginx/);
assert.match(gitignore, /^docker\/nginx\/tls\.generated\.conf$/m);
assert.match(baseCompose, /image: mariadb:11\.4\.13/);
assert.match(baseCompose, /image: nginx:1\.30\.6-alpine/);
assert.match(baseCompose, /image: wordpress:cli-2\.12\.0-php8\.3/);
const wpcliService = baseCompose.match(
  /^  wpcli:\r?\n([\s\S]*?)(?=^  \S|^networks:)/m
)?.[1];
assert.ok(wpcliService, 'WP-CLI service definition must be present');
assert.doesNotMatch(
  wpcliService,
  /^\s+user:/m,
  'WP-CLI must inherit the official image www-data user instead of overriding its UID'
);
assert.match(prodCompose, /image: certbot\/certbot:v5\.8\.0/);
assert.match(
  baseCompose,
  /x-logging: &default-logging[\s\S]*max-size: "10m"[\s\S]*max-file: "5"/,
  'Production containers must rotate Docker JSON logs'
);
assert.equal(
  (baseCompose.match(/logging: \*default-logging/g) || []).length,
  4,
  'All long-running Compose services must use the shared log rotation policy'
);
assert.match(
  dockerfile,
  /^FROM wordpress:7\.1\.4-php8\.3-fpm-alpine$/m,
  'WordPress core image must track the current supported release'
);
assert.match(dockerfile, /COPY docker\/wordpress\/yy-entrypoint\.sh \/usr\/local\/bin\/wuhanyingyuan-entrypoint/);
assert.match(
  dockerfile,
  /COPY docker\/wordpress\/yy-uploads-switch\.sh \/usr\/local\/bin\/yy-uploads-switch/,
  'The uploads switch helper must be installed in the WordPress image'
);
assert.match(dockerfile, /ENTRYPOINT \["wuhanyingyuan-entrypoint"\]/);
assert.match(
  coreEntrypoint,
  /core_source="\$\{YY_CORE_SOURCE:-\/usr\/src\/wordpress\}"/,
  'WordPress entrypoint must synchronize image core into persistent storage'
);
assert.match(
  coreEntrypoint,
  /wp-settings\.php wp-load\.php wp-admin\/admin\.php wp-includes\/version\.php/,
  'WordPress entrypoint must reject incomplete image core before changing persistent storage'
);
assert.match(
  coreEntrypoint,
  /core_stage=/,
  'WordPress core must be copied to a staging directory before activation'
);
assert.match(
  coreEntrypoint,
  /! core_matches "\$core_source" "\$core_target"/,
  'A same-version but damaged core must be repaired from the immutable image'
);
assert.match(
  coreEntrypoint,
  /cmp -s "\$source_dir\/\$relative" "\$target_dir\/\$relative"/,
  'Core comparison must verify file contents, not only the WordPress version'
);
assert.match(
  coreEntrypoint,
  /wp-content\|wp-config\.php\|\.htaccess\|robots\.txt\|favicon\.ico\|\.user\.ini\|\.well-known/,
  'Core synchronization must preserve user-managed root files'
);
assert.match(coreEntrypoint, /exec "\$entrypoint" "\$@"/);

assert.match(phpFpm, /fastcgi_param HTTP_AUTHORIZATION \$http_authorization;/);
assert.match(tlsTemplate, /listen 443 ssl;/);
assert.match(tlsTemplate, /location \^~ \/\.well-known\/acme-challenge\//);
assert.match(tlsTemplate, /\$\{DOMAIN\}/);
for (const [name, config] of [
  ['HTTP Nginx config', defaultNginx],
  ['TLS Nginx config', tlsTemplate],
]) {
  assert.ok(
    config.indexOf('location ~ /\\.(?!well-known)')
      < config.indexOf('location ~* \\.(?:css|js|jpg|jpeg|png|gif|webp|avif|svg|ico|woff2?)$'),
    `${name} must reject hidden paths before static file matching`
  );
  assert.ok(
    config.indexOf('location ~ /\\.(?!well-known)')
      < config.indexOf('location ~ \\.php$'),
    `${name} must reject hidden PHP paths before forwarding requests to PHP-FPM`
  );
}
assert.doesNotMatch(defaultNginx, /add_header Cache-Control/);
assert.doesNotMatch(tlsTemplate, /add_header Cache-Control/);
assert.match(deploy, /certonly/);
assert.match(deploy, /yy_render_tls/);
assert.match(deploy, /yy_compose build wordpress/);
assert.match(
  deploy,
  /yy_compose run --rm --no-deps nginx nginx -t/,
  'TLS configuration must be validated before recreating the public Nginx service'
);
assert.match(deploy, /yy_compose exec -T nginx nginx -s reload/);
assert.ok(
  deploy.indexOf('yy_compose build wordpress') < deploy.indexOf('yy_compose_base up'),
  'The WordPress image must be built before the first stack starts'
);
assert.match(
  common,
  /live\/\$DOMAIN\/fullchain\.pem[\s\S]*live\/\$DOMAIN\/privkey\.pem/,
  'Certificate readiness must require both the chain and private key'
);
assert.match(
  common,
  /YY_OPERATION_LOCK_FILE="\$\{YY_OPERATION_LOCK_FILE:-\$YY_ROOT\/\.yy-operation\.lock\}"/,
  'Backup and restore must share one configurable host-level operation lock'
);
assert.match(
  common,
  /yy_acquire_operation_lock\(\)[\s\S]*flock -n 9/,
  'The shared operation lock must use a non-blocking flock'
);
assert.match(
  common,
  /yy_release_operation_lock\(\)[\s\S]*flock -u 9/,
  'The shared operation lock must support explicit release'
);
assert.match(
  backup,
  /trap cleanup EXIT[\s\S]*yy_acquire_operation_lock[\s\S]*running_services=/,
  'Backup must acquire the shared lock before inspecting and dumping services'
);
assert.match(
  backup,
  /cleanup\(\) \{[\s\S]*yy_release_operation_lock/,
  'Every backup exit path must release the shared operation lock'
);
assert.match(
  backup,
  /running_services="\$\(yy_compose ps --services --status running\)"/,
  'Backup preflight must inspect a captured service list'
);
assert.match(
  backup,
  /db_temp="\$backup_dir\/\.yy-db-\$stamp\.sql\.gz\.partial\.\$\$"/,
  'Database dumps must be staged under a name that cannot be selected by --latest'
);
assert.match(
  backup,
  /gzip -c >"\$db_temp"[\s\S]*tar -C \/var\/www\/html\/wp-content -czf - uploads >"\$uploads_temp"[\s\S]*gzip -t "\$db_temp"[\s\S]*tar -tzf "\$uploads_temp"/,
  'Backup archives must be written and validated before publication'
);
assert.match(
  backup,
  /mv -f "\$uploads_temp" "\$uploads_file"[\s\S]*mv -f "\$db_temp" "\$db_file"/,
  'The database dump must be published after its matching uploads archive'
);
assert.match(
  backup,
  /grep -Fxq 'db' <<<"\$running_services"/,
  'Database preflight must not short-circuit a pipefail pipeline'
);
assert.match(
  backup,
  /grep -Fxq 'wordpress' <<<"\$running_services"/,
  'WordPress preflight must not short-circuit a pipefail pipeline'
);
assert.match(restore, /exec -T --user root wordpress \\\s+chown -R www-data:www-data/);
assert.match(restore, /find "\$backup_dir" -maxdepth 1 -type f -name 'yy-db-\*\.sql\.gz' -print0/);
assert.match(
  restore,
  /yy_validate_uploads_archive\(\)[\s\S]*Uploads backup contains an absolute path[\s\S]*Uploads backup contains an entry outside uploads\/[\s\S]*Uploads backup contains a parent-directory path[\s\S]*contains a link or special file/,
  'Restore must reject unsafe upload archive paths and entry types before deployment'
);
assert.match(
  restore,
  /yy_validate_uploads_archive "\$uploads_file"/,
  'Restore must invoke uploads archive validation before dry-run or mutation'
);
assert.match(
  restore,
  /restore_stage="\/var\/www\/html\/wp-content\/uploads\/\.yy-restore-\$restore_id"/,
  'Uploads must be restored through a staging directory'
);
assert.match(restore, /tar -xzf - -C "\$restore_stage"/);
assert.match(restore, /test -d "\$restore_stage\/uploads"/);
assert.match(
  restore,
  /uploads_rollback="\$\(yy_compose exec -T wordpress yy-uploads-switch activate "\$restore_stage"\)"/,
  'Restore must activate staged uploads through the rollback-capable helper'
);
assert.match(
  restore,
  /yy_compose exec -T wordpress yy-uploads-switch rollback "\$uploads_rollback"/,
  'Restore failures must roll back the uploads switch'
);
assert.match(
  restore,
  /yy_compose exec -T wordpress yy-uploads-switch commit "\$uploads_rollback"/,
  'A completed restore must commit the uploads switch'
);
assert.match(
  restore,
  /safety_output="\$\(YY_OPERATION_LOCK_HELD=1 bash "\$SCRIPT_DIR\/backup\.sh"\)"/,
  'Restore must retain the safety backup and pass the held operation lock into it'
);
assert.match(
  restore,
  /trap cleanup EXIT[\s\S]*yy_acquire_operation_lock[\s\S]*maintenance_active=true/,
  'Restore must acquire the operation lock before entering maintenance mode'
);
assert.match(
  restore,
  /cleanup\(\) \{[\s\S]*yy_release_operation_lock/,
  'Every restore exit path must release the shared operation lock'
);
assert.match(
  restore,
  /if restore_database "\$safety_database_file"; then/,
  'A failed database restore must attempt the safety backup rollback'
);
assert.ok(
  restore.indexOf('tar -xzf - -C "$restore_stage"') < restore.indexOf('yy-uploads-switch activate'),
  'Current uploads must remain in place until the backup archive has extracted successfully'
);
assert.match(
  restore,
  /yy_compose exec -T wordpress tar -xzf - -C "\$restore_stage" <"\$uploads_file"/,
  'The uploaded tar.gz archive must be streamed without a second gzip decompression'
);
assert.doesNotMatch(
  restore,
  /gzip -dc "\$uploads_file"\s*\|/,
  'Uploads restore must not feed a decompressed stream to tar -z'
);
assert.match(
  uploadsSwitch,
  /mktemp -d "\$uploads\/\.yy-uploads-rollback\.XXXXXX"/,
  'Uploads activation must retain a same-filesystem rollback directory'
);
assert.match(
  uploadsSwitch,
  /move_entries "\$uploads" "\$rollback_dir\/files" "\$rollback_dir" "\$stage"/,
  'Existing uploads must be moved aside before staged files are activated'
);
assert.match(
  uploadsSwitch,
  /trap on_exit EXIT/,
  'A failed uploads activation must have an automatic rollback path'
);
assert.match(initSite, /yy_require_vars EDITOR_EMAIL\s+yy_reject_placeholder EDITOR_EMAIL/);
assert.match(playwrightConfig, /name: 'tablet-chromium'/);
assert.match(playwrightConfig, /viewport: \{ width: 768, height: 1024 \}/);

assert.match(bootstrap, /\(!\$existing_post_id \|\| \$overwrite\)/, 'News seeding must preserve existing editorial fields');
assert.match(bootstrap, /_yy_seed_menu/);
assert.match(
  bootstrap,
  /\$wp_rewrite->set_permalink_structure\('\/news\/%postname%\/'\);[\s\S]*\$wp_rewrite->set_category_base\('news\/category'\);[\s\S]*flush_rewrite_rules\(\);/,
  'Bootstrap must synchronize and flush the active WP_Rewrite configuration'
);
assert.match(contentTypes, /capability_type' => \['yy_homepage_item', 'yy_homepage_items'\]/);
assert.match(roles, /yy_homepage_item_capabilities/);
assert.doesNotMatch(roles, /manage_options|install_plugins|switch_themes/);
assert.match(plugin, /yy_core_sync_roles\(true\)/);
assert.match(plugin, /1\.0\.1/);
assert.match(
  metaFields,
  /'auth_callback'\s*=>\s*static function \(\$allowed, \$meta_key, \$object_id, \$user_id, \$cap, \$caps\)\s*\{/,
  'Registered post meta auth callbacks must declare the complete WordPress signature'
);
assert.match(
  metaFields,
  /current_user_can\('edit_post', \(int\) \$object_id\)/,
  'Registered post meta auth callbacks must authorize the actual object ID'
);
assert.match(
  metaFields,
  /in_array\(\$field\['type'\], \['integer', 'media'\], true\)/,
  'Attachment IDs and integer fields must share the integer detection rule'
);
assert.match(
  metaFields,
  /'type'\s*=>\s*\$is_integer\s*\?\s*'integer'\s*:\s*'string'/,
  'Attachment ID meta must use an integer REST schema'
);
for (const postType of [
  'yy_platform',
  'yy_service',
  'yy_advantage',
  'yy_process',
  'yy_case',
  'yy_faq',
  'yy_carousel_image',
  'yy_stat',
]) {
  assert.match(
    contentModelTest,
    new RegExp(`['"]${postType}['"]`),
    `Content model test must cover ${postType}`
  );
}
assert.match(contentModelTest, /['"]post['"]/);
assert.match(contentModelTest, /_yy_order/);
assert.match(contentModelTest, /yy_get_site_setting/);
assert.match(contentModelTest, /yy_get_home_setting/);
assert.match(contentModelTest, /yy_import_legacy_article/, 'Legacy import runtime coverage is required');
assert.match(contentModelTest, /<script>alert/, 'Legacy import fixture must verify dangerous script removal');
assert.match(contentModelTest, /missing-legacy/, 'Legacy import runtime coverage must include a missing image');
assert.match(contentModelTest, /warnings/, 'Legacy import runtime coverage must verify warning reporting');

assert.match(publisherScript, /application-password create/);
assert.match(publisherScript, /--rotate/);
assert.match(publisherScript, /--all/);
assert.match(publisherScript, /NEWS_CATEGORY_ID/);
assert.doesNotMatch(publisherScript, /ADMIN_PASSWORD/);

console.log('Deployment contract passed.');
