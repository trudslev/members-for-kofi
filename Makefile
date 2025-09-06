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

release: .releaseignore
	@echo "Creating production release..."
	rm -rf build members-for-kofi.zip
	mkdir build
	# Sync source (excluding dev artifacts & vendor)
	rsync -av --exclude-from='.releaseignore' ./ build/
	# Bring in composer metadata temporarily for install
	cp composer.json build/
	@[ -f composer.lock ] && cp composer.lock build/ || true
	# Install only production dependencies (none currently) to generate optimized autoloader
	cd build && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
	# Remove composer metadata from final package
	rm -f build/composer.json build/composer.lock
	cd build && zip -rq ../members-for-kofi.zip .
	rm -rf build
	@echo "Release created: members-for-kofi.zip (production only, no dev deps)"

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
