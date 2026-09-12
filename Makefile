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

PLUGIN_SLUG:=members-for-kofi
MAIN_FILE:=members-for-kofi.php
VERSION:=$(shell grep -E '^ \* Version:' $(MAIN_FILE) | awk '{print $$3}')
SVN_URL:=https://plugins.svn.wordpress.org/$(PLUGIN_SLUG)
SVN_DIR:=/tmp/$(PLUGIN_SLUG)-svn
ZIP_NAME:=$(PLUGIN_SLUG)-$(VERSION).zip
GIT_BRANCH:=$(shell git rev-parse --abbrev-ref HEAD 2>/dev/null)
WPORG_USER?=
WPORG_PASS?=
SVN_COMMIT_NON_INTERACTIVE?=0
OUT_DIR?=/tmp
STAGE_DIR:=$(OUT_DIR)/$(PLUGIN_SLUG)-stage
ZIP_FULL:=$(OUT_DIR)/$(ZIP_NAME)

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

# Create and push git tag (v<version>) – only on main
.PHONY: git-tag
git-tag: ensure-main
	@if git rev-parse -q --verify refs/tags/v$(VERSION) >/dev/null; then echo "Tag v$(VERSION) already exists"; exit 1; fi
	@if ! grep -q "Stable tag: $(VERSION)" readme.txt; then echo "Stable tag mismatch in readme.txt (expected $(VERSION))"; exit 1; fi
	git tag -a v$(VERSION) -m "Release $(VERSION)"
	git push origin v$(VERSION)
	@echo "Created and pushed tag v$(VERSION)"

# Full release pipeline: package + git tag (main only)
.PHONY: full-release
full-release: release git-tag
	@echo "Full release (package + tag) complete for $(VERSION)"

# Optional GitHub release (requires gh CLI & authenticated). Uses zip built by release.
.PHONY: github-release
github-release: release git-tag
	@if ! command -v gh >/dev/null; then echo 'gh CLI not installed – skipping GitHub release.'; exit 0; fi
	@if gh release view v$(VERSION) >/dev/null 2>&1; then echo 'GitHub release already exists for v$(VERSION)'; exit 0; fi
	@echo "Creating GitHub release v$(VERSION)"
	gh release create v$(VERSION) $(ZIP_FULL) --title "v$(VERSION)" --notes "Release $(VERSION)"
	@echo "GitHub release v$(VERSION) published."

