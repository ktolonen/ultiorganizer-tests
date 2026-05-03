#!/usr/bin/env bash

# Crawl in-scope pages by authenticating once and then following links recursively.
# Usage: ./scripts/crawl/wget_follow_links.sh <base_url> <start_url_or_path> [output_dir]
#
# Optional authenticated mode:
#   export WGET_LOGIN_USER='superadmin-user'
#   export WGET_LOGIN_PASS='superadmin-password'
#   ./scripts/crawl/wget_follow_links.sh https://example.com '?view=admin/serverconf'
#
# Optional overrides:
#   WGET_LOGIN_URL          overrides automatic login URL discovery
#   WGET_VERIFY_URL         overrides automatic verification URL discovery
#   WGET_CRAWL_MAX_DEPTH    maximum link depth to follow (default: 3)
#   WGET_CRAWL_MAX_PAGES    maximum number of pages to download in total (default: 0, unlimited)
#   WGET_CRAWL_MAX_PAGES_PER_VIEW
#                          maximum number of pages to download for one routed view/path bucket
#                          (default: 25)
#   WGET_MAX_VISITS_PER_URL maximum downloads per normalized URL (default: 1)
#   WGET_ACCEPT_REGEX       only follow matching URLs
#   WGET_REJECT_REGEX       skip matching URLs
#   WGET_BLOCK_AUTH_ROUTES  skip login/logout/session-destroy routes (default: 1)
#   WGET_AUTH_FAILURE_REGEX regex that marks a fetched page as logged out (default: built-in)
#   WGET_PAGE_DELAY         sleep N seconds between requests (default: 0)
#   WGET_RETRIES            retries per request (default: 1)
#   WGET_TIMEOUT            request timeout in seconds (default: 30)

set -u
set -o pipefail

