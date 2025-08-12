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
	@echo "Creating release..."
	rm -rf release
	mkdir release
	rsync -av --exclude-from='.releaseignore' ./ release/
	cd release && zip -r ../members-for-kofi.zip .
	rm -rf release
	@echo "Release created: members-for-kofi.zip"

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
