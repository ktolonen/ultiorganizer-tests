#!/usr/bin/env bash

# Repeatedly download one public page with concurrent wget clients.
# Usage: ./scripts/crawl/wget_public_performance.sh <base_url> <page_url_or_path> <downloads_per_client> <interval_ms> <clients> [output_dir]
#
# Example:
#   ./scripts/crawl/wget_public_performance.sh http://127.0.0.1/ / 1000 10 50
#
# This performs downloads_per_client * clients total public HTTP requests.
#
# Optional overrides:
#   WGET_RETRIES  tries per request (default: 1)
#   WGET_TIMEOUT  request timeout in seconds (default: 30)
#   WGET_USER_AGENT user agent sent by wget (default: ultiorganizer-public-performance/1.0)

set -u
set -o pipefail

if [[ $# -lt 5 ]]; then
  echo "Usage: $0 <base_url> <page_url_or_path> <downloads_per_client> <interval_ms> <clients> [output_dir]" >&2
  exit 1
fi

BASE_URL="${1%/}"
PAGE_INPUT="$2"
DOWNLOADS_PER_CLIENT="$3"
INTERVAL_MS="$4"
CLIENTS="$5"
OUT_DIR="${6:-public_performance}"
LOG_FILE="$OUT_DIR/wget_public_performance.log"
RESULTS_FILE="$OUT_DIR/results.tsv"
SUMMARY_FILE="$OUT_DIR/summary.txt"
WGET_RETRIES="${WGET_RETRIES:-1}"
WGET_TIMEOUT="${WGET_TIMEOUT:-30}"
WGET_USER_AGENT="${WGET_USER_AGENT:-ultiorganizer-public-performance/1.0}"

mkdir -p "$OUT_DIR"
: > "$LOG_FILE"
: > "$RESULTS_FILE"

validate_positive_integer() {
  local label="$1"
  local value="$2"

  if [[ ! "$value" =~ ^[1-9][0-9]*$ ]]; then
    echo "$label must be a positive integer, got '$value'." >&2
    exit 1
  fi
}

validate_non_negative_integer() {
  local label="$1"
  local value="$2"

  if [[ ! "$value" =~ ^[0-9]+$ ]]; then
    echo "$label must be a non-negative integer, got '$value'." >&2
    exit 1
  fi
}

timestamp() {
  date '+%Y-%m-%d %H:%M:%S'
}

now_ms() {
  date '+%s%3N'
}

log_line() {
  local message="$1"
  printf '[%s] %s\n' "$(timestamp)" "$message" | tee -a "$LOG_FILE"
}

resolve_url() {
  local base_url="$1"
  local page="$2"

  if [[ "$page" =~ ^https?:// ]]; then
    printf '%s' "$page"
    return 0
  fi

  if [[ "$page" == /* ]]; then
    printf '%s%s' "$base_url" "$page"
    return 0
  fi

  if [[ "$page" == \?* ]]; then
    printf '%s/%s' "$base_url" "$page"
    return 0
  fi

  printf '%s/%s' "$base_url" "$page"
}

sleep_interval() {
  local interval_ms="$1"

  if [[ "$interval_ms" == "0" ]]; then
    return 0
  fi

  sleep "$(awk -v ms="$interval_ms" 'BEGIN { printf "%.3f", ms / 1000 }')"
}

run_client() {
  local client_id="$1"
  local url="$2"
  local result_file="$3"
  local i start_ms end_ms elapsed_ms status

  for ((i = 1; i <= DOWNLOADS_PER_CLIENT; i++)); do
    start_ms="$(now_ms)"
    if wget --tries="$WGET_RETRIES" --timeout="$WGET_TIMEOUT" --user-agent="$WGET_USER_AGENT" -q -O /dev/null "$url" >>"$LOG_FILE" 2>&1; then
      status=0
    else
      status=$?
    fi
    end_ms="$(now_ms)"
    elapsed_ms=$((end_ms - start_ms))

    printf '%s\t%s\t%s\t%s\t%s\n' "$client_id" "$i" "$status" "$elapsed_ms" "$start_ms" >> "$result_file"

    if (( i < DOWNLOADS_PER_CLIENT )); then
      sleep_interval "$INTERVAL_MS"
    fi
  done
}

validate_positive_integer "downloads_per_client" "$DOWNLOADS_PER_CLIENT"
validate_non_negative_integer "interval_ms" "$INTERVAL_MS"
validate_positive_integer "clients" "$CLIENTS"
validate_positive_integer "WGET_RETRIES" "$WGET_RETRIES"
validate_positive_integer "WGET_TIMEOUT" "$WGET_TIMEOUT"

TARGET_URL="$(resolve_url "$BASE_URL" "$PAGE_INPUT")"
TOTAL_REQUESTS=$((DOWNLOADS_PER_CLIENT * CLIENTS))
INTERVAL_SECONDS="$(awk -v ms="$INTERVAL_MS" 'BEGIN { printf "%.3f", ms / 1000 }')"
MIN_SCHEDULED_MS=$(((DOWNLOADS_PER_CLIENT > 0 ? DOWNLOADS_PER_CLIENT - 1 : 0) * INTERVAL_MS))

printf 'client\titeration\texit_status\telapsed_ms\tstart_epoch_ms\n' > "$RESULTS_FILE"

log_line "Starting public performance run"
log_line "Target: $TARGET_URL"
log_line "Clients: $CLIENTS"
log_line "Downloads per client: $DOWNLOADS_PER_CLIENT"
log_line "Total scheduled downloads: $TOTAL_REQUESTS"
log_line "Interval per client: ${INTERVAL_MS}ms (${INTERVAL_SECONDS}s)"
log_line "Minimum scheduled client duration excluding response time: ${MIN_SCHEDULED_MS}ms"

RUN_STARTED_MS="$(now_ms)"
declare -a pids=()

for ((client = 1; client <= CLIENTS; client++)); do
  client_result_file="$OUT_DIR/client_${client}.tsv"
  : > "$client_result_file"
  run_client "$client" "$TARGET_URL" "$client_result_file" &
  pids+=("$!")
done

run_status=0
for pid in "${pids[@]}"; do
  if ! wait "$pid"; then
    run_status=1
  fi
done
RUN_FINISHED_MS="$(now_ms)"

for ((client = 1; client <= CLIENTS; client++)); do
  cat "$OUT_DIR/client_${client}.tsv" >> "$RESULTS_FILE"
done

awk -F '\t' -v started="$RUN_STARTED_MS" -v finished="$RUN_FINISHED_MS" -v total="$TOTAL_REQUESTS" '
  NR == 1 { next }
  {
    completed += 1
    elapsed_sum += $4
    if ($3 == 0) {
      ok += 1
    } else {
      failed += 1
      failures[$3] += 1
    }
    if (min_elapsed == "" || $4 < min_elapsed) {
      min_elapsed = $4
    }
    if ($4 > max_elapsed) {
      max_elapsed = $4
    }
  }
  END {
    duration_ms = finished - started
    avg_elapsed = completed > 0 ? elapsed_sum / completed : 0
    rps = duration_ms > 0 ? completed / (duration_ms / 1000) : 0

    printf "started_epoch_ms=%s\n", started
    printf "finished_epoch_ms=%s\n", finished
    printf "duration_ms=%d\n", duration_ms
    printf "scheduled_requests=%d\n", total
    printf "completed_requests=%d\n", completed
    printf "successful_requests=%d\n", ok
    printf "failed_requests=%d\n", failed
    printf "requests_per_second=%.2f\n", rps
    printf "min_elapsed_ms=%s\n", min_elapsed == "" ? 0 : min_elapsed
    printf "avg_elapsed_ms=%.2f\n", avg_elapsed
    printf "max_elapsed_ms=%s\n", max_elapsed == "" ? 0 : max_elapsed
    for (status in failures) {
      printf "failure_exit_%s=%d\n", status, failures[status]
    }
  }
' "$RESULTS_FILE" > "$SUMMARY_FILE"

cat "$SUMMARY_FILE" | tee -a "$LOG_FILE"
log_line "Results: $RESULTS_FILE"
log_line "Summary: $SUMMARY_FILE"
log_line "Log file: $LOG_FILE"

if [[ "$run_status" -ne 0 ]]; then
  exit "$run_status"
fi

failed_requests="$(awk -F '=' '$1 == "failed_requests" { print $2 }' "$SUMMARY_FILE")"
if [[ "${failed_requests:-0}" != "0" ]]; then
  exit 1
fi
