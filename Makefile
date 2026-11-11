# Members for Ko-fi
#
# Targets here are the ones anyone working on the plugin can run. Publishing
# targets need credentials or push rights, so they live in an untracked
# Makefile.local -- see the bottom of this file.

ifneq ("$(wildcard .env)","")
include .env
export
endif

TEST_COMPOSE:=docker compose -f docker-compose.test.yml
# The same, including the web server (profile "http"), for commands that must
# see every container: down, pull, logs.
TEST_COMPOSE_ALL:=$(TEST_COMPOSE) --profile http
WP_TEST_PORT?=8101
# What the suite runs against. WP_VERSION is downloaded by WP-CLI; the other two
# pick the images in docker-compose.test.yml.
WP_VERSION?=latest
PHP_VERSION?=8.3
DB_IMAGE?=mariadb:11.4
export WP_VERSION PHP_VERSION DB_IMAGE
PLUGIN_PATH_IN_CONTAINER:=/var/www/html/wp-content/plugins/members-for-kofi

# Runs a command against the test site and stops the containers afterwards if
# it was the one that started them. Every target that needs the running site
# goes through this, so nothing is left running after a test run or a release.
# `make test-env-up` first, or KEEP_TEST_ENV=1, keeps them up between runs.
# TEST_ENV_HTTP=1 in front of it also starts the web server.
WITH_TEST_ENV:=bash bin/with-test-env.sh
# WP-CLI inside the running test site.
WPCLI_SITE:=$(TEST_COMPOSE) exec -T wpcli wp
TEST_SITE_RUNNING:=[ -n "$$($(TEST_COMPOSE) ps -q --status running wpcli 2>/dev/null)" ]
# Commands that need only the plugin's own files (i18n) run WP-CLI against this
# checkout alone, without the test site.
WPCLI_FILES:=docker run --rm -u 33:33 -v $(CURDIR):$(PLUGIN_PATH_IN_CONTAINER) -w $(PLUGIN_PATH_IN_CONTAINER) wordpress:cli-php$(PHP_VERSION) wp
# The images the test site uses, pinned in docker-compose.test.yml.
TEST_IMAGES=$$($(TEST_COMPOSE_ALL) config --images 2>/dev/null)

# Nothing is pulled on a normal run: the images are pinned, and WordPress core
# comes from WP-CLI (WP_VERSION, default latest). This refreshes the pinned
# images, for patch releases of the database and PHP, and removes the ones a
# pull superseded. Not forced: an image that another tag or a container still
# uses is refused by Docker and simply stays.
.PHONY: test-env-pull
test-env-pull:
	@images="$(TEST_IMAGES)"; \
	old=$$(docker image ls -q $$images 2>/dev/null | sort -u); \
	$(TEST_COMPOSE_ALL) pull --quiet || exit 1; \
	new=$$(docker image ls -q $$images 2>/dev/null | sort -u); \
	for id in $$old; do \
		if ! echo "$$new" | grep -qx "$$id"; then \
			if docker image rm "$$id" >/dev/null 2>&1; then echo "Removed superseded image $$id"; fi; \
		fi; \
	done

# Boots and provisions the full test site, web server included, and leaves it
# running for fast iteration; the test targets then reuse it.
.PHONY: test-env-up
test-env-up: vendor
	@TEST_ENV_HTTP=1 bash bin/test-env-init.sh

# The site lives in RAM, so there is nothing on disk to keep: its volume goes too.
.PHONY: test-env-down
test-env-down:
	$(TEST_COMPOSE_ALL) down -v

# Removes everything the test site leaves on the machine: containers, network,
# volumes, and the images only this project's tests use (about 1.3 GB; the
# next run downloads them again). Images are not forced: one that another
# container or tag still uses is refused by Docker and stays.
.PHONY: test-env-clean
test-env-clean:
	@images="$(TEST_IMAGES)"; \
	$(TEST_COMPOSE_ALL) down -v >/dev/null 2>&1 || true; \
	for image in $$images; do \
		if docker image rm "$$image" >/dev/null 2>&1; then echo "Removed image $$image"; fi; \
	done
	@echo "Test site removed: containers, network, volumes and unused images."

