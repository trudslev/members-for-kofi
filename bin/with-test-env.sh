#!/usr/bin/env bash
# Runs a command against the throwaway test site, then puts the machine back
# the way it was found.
#
#   bin/with-test-env.sh <command> [args...]
#
# If the test containers were not running, they are started and provisioned for
# the command and stopped again afterwards -- also when the command fails or is
# interrupted. If they were already running (someone ran `make test-env-up` to
# iterate), they are left running: this only stops what it started.
#
# The site lives in RAM (database and docroot), so stopping also removes its
# volume: there is nothing on disk to keep, and a run leaves nothing behind.
#
# Set KEEP_TEST_ENV=1 to leave them running regardless.
#
# TEST_ENV_HTTP=1 also starts the web server, for the suites that drive the site
# over HTTP; PHPUnit alone needs only the database and WP-CLI. If the rest was
# already running and only the web server was started here, only it is stopped.
#
# Before this, every test target and the release left a WordPress and a MySQL
# container running indefinitely.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

# With the "http" profile, so down, ps and rm also see the web server.
COMPOSE="docker compose -f docker-compose.test.yml --profile http"

# shellcheck disable=SC2086 # COMPOSE is a command line by design.
running() { [ -n "$($COMPOSE ps -q --status running "$1" 2>/dev/null)" ]; }

STARTED_HERE=0
if ! running wpcli; then
  STARTED_HERE=1
fi

STARTED_HTTP=0
if [ "${TEST_ENV_HTTP:-0}" = 1 ] && ! running wordpress; then
  STARTED_HTTP=1
fi

stop_if_started_here() {
  if [ "${KEEP_TEST_ENV:-0}" = 1 ]; then
    return
  fi
  if [ "$STARTED_HERE" = 0 ] && [ "$STARTED_HTTP" = 1 ]; then
    # shellcheck disable=SC2086
    $COMPOSE rm -sf wordpress >/dev/null 2>&1 || true
    echo "Stopped the web server (it was not running before; KEEP_TEST_ENV=1 keeps it)."
    return
  fi
  if [ "$STARTED_HERE" = 1 ]; then
    # shellcheck disable=SC2086
    $COMPOSE down -v >/dev/null 2>&1 || true
    echo "Stopped the test site (it was not running before; KEEP_TEST_ENV=1 keeps it)."
  fi
}
trap stop_if_started_here EXIT

# The containers are capped at 384 MB (database), 384 MB (WP-CLI and PHPUnit)
# and 256 MB (web server). On a machine that is already short of memory,
# starting them can tip the whole machine into swap, so refuse instead.
# TEST_ENV_MIN_FREE_MB overrides the threshold.
if [ "$STARTED_HERE" = 1 ] || [ "$STARTED_HTTP" = 1 ]; then
  min_free_mb=${TEST_ENV_MIN_FREE_MB:-1024}
  available_mb=$(awk '/^MemAvailable:/ { print int($2 / 1024) }' /proc/meminfo 2>/dev/null)
  if [ -n "$available_mb" ] && [ "$available_mb" -lt "$min_free_mb" ]; then
    STARTED_HERE=0 # Nothing was started, so there is nothing to stop.
    STARTED_HTTP=0
    echo "Not starting the test site: only ${available_mb} MB of memory is available, and it needs ${min_free_mb} MB." >&2
    echo "Free some memory first (close idle sessions or programs), or set TEST_ENV_MIN_FREE_MB to override." >&2
    exit 1
  fi
fi

# Always (re)provision: it resets the plugin options the suites expect, whether
# or not the containers were already up, and starts what is missing.
bash bin/test-env-init.sh || exit 1

"$@"