if [[ $# -lt 2 ]]; then
  echo "Usage: $0 <base_url> <start_url_or_path> [output_dir]" >&2
  exit 1
fi

BASE_URL="${1%/}"
START_INPUT="$2"
OUT_DIR="${3:-downloaded_links}"
PAGES_DIR="$OUT_DIR/pages"
LOG_FILE="$OUT_DIR/wget_follow_links.log"
COOKIE_JAR="$OUT_DIR/wget_cookies.txt"
MANIFEST_FILE="$OUT_DIR/manifest.tsv"
AUTH_USER="${WGET_LOGIN_USER:-}"
AUTH_PASS="${WGET_LOGIN_PASS:-}"
LOGIN_URL="${WGET_LOGIN_URL:-}"
VERIFY_URL="${WGET_VERIFY_URL:-}"
MAX_DEPTH="${WGET_CRAWL_MAX_DEPTH:-3}"
MAX_PAGES="${WGET_CRAWL_MAX_PAGES:-0}"
MAX_PAGES_PER_VIEW="${WGET_CRAWL_MAX_PAGES_PER_VIEW:-25}"
MAX_VISITS_PER_URL="${WGET_MAX_VISITS_PER_URL:-1}"
ACCEPT_REGEX="${WGET_ACCEPT_REGEX:-}"
REJECT_REGEX="${WGET_REJECT_REGEX:-}"
BLOCK_AUTH_ROUTES="${WGET_BLOCK_AUTH_ROUTES:-1}"
AUTH_FAILURE_REGEX="${WGET_AUTH_FAILURE_REGEX:-name=[\"'\\'' ]*myusername[\"'\\'' ]|name=[\"'\\'' ]*mypassword[\"'\\'' ]|Login failed|Operation not allowed}"
PAGE_DELAY="${WGET_PAGE_DELAY:-0}"
WGET_RETRIES="${WGET_RETRIES:-1}"
WGET_TIMEOUT="${WGET_TIMEOUT:-30}"

mkdir -p "$PAGES_DIR"
: > "$LOG_FILE"
: > "$MANIFEST_FILE"

validate_integer() {
  local label="$1"
  local value="$2"

  if [[ ! "$value" =~ ^[0-9]+$ ]]; then
    echo "$label must be a non-negative integer, got '$value'." >&2
    exit 1
  fi
}

validate_integer "WGET_CRAWL_MAX_DEPTH" "$MAX_DEPTH"
validate_integer "WGET_CRAWL_MAX_PAGES" "$MAX_PAGES"
validate_integer "WGET_CRAWL_MAX_PAGES_PER_VIEW" "$MAX_PAGES_PER_VIEW"
validate_integer "WGET_MAX_VISITS_PER_URL" "$MAX_VISITS_PER_URL"
validate_integer "WGET_BLOCK_AUTH_ROUTES" "$BLOCK_AUTH_ROUTES"
validate_integer "WGET_RETRIES" "$WGET_RETRIES"
validate_integer "WGET_TIMEOUT" "$WGET_TIMEOUT"

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

  if [[ "$PAGE_DELAY" != "0" ]]; then
    sleep "$PAGE_DELAY"
  fi

  if [[ -n "$AUTH_USER" ]]; then
    wget --tries="$WGET_RETRIES" --timeout="$WGET_TIMEOUT" --load-cookies "$COOKIE_JAR" --keep-session-cookies -q -O "$output_file" "$url" >>"$LOG_FILE" 2>&1
  else
    wget --tries="$WGET_RETRIES" --timeout="$WGET_TIMEOUT" -q -O "$output_file" "$url" >>"$LOG_FILE" 2>&1
  fi
}

discover_url() {
  local label="$1"
  shift
  local candidates=("$@")
  local candidate
  local probe_file="$OUT_DIR/${label}_probe.html"

  for candidate in "${candidates[@]}"; do
    if wget --tries="$WGET_RETRIES" --timeout="$WGET_TIMEOUT" -q -O "$probe_file" "$candidate" >>"$LOG_FILE" 2>&1; then
      rm -f "$probe_file"
      printf '%s' "$candidate"
      return 0
    fi
  done

  rm -f "$probe_file"
  return 1
}

php_helper() {
  php /dev/stdin "$@" <<'PHP'
<?php

array_shift($argv);
$command = array_shift($argv);

function starts_with(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

function normalize_path(string $path): string
{
    $leadingSlash = starts_with($path, '/');
    $trailingSlash = $path !== '/' && substr($path, -1) === '/';
    $segments = explode('/', $path);
    $stack = [];

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            if (!empty($stack)) {
                array_pop($stack);
            }
            continue;
        }

        $stack[] = $segment;
    }

    $normalized = ($leadingSlash ? '/' : '') . implode('/', $stack);
    if ($normalized === '') {
        $normalized = $leadingSlash ? '/' : '.';
    }
    if ($trailingSlash && $normalized !== '/') {
        $normalized .= '/';
    }

    return $normalized;
}

function build_origin(array $parts): string
{
    if (!isset($parts['scheme'], $parts['host'])) {
        return '';
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $origin = $scheme . '://' . $host;

    if (isset($parts['port'])) {
        $port = (int) $parts['port'];
        $defaultPort = ($scheme === 'https') ? 443 : 80;
        if ($port !== $defaultPort) {
            $origin .= ':' . $port;
        }
    }

    return $origin;
}

function absolutize_url(string $base, string $relative): string
{
    if ($relative === '') {
        return $base;
    }

    if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $relative)) {
        return $relative;
    }

    $baseParts = parse_url($base);
    if ($baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
        return '';
    }

    $scheme = $baseParts['scheme'];
    $host = $baseParts['host'];
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    $basePath = $baseParts['path'] ?? '/';
    if ($basePath === '') {
        $basePath = '/';
    }

    if (starts_with($relative, '//')) {
        return $scheme . ':' . $relative;
    }

    if ($relative[0] === '#') {
        return $scheme . '://' . $host . $port . $basePath . $relative;
    }

    if ($relative[0] === '?') {
        return $scheme . '://' . $host . $port . $basePath . $relative;
    }

    if ($relative[0] === '/') {
        return $scheme . '://' . $host . $port . $relative;
    }

    $directory = preg_replace('~/[^/]*$~', '/', $basePath);
    return $scheme . '://' . $host . $port . $directory . $relative;
}