# Lists anything this project has left behind, in any of its compose projects:
# running containers, and volumes (which `test-env-down` keeps).
.PHONY: test-env-status
test-env-status:
	@left=$$(for project in members-for-kofi kofi-plugin-check; do \
		docker ps --format '{{.Names}}  ({{.Status}})' --filter label=com.docker.compose.project=$$project; \
	done); \
	if [ -n "$$left" ]; then echo "Still running:"; echo "$$left"; echo "Stop with: make test-env-down"; \
	else echo "No test containers running."; fi
	@volumes=$$(for project in members-for-kofi kofi-plugin-check; do \
		docker volume ls -q --filter label=com.docker.compose.project=$$project; \
	done); \
	if [ -n "$$volumes" ]; then echo "Volumes left behind:"; echo "$$volumes"; echo "Remove with: make test-env-clean"; \
	else echo "No test volumes left."; fi

# Full wipe: discards the WordPress install and database.
.PHONY: test-env-reset
test-env-reset:
	$(TEST_COMPOSE_ALL) down -v
	$(MAKE) test-env-up

vendor:
	@[ -d vendor ] || composer install

# The public test targets start the site if needed and stop it again if they
# started it (see WITH_TEST_ENV). The underscore targets do the work and assume
# the site is running; they are not meant to be called directly.
.PHONY: test
test: vendor
	@$(WITH_TEST_ENV) $(MAKE) --no-print-directory _test

.PHONY: _test
_test:
	@echo "Running test suite inside the WP-CLI container..."
	$(TEST_COMPOSE) exec -T -w $(PLUGIN_PATH_IN_CONTAINER) wpcli php ./vendor/bin/phpunit --cache-result-file=/tmp/phpunit.result.cache

.PHONY: test-case
test-case: vendor
	@$(WITH_TEST_ENV) $(MAKE) --no-print-directory _test-case

.PHONY: _test-case
_test-case:
	@echo "Running tests matching '$(TEST)'..."
	$(TEST_COMPOSE) exec -T -w $(PLUGIN_PATH_IN_CONTAINER) wpcli php ./vendor/bin/phpunit --cache-result-file=/tmp/phpunit.result.cache --filter '$(TEST)'

# Drives the local site over real HTTP, exactly as Ko-fi would.
.PHONY: test-integration
test-integration: vendor
	@TEST_ENV_HTTP=1 $(WITH_TEST_ENV) $(MAKE) --no-print-directory _test-integration

.PHONY: _test-integration
_test-integration:
	@echo "Running HTTP integration tests against http://localhost:$(WP_TEST_PORT)..."
	WP_TEST_SITE_URL=http://localhost:$(WP_TEST_PORT) ./vendor/bin/phpunit --configuration phpunit-integration.xml

# One start and one stop around both suites.
.PHONY: test-all
test-all: vendor
	@TEST_ENV_HTTP=1 $(WITH_TEST_ENV) $(MAKE) --no-print-directory _test _test-integration

.PHONY: test-shell
test-shell:
	$(TEST_COMPOSE) exec -w $(PLUGIN_PATH_IN_CONTAINER) wpcli sh

# Reports the WordPress version the test environment is actually running.
.PHONY: wp-version
wp-version:
	@if $(TEST_SITE_RUNNING); then $(WPCLI_SITE) core version 2>/dev/null | tr -d '\r\n'; echo; \
	else echo "The test site is not running (test runs start it on demand, or: make test-env-up)."; fi

# Syncs readme.txt's "Tested up to:" with the version we just tested against.
# WordPress.org reads only major.minor there (7.1 covers 7.1.2), so the patch
# level is dropped.
.PHONY: tested-up-to
tested-up-to: vendor
	@$(WITH_TEST_ENV) $(MAKE) --no-print-directory _tested-up-to

