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
# Set KEEP_TEST_ENV=1 to leave them running regardless.
#
# Before this, every test target and the release left a WordPress and a MySQL
# container running indefinitely.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

COMPOSE="docker compose -f docker-compose.test.yml"

# shellcheck disable=SC2086 # COMPOSE is a command line by design.
running() { [ -n "$($COMPOSE ps -q --status running 2>/dev/null)" ]; }

STARTED_HERE=0
if ! running; then
  STARTED_HERE=1
fi

stop_if_started_here() {
  if [ "$STARTED_HERE" = 1 ] && [ "${KEEP_TEST_ENV:-0}" != 1 ]; then
    # shellcheck disable=SC2086
    $COMPOSE down >/dev/null 2>&1 || true
    echo "Stopped the test containers (they were not running before; KEEP_TEST_ENV=1 keeps them)."
  fi
}
trap stop_if_started_here EXIT

# Always (re)provision: it pulls the newest WordPress and resets the plugin
# options the suites expect, whether or not the containers were already up.
make --no-print-directory test-env-up || exit 1

"$@"
