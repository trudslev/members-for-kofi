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