.PHONY: _tested-up-to
_tested-up-to:
	@version=$$($(WPCLI_SITE) core version 2>/dev/null | tr -d '\r\n' | cut -d. -f1,2); \
	if [ -z "$$version" ]; then echo "ERROR: could not determine WordPress version from the test environment."; exit 1; fi; \
	current=$$(grep -E '^Tested up to:' readme.txt | sed -E 's/^Tested up to:[[:space:]]*//'); \
	if [ "$$current" = "$$version" ]; then \
		echo "readme.txt already says 'Tested up to: $$version'"; \
	else \
		sed -i -E "s/^Tested up to:.*/Tested up to: $$version/" readme.txt; \
		echo "Updated readme.txt: 'Tested up to: $$current' -> '$$version'"; \
	fi

# --- Packaging -------------------------------------------------------------

# Regenerates the translation template from the current source. Run this before
# a release: strings added since the last run are otherwise untranslatable, and
# the header carries a stale version and licence.
.PHONY: pot
pot:
	$(WPCLI_FILES) i18n make-pot \
	  $(PLUGIN_PATH_IN_CONTAINER) \
	  $(PLUGIN_PATH_IN_CONTAINER)/languages/members-for-kofi.pot \
	  --exclude=vendor,tests,node_modules,dist,bin,reports \
	  --domain=members-for-kofi
	@echo "Strings: $$(grep -c '^msgid' languages/members-for-kofi.pot) in languages/members-for-kofi.pot"

# Compiles the bundled translations (languages/*.po) into the .mo files
# WordPress loads. Run it after editing a .po file.
.PHONY: mo
mo:
	$(WPCLI_FILES) i18n make-mo languages

PLUGIN_SLUG:=members-for-kofi
MAIN_FILE:=members-for-kofi.php
VERSION:=$(shell grep -E '^ \* Version:' $(MAIN_FILE) | awk '{print $$3}')
ZIP_NAME:=$(PLUGIN_SLUG)-$(VERSION).zip
GIT_BRANCH:=$(shell git rev-parse --abbrev-ref HEAD 2>/dev/null)
OUT_DIR?=/tmp
STAGE_DIR:=$(OUT_DIR)/$(PLUGIN_SLUG)-stage
ZIP_FULL:=$(OUT_DIR)/$(ZIP_NAME)

.PHONY: version
version:
	@echo "$(VERSION)"

.PHONY: ensure-main
ensure-main:
	@if [ "$(GIT_BRANCH)" != "main" ]; then echo "Current branch $(GIT_BRANCH) is not 'main' – aborting."; exit 1; fi
	@if ! git diff --quiet || ! git diff --cached --quiet; then echo "Working tree not clean – commit or stash changes first."; exit 1; fi
	@echo "On main with clean working tree."

.PHONY: release

