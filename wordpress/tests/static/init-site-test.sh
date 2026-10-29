#!/usr/bin/env bash

set -Eeuo pipefail

TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
INIT_SCRIPT="$TEST_DIR/../../scripts/init-site.sh"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT

fail() {
  printf 'init-site test failed: %s\n' "$*" >&2
  exit 1
}

fake_bin="$TMP_DIR/bin"
mkdir -p -- "$fake_bin"

cat >"$fake_bin/docker" <<'EOF'
#!/usr/bin/env bash

set -Eeuo pipefail

[[ "${1:-}" == "compose" ]] || exit 64
shift
[[ "${1:-}" == "version" ]] && exit 0

while [[ "${1:-}" == "-f" ]]; do
  shift 2
done

if [[ "${1:-}" == "--profile" ]]; then
  shift 2
fi

case "${1:-}" in
  up)
    exit 0
    ;;
  run)
    shift
    while [[ $# -gt 0 && "$1" != "wpcli" ]]; do
      shift
    done
    [[ "${1:-}" == "wpcli" ]] || exit 65
    shift
    [[ "${1:-}" == "wp" ]] || exit 66
    shift
    printf 'wp %s\n' "$*" >>"$FAKE_WP_LOG"

    case "${1:-}" in
      db)
        [[ "${2:-}" == "check" ]] || exit 67
        exit 0
        ;;
      core)
        case "${2:-}" in
          is-installed)
            [[ "${FAKE_INSTALLED:-0}" == "1" ]]
            exit
            ;;
          install)
            exit 0
            ;;
          *)
            exit 68
            ;;
        esac
        ;;
      post)
        [[ "${2:-}" == "list" ]] || exit 69
        exit 0
        ;;
      plugin|theme|language|option|rewrite|user)
        exit 0
        ;;
      yy)
        [[ "${2:-}" == "bootstrap" ]] || exit 70
        exit 0
        ;;
      *)
        exit 71
        ;;
    esac
    ;;
  *)
    exit 72
    ;;
esac
EOF
chmod +x "$fake_bin/docker"

env_file="$TMP_DIR/.env"
cat >"$env_file" <<'EOF'
SITE_URL=https://example.test
DOMAIN=example.test
DB_NAME=wordpress
DB_USER=wordpress
DB_PASSWORD=test-db-password
DB_ROOT_PASSWORD=test-root-password
ADMIN_USER=admin
ADMIN_PASSWORD=test-admin-password
ADMIN_EMAIL=admin@example.test
EOF

run_init() {
  local installed="$1"
  local log_file="$2"
  shift 2

  : >"$log_file"
  PATH="$fake_bin:$PATH" \
  YY_ENV_FILE="$env_file" \
  FAKE_INSTALLED="$installed" \
  FAKE_WP_LOG="$log_file" \
    "$INIT_SCRIPT" "$@"
}

fresh_log="$TMP_DIR/fresh.log"
run_init 0 "$fresh_log"
grep -Fxq 'wp yy bootstrap --configure-wp-options' "$fresh_log" \
  || fail "a fresh installation did not configure WordPress core options"
grep -Fxq 'wp option update blog_public 1' "$fresh_log" \
  || fail "a fresh installation did not enable search indexing"

rerun_log="$TMP_DIR/rerun.log"
run_init 1 "$rerun_log"
grep -Fxq 'wp yy bootstrap' "$rerun_log" \
  || fail "a normal rerun did not preserve existing WordPress core options"
if grep -Fq -- '--configure-wp-options' "$rerun_log"; then
  fail "a normal rerun was allowed to overwrite WordPress core options"
fi
if grep -Fxq 'wp option update blog_public 1' "$rerun_log"; then
  fail "a normal rerun was allowed to re-enable search indexing"
fi

overwrite_log="$TMP_DIR/overwrite.log"
run_init 1 "$overwrite_log" --overwrite
grep -Fxq 'wp yy bootstrap --overwrite --configure-wp-options' "$overwrite_log" \
  || fail "explicit --overwrite did not enable a full configured bootstrap"
grep -Fxq 'wp option update blog_public 1' "$overwrite_log" \
  || fail "explicit --overwrite did not enable search indexing"

printf 'Init site behavior contract passed.\n'
