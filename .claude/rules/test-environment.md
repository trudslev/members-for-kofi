---
paths:
  - "Makefile*"
  - "docker-compose*.yml"
  - "bin/**"
  - "phpunit*.xml"
  - "tests/bootstrap*.php"
  - "tests/TestCase.php"
  - "tests/Integration/IntegrationTestCase.php"
---

# Test environment and Makefile

- `docker-compose.test.yml` (WordPress + MySQL + WP-CLI) is provisioned by `bin/test-env-init.sh`:
  installs WordPress, activates the plugin, flushes permalinks, configures the options and test token
- `wordpress:latest` is pulled on every `make test-env-up`, so the suite tracks core releases.
  When the site's volume outlives a run, core lives in the volume and the image never refreshes it,
  so `test-env-init.sh` copies core over from the image whenever the versions differ. Without that
  the suite silently stays on the version the volume was created with
- `make test` runs inside the WordPress container (`tests/bootstrap.php` loads `wp-load.php`);
  `make test-integration` runs on the host against `http://localhost:8101`
- Test targets (`test`, `test-case`, `test-integration`, `test-all`, `tested-up-to`) go through
  `bin/with-test-env.sh`: containers it started are stopped afterwards, also on failure or Ctrl-C,
  and volumes it had to create are removed (`down -v`). Started by `make test-env-up` or with
  `KEEP_TEST_ENV=1`, they stay up. `make test-env-down` keeps volumes; `make test-env-clean`
  removes containers, volumes and the three test images; `make test-env-pull` removes images a newer
  pull superseded
- A new target that needs the running site is wrapped in `$(WITH_TEST_ENV)` with an underscore inner
  target, like the existing ones. Never add `test-env-up` as a prerequisite
- `pot` and `mo` run WP-CLI against this checkout alone (`WPCLI_FILES`). Anything run through the
  compose `wpcli` service mounts the site volume and creates it as a side effect
- Targets that only read files (`wp-version`, the "Tested up to" check in `release`) use
  `WPCLI_NO_SITE` (`docker compose run --no-deps`). A plain `run wpcli` starts WordPress and MySQL as
  dependencies and leaves them running; `make release` used to leave two containers up that way
- **Memory caps** (`mem_limit`/`memswap_limit`): WordPress 768 MB (PHPUnit runs inside it), MySQL
  512 MB with a lean configuration, WP-CLI 256 MB. `with-test-env.sh` refuses to start with less
  than 1.5 GB available (`TEST_ENV_MIN_FREE_MB` overrides). On 2026-10-31 the uncapped stack tipped
  this shared server into swapping until it froze and had to be rebooted. A full `make test-all`
  peaks around 270 MB per container. Never remove the caps; raise them only with a measured reason
- **Publish ports on loopback only: `"127.0.0.1:8101:80"`, never `"8101:80"`.** Docker otherwise
  publishes on every interface and its NAT rules bypass ufw. Until 2026-10-11 the test site
  (`admin`/`admin`, this repository mounted read-write) answered on the public address. Same for
  `docker-compose.site.yml` and any rehearsal compose file
- A throwaway compose project made for a rehearsal is torn down with `down -v` before work is done
- `docker-compose.site.yml` is the manual QA site: `make site-up`, `site-shell`, `site-down`,
  `site-reset`
