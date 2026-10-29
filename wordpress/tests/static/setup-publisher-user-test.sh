#!/usr/bin/env bash

set -Eeuo pipefail

TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
PUBLISHER_SCRIPT="$TEST_DIR/../../scripts/setup-publisher-user.sh"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT

fail() {
  printf 'publisher setup test failed: %s\n' "$*" >&2
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

[[ "${1:-}" == "run" ]] || exit 65
shift
while [[ $# -gt 0 && "$1" != "wpcli" ]]; do
  shift
done
[[ "${1:-}" == "wpcli" ]] || exit 66
shift
[[ "${1:-}" == "wp" ]] || exit 67
shift
printf 'wp %s\n' "$*" >>"$FAKE_WP_LOG"

case "${1:-}" in
  plugin)
    [[ "${2:-}" == "is-active" ]] || exit 68
    exit 0
    ;;
  term)
    [[ "${2:-}" == "get" && "${3:-}" == "category" ]] || exit 69
    [[ "${FAKE_CATEGORY_EXISTS:-0}" == "1" ]] || exit 70
    printf '44\n'
    exit 0
    ;;
  user)
    case "${2:-}" in
      get)
        [[ "${FAKE_USER_EXISTS:-0}" == "1" ]]
        ;;
      set-role|update|create)
        exit 0
        ;;
      application-password)
        case "${3:-}" in
          list)
            printf '%s\n' "${FAKE_APP_COUNT:-0}"
            ;;
          delete)
            exit 0
            ;;
          create)
            printf 'app-pass-one app-pass-two app-pass-three\n'
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
    ;;
  eval)
    printf 'generated-user-password\n'
    ;;
  *)
    exit 73
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
PUBLISHER_USER=publisher
PUBLISHER_EMAIL=publisher@example.test
NEWS_CATEGORY_SLUG=industry-news
EOF

run_publisher_setup() {
  local category_exists="$1"
  local user_exists="$2"
  local app_count="$3"
  local log_file="$4"
  shift 4

  : >"$log_file"
  PATH="$fake_bin:$PATH" \
  YY_ENV_FILE="$env_file" \
  FAKE_CATEGORY_EXISTS="$category_exists" \
  FAKE_USER_EXISTS="$user_exists" \
  FAKE_APP_COUNT="$app_count" \
  FAKE_WP_LOG="$log_file" \
    "$PUBLISHER_SCRIPT" "$@"
}

missing_category_log="$TMP_DIR/missing-category.log"
if run_publisher_setup 0 1 1 "$missing_category_log" --rotate >/dev/null 2>&1; then
  fail "a missing news category was reported as successful"
fi
if grep -Fq 'application-password' "$missing_category_log"; then
  fail "a missing news category rotated or created an Application Password"
fi
if grep -Eq '^wp user (create|set-role|update)' "$missing_category_log"; then
  fail "a missing news category modified the publisher account"
fi

success_log="$TMP_DIR/success.log"
success_output="$(run_publisher_setup 1 1 0 "$success_log")"
grep -Fq 'API_KEY=publisher' <<<"$success_output" \
  || fail "successful setup did not print the publisher username"
grep -Fq 'API_PASS=app-pass-one app-pass-two app-pass-three' <<<"$success_output" \
  || fail "successful setup did not print the generated Application Password"
grep -Fq 'NEWS_CATEGORY_ID=44' <<<"$success_output" \
  || fail "successful setup did not print the resolved category ID"

category_line="$(grep -n '^wp term get category' "$success_log" | cut -d: -f1)"
user_line="$(grep -n '^wp user set-role' "$success_log" | cut -d: -f1)"
password_line="$(grep -n '^wp user application-password create' "$success_log" | cut -d: -f1)"
[[ -n "$category_line" && -n "$user_line" && -n "$password_line" ]] \
  || fail "successful setup did not execute the expected WP-CLI commands"
[[ "$category_line" -lt "$user_line" && "$user_line" -lt "$password_line" ]] \
  || fail "publisher mutations must happen after category validation and before password output"

printf 'Publisher setup behavior contract passed.\n'