function normalize_url(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return '';
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    $host = strtolower($parts['host']);
    $path = $parts['path'] ?? '/';
    if ($path === '') {
        $path = '/';
    }
    $path = normalize_path($path);
    if (!starts_with($path, '/')) {
        $path = '/' . $path;
    }

    $normalized = $scheme . '://' . $host;
    if (isset($parts['port'])) {
        $port = (int) $parts['port'];
        $defaultPort = ($scheme === 'https') ? 443 : 80;
        if ($port !== $defaultPort) {
            $normalized .= ':' . $port;
        }
    }

    $normalized .= $path;
    if (isset($parts['query']) && $parts['query'] !== '') {
        $normalized .= '?' . $parts['query'];
    }

    return $normalized;
}

function in_scope(string $candidate, string $root): bool
{
    $candidateParts = parse_url($candidate);
    $rootParts = parse_url($root);
    if ($candidateParts === false || $rootParts === false) {
        return false;
    }

    if (build_origin($candidateParts) !== build_origin($rootParts)) {
        return false;
    }

    $rootPath = $rootParts['path'] ?? '/';
    if ($rootPath === '') {
        $rootPath = '/';
    }
    $rootPath = normalize_path($rootPath);
    if (!starts_with($rootPath, '/')) {
        $rootPath = '/' . $rootPath;
    }
    if ($rootPath !== '/' && substr($rootPath, -1) !== '/') {
        $rootPath .= '/';
    }

    $candidatePath = $candidateParts['path'] ?? '/';
    if ($candidatePath === '') {
        $candidatePath = '/';
    }
    $candidatePath = normalize_path($candidatePath);
    if (!starts_with($candidatePath, '/')) {
        $candidatePath = '/' . $candidatePath;
    }

    if ($rootPath === '/') {
        return true;
    }

    return $candidatePath === rtrim($rootPath, '/') || starts_with($candidatePath, $rootPath);
}

if ($command === 'normalize') {
    $base = $argv[0] ?? '';
    $candidate = $argv[1] ?? '';
    $absolute = absolutize_url($base, $candidate);
    $normalized = normalize_url($absolute);
    if ($normalized !== '') {
        echo $normalized;
    }
    exit(0);
}

if ($command === 'bucket') {
    $url = normalize_url($argv[0] ?? '');
    if ($url === '') {
        exit(0);
    }

    $parts = parse_url($url);
    if ($parts === false) {
        exit(0);
    }

    $path = $parts['path'] ?? '/';
    if ($path === '') {
        $path = '/';
    }
    $path = normalize_path($path);
    if (!starts_with($path, '/')) {
        $path = '/' . $path;
    }

    if (isset($parts['query'])) {
        parse_str($parts['query'], $query);
        if (isset($query['view']) && $query['view'] !== '') {
            echo $path, '?view=', $query['view'];
            exit(0);
        }
    }

    echo $path;
    exit(0);
}

if ($command === 'extract') {
    $root = normalize_url($argv[0] ?? '');
    $pageUrl = normalize_url($argv[1] ?? '');
    $inputFile = $argv[2] ?? '';

    if ($root === '' || $pageUrl === '' || $inputFile === '' || !is_file($inputFile)) {
        exit(0);
    }

    $html = @file_get_contents($inputFile);
    if ($html === false || $html === '') {
        exit(0);
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) {
        exit(0);
    }

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//a[@href] | //area[@href]');
    $seen = [];

    foreach ($nodes as $node) {
        $href = trim(html_entity_decode($node->getAttribute('href'), ENT_QUOTES | ENT_HTML5));
        if ($href === '') {
            continue;
        }
        if (preg_match('~^(mailto:|tel:|javascript:|data:)~i', $href)) {
            continue;
        }

        $candidate = normalize_url(absolutize_url($pageUrl, $href));
        if ($candidate === '' || $candidate === $pageUrl || !in_scope($candidate, $root)) {
            continue;
        }

        if (!isset($seen[$candidate])) {
            $seen[$candidate] = true;
            echo $candidate, PHP_EOL;
        }
    }

    exit(0);
}