.PHONY: deploy-svn
deploy-svn: release
	@echo "Deploying $(PLUGIN_SLUG) $(VERSION) to WordPress.org SVN (production vendor only)"
	@if [ -z "$(shell command -v svn)" ]; then echo 'svn not found'; exit 1; fi
	rm -rf $(SVN_DIR)
	svn checkout $(SVN_URL) $(SVN_DIR)
	# Prepare clean trunk source (without local vendor or dev files)
	rsync -a --delete --exclude-from='.releaseignore' ./ $(SVN_DIR)/trunk/
	# Build production autoloader inside trunk
	@if [ -f composer.json ]; then \
	  cp composer.json $(SVN_DIR)/trunk/; \
	  [ -f composer.lock ] && cp composer.lock $(SVN_DIR)/trunk/ || true; \
	  cd $(SVN_DIR)/trunk && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader; \
	  rm -f $(SVN_DIR)/trunk/composer.json $(SVN_DIR)/trunk/composer.lock; \
	fi
	# Copy WordPress.org assets to /assets (not inside trunk)
	@if [ -d .wordpress-org/assets ]; then \
		mkdir -p $(SVN_DIR)/assets; \
		rsync -a .wordpress-org/assets/ $(SVN_DIR)/assets/; \
	fi
	# SVN adds
	cd $(SVN_DIR) && svn update && svn add --force trunk/* > /dev/null 2>&1 || true
	cd $(SVN_DIR) && if [ -d assets ]; then svn add --force assets/* > /dev/null 2>&1 || true; fi
	# Recreate tag from trunk
	cd $(SVN_DIR) && svn rm tags/$(VERSION) > /dev/null 2>&1 || true
	cd $(SVN_DIR) && svn copy trunk tags/$(VERSION)
	cd $(SVN_DIR) && svn add --force tags/$(VERSION) > /dev/null 2>&1 || true
	cd $(SVN_DIR) && svn stat
	@echo "Review svn status. If correct: make commit-svn"

.PHONY: commit-svn
commit-svn:
	@echo "Committing to WordPress.org SVN..."
	@if [ ! -d $(SVN_DIR) ]; then echo 'Run make deploy-svn first'; exit 1; fi
	cd $(SVN_DIR) && \
	USER_ARG="" && PASS_ARG="" && NI_ARGS="" && \
	if [ -n "$(WPORG_USER)" ]; then USER_ARG="--username $(WPORG_USER)"; fi; \
	if [ -n "$(WPORG_PASS)" ]; then PASS_ARG="--password $(WPORG_PASS) --no-auth-cache"; fi; \
	if [ "$(SVN_COMMIT_NON_INTERACTIVE)" = "1" ]; then NI_ARGS="--non-interactive"; fi; \
	echo "svn commit using $$USER_ARG $$NI_ARGS"; \
	svn commit $$USER_ARG $$PASS_ARG $$NI_ARGS -m "Release $(VERSION)" || true
	@echo "Done."

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

# ---------------- Release / Distribution Extras ----------------

# Infer plugin version from main plugin header unless explicitly passed: make release VERSION=1.2.3
PLUGIN_MAIN ?= members-for-kofi.php
VERSION ?= $(shell grep -E '^ \* Version:' $(PLUGIN_MAIN) | awk '{print $$3}')
SLUG ?= members-for-kofi
WP_SVN_URL ?= https://plugins.svn.wordpress.org/$(SLUG)
TMP_SVN_DIR ?= /tmp/$(SLUG)-svn

.PHONY: version tag dist svn-checkout svn-stage svn-tag svn-deploy help

version:
	@echo "Detected version: $(VERSION)"

tag: version
	@git rev-parse --is-inside-work-tree >/dev/null 2>&1 || (echo "Not a git repo" && exit 1)
	@if git rev-parse "v$(VERSION)" >/dev/null 2>&1; then echo "Tag v$(VERSION) already exists"; else \
	  echo "Creating git tag v$(VERSION)"; \
	  git tag -a v$(VERSION) -m "Release v$(VERSION)"; \
	  git push origin v$(VERSION); \
	fi

# Create an unpacked production-ready directory in ./dist (not zipped)
dist: .releaseignore
	@echo "Building dist directory (production files)..."
	rm -rf dist
	mkdir dist
	rsync -av --exclude-from='.releaseignore' ./ dist/
	cp composer.json dist/ 2>/dev/null || true
	@[ -f composer.lock ] && cp composer.lock dist/ || true
	cd dist && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
	rm -f dist/composer.json dist/composer.lock
	@echo "Dist directory ready at ./dist"

svn-checkout:
	@echo "Checking out (or updating) SVN working copy at $(TMP_SVN_DIR)"
	@if [ -d "$(TMP_SVN_DIR)/.svn" ]; then \
	  svn update $(TMP_SVN_DIR); \
	else \
	  rm -rf $(TMP_SVN_DIR); \
	  svn checkout --depth immediates $(WP_SVN_URL) $(TMP_SVN_DIR); \
	  svn update $(TMP_SVN_DIR)/trunk $(TMP_SVN_DIR)/tags; \
	fi

# Stage new trunk contents (does not commit). Depends on dist.
svn-stage: dist svn-checkout
	@echo "Staging dist contents into SVN trunk"
	rm -rf $(TMP_SVN_DIR)/trunk/*
	cp -R dist/* $(TMP_SVN_DIR)/trunk/
	cd $(TMP_SVN_DIR) && svn add --force trunk/* >/dev/null 2>&1 || true
	cd $(TMP_SVN_DIR) && svn status
	@echo "Run 'make svn-tag' to copy trunk to tags/$(VERSION) then 'make svn-deploy' to commit."

svn-tag: svn-stage
	@echo "Copying trunk to tag directory $(VERSION)"
	cd $(TMP_SVN_DIR) && \
	  if [ -d tags/$(VERSION) ]; then echo "Tag $(VERSION) already exists in SVN"; else svn copy trunk tags/$(VERSION); fi
	cd $(TMP_SVN_DIR) && svn status

svn-deploy:
	@echo "Committing trunk (and tag if present) to WordPress.org SVN"
	cd $(TMP_SVN_DIR) && svn commit -m "Release $(VERSION)" || true
	@echo "If authentication failed, rerun 'make svn-deploy' after caching credentials."

help:
	@echo "Available targets:"
	@echo "  build / test / test-case               - CI & testing"
	@echo "  release                                - Create production zip (no composer.json)"
	@echo "  dist                                   - Create production directory for SVN"
	@echo "  version                                - Show inferred version"
	@echo "  tag                                    - Create & push git tag v$(VERSION)"
	@echo "  svn-checkout                           - Checkout/update WP.org SVN working copy"
	@echo "  svn-stage                              - Copy dist into SVN trunk"
	@echo "  svn-tag                                - Copy trunk to tags/$(VERSION)"
	@echo "  svn-deploy                             - Commit staged changes to SVN"
	@echo "Variables (override with VAR=value): VERSION ($(VERSION)), SLUG ($(SLUG)), WP_SVN_URL ($(WP_SVN_URL))"

# ---------------- Release / Distribution Extras ----------------

# Infer plugin version from main plugin header unless explicitly passed: make release VERSION=1.2.3
PLUGIN_MAIN ?= members-for-kofi.php
VERSION ?= $(shell grep -E '^ \* Version:' $(PLUGIN_MAIN) | awk '{print $$3}')
SLUG ?= members-for-kofi
WP_SVN_URL ?= https://plugins.svn.wordpress.org/$(SLUG)
TMP_SVN_DIR ?= /tmp/$(SLUG)-svn

.PHONY: version tag dist svn-checkout svn-stage svn-tag svn-deploy help

version:
	@echo "Detected version: $(VERSION)"

tag: version
	@git rev-parse --is-inside-work-tree >/dev/null 2>&1 || (echo "Not a git repo" && exit 1)
	@if git rev-parse "v$(VERSION)" >/dev/null 2>&1; then echo "Tag v$(VERSION) already exists"; else \
	  echo "Creating git tag v$(VERSION)"; \
	  git tag -a v$(VERSION) -m "Release v$(VERSION)"; \
	  git push origin v$(VERSION); \
	fi

# Create an unpacked production-ready directory in ./dist (not zipped)
dist: .releaseignore
	@echo "Building dist directory (production files)..."
	rm -rf dist
	mkdir dist
	rsync -av --exclude-from='.releaseignore' ./ dist/
	cp composer.json dist/ 2>/dev/null || true
	@[ -f composer.lock ] && cp composer.lock dist/ || true
	cd dist && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
	rm -f dist/composer.json dist/composer.lock
	@echo "Dist directory ready at ./dist"

svn-checkout:
	@echo "Checking out (or updating) SVN working copy at $(TMP_SVN_DIR)"
	@if [ -d "$(TMP_SVN_DIR)/.svn" ]; then \
	  svn update $(TMP_SVN_DIR); \
	else \
	  rm -rf $(TMP_SVN_DIR); \
	  svn checkout --depth immediates $(WP_SVN_URL) $(TMP_SVN_DIR); \
	  svn update $(TMP_SVN_DIR)/trunk $(TMP_SVN_DIR)/tags; \
	fi

# Stage new trunk contents (does not commit). Depends on dist.
svn-stage: dist svn-checkout
	@echo "Staging dist contents into SVN trunk"
	rm -rf $(TMP_SVN_DIR)/trunk/*
	cp -R dist/* $(TMP_SVN_DIR)/trunk/
	cd $(TMP_SVN_DIR) && svn add --force trunk/* >/dev/null 2>&1 || true
	cd $(TMP_SVN_DIR) && svn status
	@echo "Run 'make svn-tag' to copy trunk to tags/$(VERSION) then 'make svn-deploy' to commit."

svn-tag: svn-stage
	@echo "Copying trunk to tag directory $(VERSION)"
	cd $(TMP_SVN_DIR) && \
	  if [ -d tags/$(VERSION) ]; then echo "Tag $(VERSION) already exists in SVN"; else svn copy trunk tags/$(VERSION); fi
	cd $(TMP_SVN_DIR) && svn status

svn-deploy:
	@echo "Committing trunk (and tag if present) to WordPress.org SVN"
	cd $(TMP_SVN_DIR) && svn commit -m "Release $(VERSION)" || true
	@echo "If authentication failed, rerun 'make svn-deploy' after caching credentials."

help:
	@echo "Available targets:"
	@echo "  build / test / test-case               - CI & testing"
	@echo "  release                                - Create production zip (no composer.json)"
	@echo "  dist                                   - Create production directory for SVN"
	@echo "  version                                - Show inferred version"
	@echo "  tag                                    - Create & push git tag v$(VERSION)"
	@echo "  svn-checkout                           - Checkout/update WP.org SVN working copy"
	@echo "  svn-stage                              - Copy dist into SVN trunk"
	@echo "  svn-tag                                - Copy trunk to tags/$(VERSION)"
	@echo "  svn-deploy                             - Commit staged changes to SVN"
	@echo "Variables (override with VAR=value): VERSION ($(VERSION)), SLUG ($(SLUG)), WP_SVN_URL ($(WP_SVN_URL))"
