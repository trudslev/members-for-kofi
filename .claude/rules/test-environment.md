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

The plugin is open source: the suite needs nothing but Docker. No host paths, no secrets, and never a
dependency on a test environment shared by other projects on one machine.

- `docker-compose.test.yml` has two layers. `db` (`DB_IMAGE`, default `mariadb:11.4`) and `wpcli`
  (`wordpress:cli-php${PHP_VERSION}`, default 8.3) always run; PHPUnit runs inside `wpcli`. The
  Apache `wordpress` service is in the `http` profile and starts only for suites that need a real
  site (`TEST_ENV_HTTP=1`: `test-integration`, `test-all`, `test-env-up`)
- Everything is in RAM: the database datadir is a tmpfs, and the docroot is a tmpfs named volume
  (`wp_test_core`) shared by `wpcli` and `wordpress`. Each start is a fresh site;
  `bin/test-env-init.sh` provisions it in one `exec` (core download, config, install, plugin, options,
  permalinks, `.htaccess`). Each `docker compose exec` costs most of a second, so batch steps
- WordPress core comes from `wp core download --version=${WP_VERSION:-latest}`, not from an image, so
  the suite follows core releases without pulls. Images are pinned and never pulled on a normal run;
  `make test-env-pull` refreshes them and removes the ones it superseded
- `wpcli` runs as 33:33 (Apache's www-data), and the volume is created owned by 33. Docker creates
  `wp-content` and `wp-content/plugins` (above the plugin's bind mount) as root, so init chowns those
  two, never recursively: the working tree is mounted below them
- PHPUnit runs as www-data, so it cannot read a developer's private `.env`; tests load `.env` only
  `is_readable()`. Its result cache goes to the container's `/tmp`, never into the working tree
- `make test` runs `php ./vendor/bin/phpunit` in `wpcli`; `make test-integration` runs on the host
  against `http://localhost:8101`. Integration tests talk to the site with `docker compose exec -T wpcli
  wp …` and to the database with whichever client the image has (`mariadb` or `mysql`)
- Test targets go through `bin/with-test-env.sh`: what it started is stopped afterwards, also on
  failure or Ctrl-C, and volumes it created are removed (`down -v`). If only the web server was missing,
  only it is stopped. Started by `make test-env-up` or with `KEEP_TEST_ENV=1`, they stay up.
  `make test-env-clean` removes containers, volumes and the pinned images
- A new target that needs the running site is wrapped in `$(WITH_TEST_ENV)` with an underscore inner
  target, like the existing ones; prefix it with `TEST_ENV_HTTP=1` if it needs HTTP. Never add
  `test-env-up` as a prerequisite. Compose commands that must see every container use
  `$(TEST_COMPOSE_ALL)` (with `--profile http`)
- `pot` and `mo` run WP-CLI against this checkout alone (`WPCLI_FILES`), without the test site.
  `wp-version`, `tested-up-to` and the "Tested up to" check in `release` read the running site
- **Memory caps** (`mem_limit`/`memswap_limit`): database 384 MB, `wpcli` 384 MB (PHPUnit), web server
  256 MB. `with-test-env.sh` refuses to start with less than 1 GB available (`TEST_ENV_MIN_FREE_MB`
  overrides), so the stack cannot push a machine it shares with other services into swap. The tmpfs
  data is charged to the containers: measured peaks are about 200 MB for each of `db` and `wpcli`
  (MySQL 8.4: ~350 MB for `db`). Never remove the caps; raise them only with a measured reason
- **Publish ports on loopback only: `"127.0.0.1:8101:80"`, never `"8101:80"`.** Docker otherwise
  publishes on every interface and its NAT rules bypass the host firewall, which would expose a
  site whose login is `admin`/`admin` and which mounts this repository read-write. Same for
  `docker-compose.site.yml` and any rehearsal compose file
- A throwaway compose project made for a rehearsal is torn down with `down -v` before work is done
- `docker-compose.site.yml` is the manual QA site: `make site-up`, `site-shell`, `site-down`,
  `site-reset`