release: .releaseignore
	@echo "Packaging $(PLUGIN_SLUG) version $(VERSION) -> $(ZIP_FULL)"
	rm -rf $(STAGE_DIR) $(ZIP_FULL) $(ZIP_NAME) $(PLUGIN_SLUG).zip
	mkdir -p $(STAGE_DIR)
	rsync -a --exclude-from='.releaseignore' ./ $(STAGE_DIR)/$(PLUGIN_SLUG)/
	# Build a production-only vendor/ in the staging dir. composer.json is left
	# in place afterwards: WordPress.org's Plugin Check flags a vendor/ directory
	# with no composer.json as unexplained third-party code.
	# The working tree's
	# vendor/ holds PHPUnit, PHPCS and friends and is excluded from the copy --
	# shipping it bloated the package and put the test toolchain in every
	# release artifact.
	@if [ -f composer.json ]; then \
	  cp composer.json $(STAGE_DIR)/$(PLUGIN_SLUG)/; \
	  [ -f composer.lock ] && cp composer.lock $(STAGE_DIR)/$(PLUGIN_SLUG)/ || true; \
	  cd $(STAGE_DIR)/$(PLUGIN_SLUG) && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader; \
	  rm -f $(STAGE_DIR)/$(PLUGIN_SLUG)/composer.lock; \
	else \
	  echo "ERROR: composer.json missing - cannot build a production vendor/"; exit 1; \
	fi
	@if [ ! -f $(STAGE_DIR)/$(PLUGIN_SLUG)/vendor/autoload.php ]; then \
	  echo "ERROR: vendor/autoload.php missing from the package"; exit 1; \
	fi
	# Ensure stable tag consistency
	@if ! grep -q "Stable tag: $(VERSION)" readme.txt; then \
		echo "WARNING: Stable tag mismatch in readme.txt (expected $(VERSION))"; \
	fi
	# Ensure "Tested up to" reflects the WordPress version we actually tested against
	@tested=$$($(TEST_SITE_RUNNING) && $(WPCLI_SITE) core version 2>/dev/null | tr -d '\r\n' | cut -d. -f1,2); \
	declared=$$(grep -E '^Tested up to:' readme.txt | sed -E 's/^Tested up to:[[:space:]]*//'); \
	if [ -n "$$tested" ] && [ "$$tested" != "$$declared" ]; then \
		echo "WARNING: readme.txt says 'Tested up to: $$declared' but the test environment runs $$tested. Run 'make tested-up-to'."; \
	fi
	cd $(STAGE_DIR) && zip -rq $(ZIP_FULL) $(PLUGIN_SLUG)
	rm -rf $(STAGE_DIR)
	@echo "Created artifact: $(ZIP_FULL)"

# --- Local WordPress test site (manual QA) ---

site-pull:
	docker compose -f docker-compose.site.yml pull wordpress wpcli

site-up: site-pull
	chmod +x bin/site-init.sh || true
	docker compose -f docker-compose.site.yml up -d db wordpress
	bash bin/site-init.sh
	@echo "Note: if WordPress core version is persisted in volume, run 'make site-reset' to recreate with latest image files."

site-shell:
	docker compose -f docker-compose.site.yml run --rm wpcli bash

site-down:
	docker compose -f docker-compose.site.yml down

site-reset: site-down site-pull
	docker compose -f docker-compose.site.yml down -v
	docker compose -f docker-compose.site.yml up -d db wordpress
	bash bin/site-init.sh

# Runs WordPress.org's own Plugin Check against the packaged plugin, in a
# disposable WordPress that mounts no part of this repository. Checking the
# working tree would report on files that never ship, and letting WordPress
# install plugins into a bind-mounted repo would overwrite it.
.PHONY: plugin-check
plugin-check: release
	@set -e; \
	dir=$$(mktemp -d); \
	trap 'docker compose -p kofi-plugin-check -f $$dir/docker-compose.yml down -v >/dev/null 2>&1; rm -rf $$dir' EXIT; \
	mkdir -p $$dir/artifact; \
	cp $(ZIP_FULL) $$dir/artifact/; \
	printf 'services:\n  db:\n    image: $(DB_IMAGE)\n    environment:\n      MYSQL_DATABASE: wordpress\n      MYSQL_USER: wp\n      MYSQL_PASSWORD: wp\n      MYSQL_ROOT_PASSWORD: wp\n    healthcheck:\n      test: ["CMD-SHELL","healthcheck.sh --connect --innodb_initialized 2>/dev/null || mysqladmin ping -h 127.0.0.1 --silent"]\n      interval: 5s\n      retries: 30\n  wordpress:\n    image: wordpress:php$(PHP_VERSION)-apache\n    depends_on:\n      db: {condition: service_healthy}\n    environment:\n      WORDPRESS_DB_HOST: db:3306\n      WORDPRESS_DB_USER: wp\n      WORDPRESS_DB_PASSWORD: wp\n      WORDPRESS_DB_NAME: wordpress\n    volumes:\n      - wp:/var/www/html\n      - %s/artifact:/artifact:ro\n  wpcli:\n    image: wordpress:cli-php$(PHP_VERSION)\n    depends_on: [wordpress]\n    user: "33:33"\n    environment:\n      WORDPRESS_DB_HOST: db:3306\n      WORDPRESS_DB_USER: wp\n      WORDPRESS_DB_PASSWORD: wp\n      WORDPRESS_DB_NAME: wordpress\n    working_dir: /var/www/html\n    volumes:\n      - wp:/var/www/html\n      - %s/artifact:/artifact:ro\nvolumes:\n  wp:\n' $$dir $$dir > $$dir/docker-compose.yml; \
	C="docker compose -p kofi-plugin-check -f $$dir/docker-compose.yml"; \
	echo "Booting a disposable WordPress for Plugin Check..."; \
	$$C up -d db wordpress >/dev/null 2>&1; \
	for i in $$(seq 1 40); do $$C exec -T wordpress test -f /var/www/html/wp-settings.php >/dev/null 2>&1 && break; sleep 2; done; \
	$$C run --rm -T wpcli wp core install --url=http://localhost --title=check --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email >/dev/null 2>&1; \
	$$C run --rm -T wpcli wp plugin install /artifact/$(ZIP_NAME) --activate >/dev/null 2>&1; \
	$$C run --rm -T wpcli wp plugin install plugin-check --activate >/dev/null 2>&1; \
	echo ""; \
	$$C run --rm -T wpcli wp plugin check $(PLUGIN_SLUG) 2>/dev/null || true

