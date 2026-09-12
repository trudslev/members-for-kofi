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
WP_TEST_PORT?=8101
PLUGIN_PATH_IN_CONTAINER:=/var/www/html/wp-content/plugins/members-for-kofi

# Boots a throwaway site running the newest WordPress and provisions it
# (installs WP, activates the plugin, sets permalinks and the test token).
.PHONY: test-env-pull
test-env-pull:
	@echo "Pulling newest WordPress image..."
	$(TEST_COMPOSE) pull --quiet wordpress wpcli db

.PHONY: test-env-up
test-env-up: vendor test-env-pull
	@echo "Starting WordPress test environment..."
	$(TEST_COMPOSE) up -d db wordpress
	bash bin/test-env-init.sh

.PHONY: test-env-down
test-env-down:
	$(TEST_COMPOSE) down

# Full wipe: discards the WordPress install and database.
.PHONY: test-env-reset
test-env-reset:
	$(TEST_COMPOSE) down -v
	$(MAKE) test-env-up

vendor:
	@[ -d vendor ] || composer install

.PHONY: test
test: test-env-up
	@echo "Running test suite inside the WordPress container..."
	$(TEST_COMPOSE) exec -T -w $(PLUGIN_PATH_IN_CONTAINER) wordpress ./vendor/bin/phpunit

.PHONY: test-case
test-case: test-env-up
	@echo "Running tests matching '$(TEST)'..."
	$(TEST_COMPOSE) exec -T -w $(PLUGIN_PATH_IN_CONTAINER) wordpress ./vendor/bin/phpunit --filter '$(TEST)'

# Drives the local site over real HTTP, exactly as Ko-fi would.
.PHONY: test-integration
test-integration: test-env-up
	@echo "Running HTTP integration tests against http://localhost:$(WP_TEST_PORT)..."
	WP_TEST_SITE_URL=http://localhost:$(WP_TEST_PORT) ./vendor/bin/phpunit --configuration phpunit-integration.xml

.PHONY: test-all
test-all: test test-integration

.PHONY: test-shell
test-shell:
	$(TEST_COMPOSE) exec -w $(PLUGIN_PATH_IN_CONTAINER) wordpress bash

# Reports the WordPress version the test environment is actually running.
.PHONY: wp-version
wp-version:
	@$(TEST_COMPOSE) run --rm -T wpcli wp core version 2>/dev/null | tr -d '\r\n'
	@echo

# Syncs readme.txt's "Tested up to:" with the version we just tested against.
.PHONY: tested-up-to
tested-up-to: test-env-up
	@version=$$($(TEST_COMPOSE) run --rm -T wpcli wp core version 2>/dev/null | tr -d '\r\n'); \
	if [ -z "$$version" ]; then echo "ERROR: could not determine WordPress version from the test environment."; exit 1; fi; \
	current=$$(grep -E '^Tested up to:' readme.txt | sed -E 's/^Tested up to:[[:space:]]*//'); \
	if [ "$$current" = "$$version" ]; then \
		echo "readme.txt already says 'Tested up to: $$version'"; \
	else \
		sed -i -E "s/^Tested up to:.*/Tested up to: $$version/" readme.txt; \
		echo "Updated readme.txt: 'Tested up to: $$current' -> '$$version'"; \
	fi

# --- Packaging -------------------------------------------------------------

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
	# Build a production-only vendor/ in the staging dir. The working tree's
	# vendor/ holds PHPUnit, PHPCS and friends and is excluded from the copy --
	# shipping it bloated the package and put the test toolchain in every
	# release artifact.
	@if [ -f composer.json ]; then \
	  cp composer.json $(STAGE_DIR)/$(PLUGIN_SLUG)/; \
	  [ -f composer.lock ] && cp composer.lock $(STAGE_DIR)/$(PLUGIN_SLUG)/ || true; \
	  cd $(STAGE_DIR)/$(PLUGIN_SLUG) && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader; \
	  rm -f $(STAGE_DIR)/$(PLUGIN_SLUG)/composer.json $(STAGE_DIR)/$(PLUGIN_SLUG)/composer.lock; \
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
	@tested=$$($(TEST_COMPOSE) run --rm -T wpcli wp core version 2>/dev/null | tr -d '\r\n'); \
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
	@echo "  test-shell           Shell inside the WordPress container"
	@echo ""
	@echo "Test environment (newest WordPress, disposable)"
	@echo "  test-env-up          Boot and provision the test site"
	@echo "  test-env-down        Stop it"
	@echo "  test-env-reset       Wipe the site and database, then rebuild"
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
