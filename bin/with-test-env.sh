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
# The same goes for the site's volumes: if none existed, the ones created for
# this run are removed with the containers, so a run from a clean machine
# leaves nothing behind. Volumes that were already there are kept.
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

# The compose project's name, as Compose resolves it (the directory name unless
# a name is set), so this keeps working if the checkout is renamed.
# shellcheck disable=SC2086
PROJECT=$($COMPOSE config 2>/dev/null | sed -n 's/^name:[[:space:]]*//p' | head -1)

volumes_exist() { [ -n "$(docker volume ls -q --filter "label=com.docker.compose.project=${PROJECT}")" ]; }

STARTED_HERE=0
if ! running; then
  STARTED_HERE=1
fi

HAD_VOLUMES=0
if volumes_exist; then
  HAD_VOLUMES=1
fi

stop_if_started_here() {
  if [ "$STARTED_HERE" = 1 ] && [ "${KEEP_TEST_ENV:-0}" != 1 ]; then
    if [ "$HAD_VOLUMES" = 1 ]; then
      # shellcheck disable=SC2086
      $COMPOSE down >/dev/null 2>&1 || true
      echo "Stopped the test containers (they were not running before; KEEP_TEST_ENV=1 keeps them)."
    else
      # shellcheck disable=SC2086
      $COMPOSE down -v >/dev/null 2>&1 || true
      echo "Stopped the test containers and removed the test site's volumes (neither existed before; KEEP_TEST_ENV=1 keeps them)."
    fi
  fi
}
trap stop_if_started_here EXIT

# Starting the stack needs about 1.5 GB (its containers are capped at 768 MB,
# 512 MB and 256 MB). On a machine that is already short of memory, starting it
# can tip the whole machine into swap, so refuse instead.
# TEST_ENV_MIN_FREE_MB overrides the threshold.
if [ "$STARTED_HERE" = 1 ]; then
  min_free_mb=${TEST_ENV_MIN_FREE_MB:-1536}
  available_mb=$(awk '/^MemAvailable:/ { print int($2 / 1024) }' /proc/meminfo 2>/dev/null)
  if [ -n "$available_mb" ] && [ "$available_mb" -lt "$min_free_mb" ]; then
    STARTED_HERE=0 # Nothing was started, so there is nothing to stop.
    echo "Not starting the test site: only ${available_mb} MB of memory is available, and it needs ${min_free_mb} MB." >&2
    echo "Free some memory first (close idle sessions or programs), or set TEST_ENV_MIN_FREE_MB to override." >&2
    exit 1
  fi
fi

# Always (re)provision: it pulls the newest WordPress and resets the plugin
# options the suites expect, whether or not the containers were already up.
make --no-print-directory test-env-up || exit 1

"$@"