# --- Help ------------------------------------------------------------------

.DEFAULT_GOAL := help

.PHONY: help
help:
	@echo "Members for Ko-fi $(VERSION)"
	@echo ""
	@echo "Testing"
	@echo "  test                 Run the WordPress-loaded suite inside the container"
	@echo "  test-case TEST=Name  Run tests matching a filter"
	@echo "  test-integration     Drive the site over real HTTP, as Ko-fi does"
	@echo "  test-all             Both suites"
	@echo "  test-shell           Shell inside the WP-CLI container, where PHPUnit runs"
	@echo ""
	@echo "Test environment (newest WordPress, or WP_VERSION=x.y; disposable, in RAM)"
	@echo "  (test targets stop the site again if they started it; KEEP_TEST_ENV=1 keeps it)"
	@echo "  test-env-up          Boot and provision the test site, and leave it running"
	@echo "  test-env-down        Stop it"
	@echo "  test-env-status      Show test containers still running and volumes left behind"
	@echo "  test-env-clean       Remove containers, volumes and the test images"
	@echo "  test-env-pull        Refresh the pinned images (PHP_VERSION, DB_IMAGE)"
	@echo "  test-env-reset       Wipe the site and database, then rebuild (leaves it running)"
	@echo "  wp-version           WordPress version currently under test"
	@echo "  tested-up-to         Sync readme.txt 'Tested up to' with that version"
	@echo ""
	@echo "Local QA site (separate from the test environment)"
	@echo "  site-up / site-down / site-shell / site-reset"
	@echo ""
	@echo "Packaging"
	@echo "  version              Print the version from the plugin header"
	@echo "  release              Build a production zip (dev files and dev vendor excluded)"
	@echo "                       Override the output directory with OUT_DIR=/some/path"
	@echo "  pot                  Regenerate the translation template from source"
	@echo "  plugin-check         Run WordPress.org's Plugin Check against the built package"
	@echo ""
	@if [ -f Makefile.local ]; then \
	  echo "Publishing (from Makefile.local)"; \
	  echo "  git-tag              Tag v$(VERSION) and push it (clean main only)"; \
	  echo "  github-release       Package, tag and publish a GitHub release"; \
	  echo "  deploy-svn           Stage a WordPress.org SVN release for review"; \
	  echo "  commit-svn           Commit the staged SVN release"; \
	else \
	  echo "Publishing targets are not loaded (no Makefile.local present)."; \
	fi

# Publishing targets need push rights or WordPress.org credentials, so they are
# kept out of the repository. Optional: make works without this file.
-include Makefile.local
