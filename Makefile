build: .env
	@echo "Building Docker test container..."
	docker compose build phpunit
	@touch .build.stamp

test: .build.stamp 
	@echo "Running all tests..."
	docker compose up -d db
	docker compose run --rm phpunit || true
	docker compose down

test-case: .build.stamp
	@echo "Running specific test case..."
	docker compose up -d db
	docker compose run --rm phpunit --filter $(TEST) || true
	docker compose down

.build.stamp: Dockerfile.test docker-compose.yml .env
	@echo "Rebuilding due to updated dependency..."
	docker compose build phpunit
	@touch .build.stamp

rebuild:
	docker compose build --no-cache phpunit
	@touch .build.stamp

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
	# Ensure stable tag consistency
	@if ! grep -q "Stable tag: $(VERSION)" readme.txt; then \
		echo "WARNING: Stable tag mismatch in readme.txt (expected $(VERSION))"; \
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
	@echo "Deploying $(PLUGIN_SLUG) $(VERSION) to WordPress.org SVN"
	@if [ -z "$(shell command -v svn)" ]; then echo 'svn not found'; exit 1; fi
	rm -rf $(SVN_DIR)
	svn checkout $(SVN_URL) $(SVN_DIR)
	# Copy trunk
	rsync -a --delete --exclude-from='.releaseignore' ./ $(SVN_DIR)/trunk/
	# Copy WordPress.org assets (from .wordpress-org/assets or legacy assets-wporg) to /assets (outside trunk)
	@if [ -d .wordpress-org/assets ]; then \
		mkdir -p $(SVN_DIR)/assets; \
		rsync -a .wordpress-org/assets/ $(SVN_DIR)/assets/; \
	fi
	# Tag
	cd $(SVN_DIR) && svn update && svn add --force trunk/* > /dev/null 2>&1 || true
	# Ensure assets are versioned
	cd $(SVN_DIR) && if [ -d assets ]; then svn add --force assets/* > /dev/null 2>&1 || true; fi
	cd $(SVN_DIR) && svn rm $(SVN_DIR)/tags/$(VERSION) > /dev/null 2>&1 || true
	cd $(SVN_DIR) && mkdir -p tags/$(VERSION) && rsync -a trunk/ tags/$(VERSION)/
	cd $(SVN_DIR) && svn add --force tags/$(VERSION) > /dev/null 2>&1 || true
	cd $(SVN_DIR) && svn stat
	@echo "Review the above svn status. If it looks correct, run: make commit-svn"

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

site-up:
	chmod +x bin/site-init.sh || true
	docker compose -f docker-compose.site.yml up -d db wordpress
	bash bin/site-init.sh

site-shell:
	docker compose -f docker-compose.site.yml run --rm wpcli bash

site-down:
	docker compose -f docker-compose.site.yml down

site-reset: site-down
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