fwrite(STDERR, "Unknown helper command\n");
exit(1);
PHP
}

normalize_url() {
  php_helper normalize "$1" "$2"
}

url_bucket() {
  php_helper bucket "$1"
}

extract_links() {
  php_helper extract "$BASE_URL" "$1" "$2"
}

url_allowed() {
  local url="$1"
  local effective_reject_regex="$REJECT_REGEX"

  if [[ "$BLOCK_AUTH_ROUTES" == "1" ]]; then
    local auth_route_regex='([?&]view=logout([&#]|$)|(^|/)logout\.php([?#]|$)|[?&]logout=1([&#]|$)|[?&]view=login([&#]|$)|(^|/)login\.php([?#]|$))'
    if [[ -n "$effective_reject_regex" ]]; then
      effective_reject_regex="(${effective_reject_regex})|(${auth_route_regex})"
    else
      effective_reject_regex="$auth_route_regex"
    fi
  fi

  if [[ -n "$ACCEPT_REGEX" ]] && ! printf '%s\n' "$url" | grep -Eq "$ACCEPT_REGEX"; then
    return 1
  fi

  if [[ -n "$effective_reject_regex" ]] && printf '%s\n' "$url" | grep -Eq "$effective_reject_regex"; then
    return 1
  fi

  return 0
}

response_indicates_auth_loss() {
  local file_path="$1"

  [[ -n "$AUTH_USER" ]] || return 1
  [[ -s "$file_path" ]] || return 1

  grep -qiE "$AUTH_FAILURE_REGEX" "$file_path"
}

save_manifest_entry() {
  local sequence="$1"
  local depth="$2"
  local url="$3"
  local file_path="$4"
  printf '%s\t%s\t%s\t%s\n' "$sequence" "$depth" "$url" "$file_path" >> "$MANIFEST_FILE"
}

page_filename() {
  local sequence="$1"
  local url="$2"
  local hash
  hash="$(printf '%s' "$url" | sha1sum | awk '{print $1}')"
  printf '%s/page_%04d_%s.html' "$PAGES_DIR" "$sequence" "${hash:0:12}"
}

if [[ -n "$AUTH_USER" || -n "$AUTH_PASS" ]]; then
  if [[ -z "$AUTH_USER" || -z "$AUTH_PASS" ]]; then
    echo "Set both WGET_LOGIN_USER and WGET_LOGIN_PASS to enable authenticated mode." >&2
    exit 1
  fi

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

  touch "$COOKIE_JAR"
  chmod 600 "$COOKIE_JAR"

  login_response="$OUT_DIR/login_response.html"
  verify_response="$OUT_DIR/login_verify.html"
  post_data="myusername=$(urlencode "$AUTH_USER")&mypassword=$(urlencode "$AUTH_PASS")"

  log_line "Authenticating against $LOGIN_URL as $AUTH_USER"

  if wget --tries="$WGET_RETRIES" --timeout="$WGET_TIMEOUT" --save-cookies "$COOKIE_JAR" --keep-session-cookies -q -O "$login_response" --post-data "$post_data" "$LOGIN_URL" >>"$LOG_FILE" 2>&1; then
    if wget --tries="$WGET_RETRIES" --timeout="$WGET_TIMEOUT" --load-cookies "$COOKIE_JAR" --keep-session-cookies -q -O "$verify_response" "$VERIFY_URL" >>"$LOG_FILE" 2>&1 \
      && ! grep -qiE "$AUTH_FAILURE_REGEX" "$verify_response"; then
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

START_URL="$(normalize_url "$BASE_URL/" "$START_INPUT")"
if [[ -z "$START_URL" ]]; then
  echo "Could not resolve start URL from '$START_INPUT'." >&2
  exit 1
fi

if ! url_allowed "$START_URL"; then
  echo "Start URL '$START_URL' is blocked by the configured regex filters." >&2
  exit 1
