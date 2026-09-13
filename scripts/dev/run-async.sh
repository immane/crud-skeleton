#!/usr/bin/env bash
# Run async outbox publish + Messenger consume for a bounded duration.
# Usage: ./scripts/dev/run-async.sh [duration] [--memory 256M] [--verbose|-v] [--interval 5] [--dry-run]
#   duration: seconds (default 60), supports 60 / 60s / 2m / 1h
#   interval: publish loop interval seconds (default: $OUTBOX_PUBLISH_INTERVAL or 5)
set -euo pipefail

DURATION_RAW="60"
MEMORY="256M"
VERBOSE=1
DRY_RUN=0
INTERVAL_RAW="${OUTBOX_PUBLISH_INTERVAL:-5}"

# Parse args: first non-flag is duration, rest are flags
POSITIONAL=()
while (( $# > 0 )); do
  case "$1" in
    --memory=*) MEMORY="${1#--memory=}"; shift;;
    --memory) MEMORY="${2:-256M}"; shift 2;;
    --interval=*) INTERVAL_RAW="${1#--interval=}"; shift;;
    --interval) INTERVAL_RAW="${2:-5}"; shift 2;;
    --verbose|-v|--debug) VERBOSE=1; shift;;
    --quiet|-q) VERBOSE=0; shift;;
    --dry-run) DRY_RUN=1; shift;;
    --) shift; break;;
    -*) echo "[run-async] unknown option: $1" >&2; shift;;
    *) POSITIONAL+=("$1"); shift;;
  esac
done
if (( ${#POSITIONAL[@]} > 0 )); then
  DURATION_RAW="${POSITIONAL[0]}"
fi

normalize_duration() {
  local raw="$1"
  raw="$(echo "$raw" | tr -d '[:space:]')"
  if [[ "$raw" =~ ^([0-9]+)h$ ]]; then
    echo $(( ${BASH_REMATCH[1]} * 3600 ))
  elif [[ "$raw" =~ ^([0-9]+)m$ ]]; then
    echo $(( ${BASH_REMATCH[1]} * 60 ))
  elif [[ "$raw" =~ ^([0-9]+)s$ ]]; then
    echo "${BASH_REMATCH[1]}"
  elif [[ "$raw" =~ ^[0-9]+$ ]]; then
    echo "$raw"
  else
    echo "60"
  fi
}

DURATION="$(normalize_duration "$DURATION_RAW")"
INTERVAL="$(normalize_duration "$INTERVAL_RAW")"
if (( DURATION < 1 )); then DURATION=1; fi
if (( DURATION > 3600 )); then DURATION=3600; fi
if (( INTERVAL < 1 )); then INTERVAL=1; fi
if (( INTERVAL > 60 )); then INTERVAL=60; fi

PHP_BIN="php"
if command -v symfony >/dev/null 2>&1; then
  if symfony php --version >/dev/null 2>&1; then
    PHP_BIN="symfony php"
  fi
fi

VERBOSITY_FLAG=""
if (( VERBOSE )); then
  VERBOSITY_FLAG="-v"
fi

echo "[run-async] duration=${DURATION}s interval=${INTERVAL}s memory=${MEMORY} php=\"${PHP_BIN}\" verbose=${VERBOSE}"

if (( DRY_RUN )); then
  echo "[dry-run] Would run publish loop (interval ${INTERVAL}s) in background + consume in foreground:"
  echo "  (while true; do ${PHP_BIN} bin/console app:trade:outbox:publish --no-interaction ${VERBOSITY_FLAG}; ${PHP_BIN} bin/console app:store:outbox:publish --no-interaction ${VERBOSITY_FLAG}; ${PHP_BIN} bin/console app:inventory:outbox:publish --no-interaction ${VERBOSITY_FLAG}; sleep ${INTERVAL}; done) &"
  echo "  ${PHP_BIN} bin/console messenger:consume async --time-limit=${DURATION} --memory-limit=${MEMORY} --no-interaction ${VERBOSITY_FLAG}"
  exit 0
fi

# Background publish loop (mirrors compose.yaml scheduler:5)
# Uses SECONDS for bounded duration, same interval as OUTBOX_PUBLISH_INTERVAL
publish_loop() {
  local end=$((SECONDS + DURATION))
  while (( SECONDS < end )); do
    # shellcheck disable=SC2086
    ${PHP_BIN} bin/console app:trade:outbox:publish --no-interaction ${VERBOSITY_FLAG} 2>&1 | sed 's/^/[trade-outbox] /' || true
    # shellcheck disable=SC2086
    ${PHP_BIN} bin/console app:store:outbox:publish --no-interaction ${VERBOSITY_FLAG} 2>&1 | sed 's/^/[store-outbox] /' || true
    # shellcheck disable=SC2086
    ${PHP_BIN} bin/console app:inventory:outbox:publish --no-interaction ${VERBOSITY_FLAG} 2>&1 | sed 's/^/[inventory-outbox] /' || true
    # Also run housekeeping like scheduler does (best-effort, no log spam)
    # shellcheck disable=SC2086
    ${PHP_BIN} bin/console app:inventory:reservations:release-expired --no-interaction 2>&1 | sed 's/^/[inventory-expire] /' || true
    sleep "$INTERVAL"
    # Early exit if end reached during sleep
    if (( SECONDS >= end )); then break; fi
  done
}

echo "[run-async] starting publish loop (interval ${INTERVAL}s) in background + consume in foreground..."
publish_loop &
PUBLISH_PID=$!

# Ensure publish loop is killed on exit/interrupt
cleanup() {
  kill "$PUBLISH_PID" 2>/dev/null || true
  wait "$PUBLISH_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "[run-async] consuming async for ${DURATION}s (publish PID ${PUBLISH_PID})..."
set +e
# shellcheck disable=SC2086
${PHP_BIN} bin/console messenger:consume async --time-limit="${DURATION}" --memory-limit="${MEMORY}" --no-interaction ${VERBOSITY_FLAG}
CONSUME_EXIT=$?
set -e

# Stop publish loop
kill "$PUBLISH_PID" 2>/dev/null || true
wait "$PUBLISH_PID" 2>/dev/null || true
trap - EXIT INT TERM

echo "[run-async] done (consume exit=${CONSUME_EXIT}). Quick checks:"
${PHP_BIN} bin/console messenger:stats 2>&1 | sed 's/^/[stats] /' || true
echo "[hint] SELECT uuid, operational_status FROM store_order ORDER BY id DESC LIMIT 5;"
echo "[hint] SELECT queue_name, COUNT(*) FROM messenger_messages GROUP BY queue_name;"
