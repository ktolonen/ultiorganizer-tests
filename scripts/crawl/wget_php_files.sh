#!/usr/bin/env bash

# Download every PHP file under a given input root from a remote base URL.
# Usage: ./scripts/crawl/wget_php_files.sh <base_url> <input_root> [output_dir]
#
# Optional authenticated mode:
#   export WGET_LOGIN_USER='superadmin-user'
#   export WGET_LOGIN_PASS='superadmin-password'
#   ./scripts/crawl/wget_php_files.sh https://example.com /path/to/php/tree
#
# Optional overrides:
#   WGET_LOGIN_URL   overrides automatic login URL discovery
#   WGET_VERIFY_URL  overrides automatic verification URL discovery

set -u
set -o pipefail

if [[ $# -lt 2 ]]; then
  echo "Usage: $0 <base_url> <input_root> [output_dir]" >&2
  exit 1
fi

BASE_URL="${1%/}"
INPUT_ROOT="${2%/}"
OUT_DIR="${3:-downloaded_php}"
LOG_FILE="$OUT_DIR/wget_php_files.log"
COOKIE_JAR="$OUT_DIR/wget_cookies.txt"
AUTH_USER="${WGET_LOGIN_USER:-}"
AUTH_PASS="${WGET_LOGIN_PASS:-}"
LOGIN_URL="${WGET_LOGIN_URL:-}"
VERIFY_URL="${WGET_VERIFY_URL:-}"
URL_QUERY="${WGET_QUERY_STRING:-}"

if [[ ! -d "$INPUT_ROOT" ]]; then
  echo "Input root '$INPUT_ROOT' does not exist or is not a directory." >&2
  exit 1
fi

mkdir -p "$OUT_DIR"
: > "$LOG_FILE"

urlencode() {
  local input="$1"
  local length="${#input}"
  local encoded=""
  local i char

  for ((i = 0; i < length; i++)); do
    char="${input:i:1}"
    case "$char" in
      [a-zA-Z0-9.~_-]) encoded+="$char" ;;
      *) printf -v encoded '%s%%%02X' "$encoded" "'$char" ;;
    esac
  done

  printf '%s' "$encoded"
}

timestamp() {
  date '+%Y-%m-%d %H:%M:%S'
}

log_line() {
  local message="$1"
  printf '[%s] %s\n' "$(timestamp)" "$message" | tee -a "$LOG_FILE"
}

authenticated_wget() {
  local output_file="$1"
  local url="$2"

  if [[ -n "$AUTH_USER" ]]; then
    wget --load-cookies "$COOKIE_JAR" --keep-session-cookies -q -O "$output_file" "$url" >>"$LOG_FILE" 2>&1
  else
    wget -q -O "$output_file" "$url" >>"$LOG_FILE" 2>&1
  fi
}

discover_url() {
  local label="$1"
  shift
  local candidates=("$@")
  local candidate
  local probe_file="$OUT_DIR/${label}_probe.html"

  for candidate in "${candidates[@]}"; do
    if wget -q -O "$probe_file" "$candidate" >>"$LOG_FILE" 2>&1; then
      rm -f "$probe_file"
      printf '%s' "$candidate"
      return 0
    fi
  done

  rm -f "$probe_file"
  return 1
}

if [[ -z "$LOGIN_URL" ]]; then
  LOGIN_URL="$(discover_url "login" \
    "$BASE_URL/index.php?view=login" \
    "$BASE_URL/?view=login" \
    "$BASE_URL/login.php" \
    "$BASE_URL/login/index.php")" || {
    echo "Could not discover a login URL under $BASE_URL. Set WGET_LOGIN_URL explicitly." >&2
    exit 1
  }
  log_line "Discovered login URL: $LOGIN_URL"
fi

if [[ -z "$VERIFY_URL" ]]; then
  VERIFY_URL="$(discover_url "verify" \
    "$BASE_URL/index.php?view=admin/serverconf" \
    "$BASE_URL/?view=admin/serverconf" \
    "$BASE_URL/admin/serverconf.php")" || {
    echo "Could not discover a verification URL under $BASE_URL. Set WGET_VERIFY_URL explicitly." >&2
    exit 1
  }
  log_line "Discovered verify URL: $VERIFY_URL"
fi

if [[ -n "$AUTH_USER" || -n "$AUTH_PASS" ]]; then
  if [[ -z "$AUTH_USER" || -z "$AUTH_PASS" ]]; then
    echo "Set both WGET_LOGIN_USER and WGET_LOGIN_PASS to enable authenticated mode." >&2
    exit 1
  fi

  touch "$COOKIE_JAR"
  chmod 600 "$COOKIE_JAR"

  login_response="$OUT_DIR/login_response.html"
  verify_response="$OUT_DIR/login_verify.html"
  post_data="myusername=$(urlencode "$AUTH_USER")&mypassword=$(urlencode "$AUTH_PASS")"

  log_line "Authenticating against $LOGIN_URL as $AUTH_USER"

  if wget --save-cookies "$COOKIE_JAR" --keep-session-cookies -q -O "$login_response" --post-data "$post_data" "$LOGIN_URL" >>"$LOG_FILE" 2>&1; then
    if wget --load-cookies "$COOKIE_JAR" --keep-session-cookies -q -O "$verify_response" "$VERIFY_URL" >>"$LOG_FILE" 2>&1 \
      && ! grep -qiE 'login failed|operation not allowed' "$verify_response"; then
      rm -f "$login_response" "$verify_response"
      log_line "Authentication succeeded; session cookie stored in $COOKIE_JAR"
    else
      log_line "Authentication verification failed for $VERIFY_URL"
      rm -f "$login_response" "$verify_response"
      exit 1
    fi
  else
    status=$?
    rm -f "$login_response" "$verify_response"
    log_line "Authentication request failed with exit=$status"
    exit 1
  fi
fi

# Find PHP files relative to the provided input root.
mapfile -d '' -t php_files < <(cd "$INPUT_ROOT" && find . -type f -name '*.php' -print0)

if [[ ${#php_files[@]} -eq 0 ]]; then
  log_line "No PHP files found under $INPUT_ROOT"
  exit 0
fi

failed_files=()
downloaded_count=0

for path in "${php_files[@]}"; do
  rel_path="${path#./}"
  dest="$OUT_DIR/$rel_path"
  tmp_dest="${dest}.part"
  url="$BASE_URL/$rel_path"
  if [[ -n "$URL_QUERY" ]]; then
    if [[ "$url" == *\?* ]]; then
      url="$url&$URL_QUERY"
    else
      url="$url?$URL_QUERY"
    fi
  fi

  mkdir -p "$(dirname "$dest")"
  log_line "TRY  $url -> $dest"

  if authenticated_wget "$tmp_dest" "$url"; then
    mv "$tmp_dest" "$dest"
    log_line "OK   $url -> $dest"
    ((downloaded_count += 1))
  else
    status=$?
    rm -f "$tmp_dest"
    failed_files+=("$rel_path")
    log_line "FAIL exit=$status $url -> $dest"
  fi
done

log_line "Completed: ${downloaded_count} succeeded, ${#failed_files[@]} failed"
log_line "Log file: $LOG_FILE"

if [[ ${#failed_files[@]} -gt 0 ]]; then
  {
    echo "Failed downloads:"
    for rel_path in "${failed_files[@]}"; do
      echo " - $rel_path"
    done
  } | tee -a "$LOG_FILE" >&2
  exit 1
fi