fi

declare -a queue_urls=("$START_URL")
declare -a queue_depths=(0)
declare -A queued_urls=()
declare -A visit_counts=()
declare -A view_counts=()
declare -a failed_urls=()

queued_urls["$START_URL"]=1
downloaded_count=0
sequence=0
queue_index=0

log_line "Starting crawl from $START_URL"
log_line "Limits: depth=$MAX_DEPTH total_pages=$MAX_PAGES per_view_pages=$MAX_PAGES_PER_VIEW max_visits_per_url=$MAX_VISITS_PER_URL"
if [[ "$BLOCK_AUTH_ROUTES" == "1" ]]; then
  log_line "Auth-route blocking enabled"
fi

while (( queue_index < ${#queue_urls[@]} )); do
  current_url="${queue_urls[$queue_index]}"
  current_depth="${queue_depths[$queue_index]}"
  ((queue_index += 1))

  current_visits="${visit_counts["$current_url"]:-0}"
  if (( current_visits >= MAX_VISITS_PER_URL )); then
    log_line "SKIP visit-limit url=$current_url visits=$current_visits"
    continue
  fi

  if (( MAX_PAGES > 0 && downloaded_count >= MAX_PAGES )); then
    log_line "Reached page limit ($MAX_PAGES); stopping crawl"
    break
  fi

  current_bucket="$(url_bucket "$current_url")"
  current_bucket_count="${view_counts["$current_bucket"]:-0}"
  if (( MAX_PAGES_PER_VIEW > 0 && current_bucket_count >= MAX_PAGES_PER_VIEW )); then
    log_line "SKIP view-limit bucket=$current_bucket url=$current_url count=$current_bucket_count"
    continue
  fi

  tmp_file="$OUT_DIR/page_${queue_index}.part"
  log_line "TRY  depth=$current_depth bucket=$current_bucket url=$current_url"

  if authenticated_wget "$tmp_file" "$current_url"; then
    ((sequence += 1))
    final_file="$(page_filename "$sequence" "$current_url")"
    mv "$tmp_file" "$final_file"

    if response_indicates_auth_loss "$final_file"; then
      log_line "AUTH LOST url=$current_url saved=$final_file"
      echo "Authentication appears to have been lost while fetching $current_url. Inspect $final_file and $LOG_FILE." >&2
      exit 1
    fi

    visit_counts["$current_url"]=$((current_visits + 1))
    view_counts["$current_bucket"]=$((current_bucket_count + 1))
    ((downloaded_count += 1))

    save_manifest_entry "$sequence" "$current_depth" "$current_url" "$final_file"
    log_line "OK   depth=$current_depth bucket=$current_bucket url=$current_url -> $final_file"

    if (( current_depth < MAX_DEPTH )); then
      while IFS= read -r discovered_url; do
        [[ -z "$discovered_url" ]] && continue

        if ! url_allowed "$discovered_url"; then
          log_line "REJECT filter url=$discovered_url"
          continue
        fi

        if [[ -n "${queued_urls["$discovered_url"]:-}" ]]; then
          continue
        fi

        queued_urls["$discovered_url"]=1
        queue_urls+=("$discovered_url")
        queue_depths+=($((current_depth + 1)))
        log_line "QUEUE depth=$((current_depth + 1)) url=$discovered_url"
      done < <(extract_links "$current_url" "$final_file")
    fi
  else
    status=$?
    rm -f "$tmp_file"
    failed_urls+=("$current_url")
    log_line "FAIL exit=$status depth=$current_depth url=$current_url"
  fi
done

log_line "Completed: ${downloaded_count} pages downloaded, ${#failed_urls[@]} failures"
log_line "Manifest: $MANIFEST_FILE"
log_line "Log file: $LOG_FILE"

if [[ ${#failed_urls[@]} -gt 0 ]]; then
  {
    echo "Failed URLs:"
    for failed_url in "${failed_urls[@]}"; do
      echo " - $failed_url"
    done
  } | tee -a "$LOG_FILE" >&2
  exit 1
fi
