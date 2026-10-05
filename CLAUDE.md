# Members for Ko-fi - AI Coding Agent Instructions

## Project Overview

This is a WordPress plugin (v1.3.1) that integrates with Ko-fi webhooks to automatically manage WordPress users and roles based on donation tiers. The plugin receives webhook payloads from Ko-fi, creates/updates WordPress users, assigns roles based on tier mappings, and manages role expiration. Features include automatic log cleanup, dual log viewing (User/Request), and organized admin settings.

## Architecture

### Core Components

- **`src/Plugin.php`**: Main orchestrator - initializes all components, registers hooks, handles rewrite rules for webhook endpoint, registers cron jobs
- **`src/Webhook/Webhook.php`**: Processes Ko-fi webhook payloads, validates verification tokens, creates users, assigns roles
- **`src/Admin/AdminSettings.php`**: Admin UI with tabbed interface (Settings/Logs), organized sections (Ko-fi Settings, Role Assignment, Logging), dual log viewer with AJAX pagination
- **`src/Cron/RoleExpiryChecker.php`**: Daily cron job to remove expired roles based on user metadata timestamps
- **`src/Cron/LogCleanup.php`**: Daily cron job to automatically delete old logs based on retention settings
- **`src/Logging/UserLogger.php`**: Database-backed user activity logger (custom table `wp_members_for_kofi_user_logs`)
- **`src/Logging/RequestLogger.php`**: Database-backed webhook request logger (custom table `wp_members_for_kofi_request_logs`) - logs all incoming webhook requests with success/failure status
- **`src/Logging/DebugLogger.php`**: Lightweight debug logger to PHP error_log, only active when `WP_DEBUG` is true
- **`src/Webhook/VerificationToken.php`**: All rules for the hashed verification token (see Database Tables)
- **`src/Privacy/PersonalData.php`**: Personal-data exporter/eraser for both log tables, suggested privacy-policy text, and the one-off clean-up of donor accounts created before 1.3.0

### Data Flow

1. Ko-fi webhook hits `https://your-site.com/webhook-kofi` (custom rewrite rule → query var `kofi_webhook=1`)
2. `Webhook::handle()` hashes the incoming token and compares it with `members_for_kofi_options['verification_token_sha256']`
3. Payload parsed, user created/updated, role assigned based on `tier_role_map` or `default_role`
4. User metadata `kofi_role_assigned_at` timestamp stored for expiry tracking
5. Daily cron (`kofi_members_check_expired_roles`) removes roles past `role_expiry_days` threshold
6. Separate daily cron (`kofi_members_cleanup_logs`) deletes logs older than `log_retention_days` when `auto_clear_logs` is enabled

## Security Standards

**Security is paramount.** This plugin MUST adhere to the highest WordPress security standards at all times. All code changes must be evaluated against these security requirements before implementation.

### Applicable Standards

This plugin follows these security standards and guidelines:

1. **WordPress Plugin Handbook - Security Best Practices**
   https://developer.wordpress.org/plugins/security/

2. **WordPress Coding Standards (WPCS)**
   Enforced via PHPCS with the `WordPress` ruleset

3. **OWASP Top 10 Web Application Security Risks**
   Special attention to: Injection, Broken Authentication, Security Misconfiguration, and Insecure Deserialization

4. **WordPress VIP Code Review Standards**
   Industry-leading security and performance practices

### Mandatory Security Practices

#### Input Validation & Sanitization

- **ALL external input MUST be sanitized**: Use `sanitize_text_field()`, `sanitize_email()`, `absint()`, `sanitize_key()`, etc.
- **Webhook payloads**: Sanitize recursively with `array_walk_recursive()` before processing
- **Always use `wp_unslash()`** when reading from `$_POST`, `$_GET`, `$_REQUEST` or `$_COOKIE`
- **Never use `wp_unslash()` on `php://input`**: WordPress only adds slashes to the superglobals, never
  to the raw request body. Unslashing it strips the escapes out of the payload itself — `\"` breaks the
  JSON parse outright, while `\\` and `\uXXXX` decode to silently corrupted text
- **Type validation**: Verify data types match expectations (string, int, array, etc.)
- **Whitelist validation**: For known values (roles, tiers), only allow expected values

#### Output Escaping

- **NEVER output unescaped data**: Use `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`, `wp_kses_post()`
- **Context-aware escaping**: Choose escape function based on output context (HTML, attribute, URL, JavaScript)
- **JSON output**: Use `wp_json_encode()` instead of `json_encode()`
- **Late escaping**: Escape at output time, not at input time

#### Authentication & Authorization

- **Capability checks**: Use `current_user_can()` for ALL privileged operations
- **Nonce verification**: Required for ALL form submissions and AJAX requests - use `wp_verify_nonce()`, `check_admin_referer()`
- **Role restrictions**: NEVER allow `administrator` role assignment via webhook (`Webhook::DISALLOWED_ROLES`),
  nor any role carrying a capability in `Webhook::DANGEROUS_CAPABILITIES` (custom roles with
  `manage_options`, `edit_users`, plugin/theme management...). `Webhook::is_assignable_role()` is the
  single rule; the settings sanitizer, the role dropdowns and `validate_role()` all call it.
  `unfiltered_html` is deliberately not on the list: editors carry it, and tier → editor is legitimate
- **Account creation never inherits the site's default role**: `Webhook::create_user()` passes
  `'role' => ''` to `wp_insert_user()`. Left unset, WordPress applies *Settings → General → New User
  Default Role*, which no plugin check sees -- with it set to administrator, a payment created an
  administrator. New accounts get a random `kofi-…` login and slug and a display name from Ko-fi's
  `from_name` only when `is_public`; never the email (themes print display names on author archives)
- **Webhook authentication**: ALWAYS validate `verification_token` against stored value before processing
- **Rate limiting**: The webhook endpoint sheds requests from an address that keeps failing
  (`Webhook::FAILURE_LIMIT` / `FAILURE_WINDOW`, filterable via
  `members_for_kofi_webhook_failure_limit`). **Only failures are counted and the token is checked
  before the limit is consulted**, so an authenticated donation is never throttled -- throttling a
  real Ko-fi payment would silently lose it. Keep that ordering if you touch this code;
  `HardeningTest::test_a_valid_donation_is_never_throttled` exists to catch its reversal
- **Unauthenticated requests must not choose what is stored**: only POST reaches the parser, a
  failed request is logged without its payload, and `RequestLogger::MAX_PAYLOAD_LENGTH` caps what
  an authenticated one can store

#### Database Security

- **Prepared statements ONLY**: Use `$wpdb->prepare()` for ALL database queries with variables
- **Never trust user input in SQL**: Even sanitized input must use prepared statements
- **Table prefixes**: Always use `$wpdb->prefix` for custom tables
- **Limit queries**: Use appropriate LIMIT clauses to prevent resource exhaustion

#### File & Direct Access Protection

- **ABSPATH guard**: EVERY PHP file MUST start with `defined( 'ABSPATH' ) || exit;`
- **No direct file access**: All files must be inaccessible when accessed directly
- **File permissions**: Never write files with overly permissive permissions
- **Path traversal prevention**: Validate and sanitize any file path operations

#### Data Protection

- **Never log sensitive data**: Passwords, tokens, API keys must NEVER appear in logs. `DebugLogger`
  context carries IDs, types and tiers -- never the payload, an email, a name or a message
- **Secure token storage**: Verification tokens stored in WordPress options (encrypted database)
- **User privacy**: Only log necessary data, implement log retention policies
- **Sanitize log output**: Even log data must be sanitized before database insertion

#### Secure Coding Practices

- **Avoid dangerous functions**: NEVER use `eval()`, `exec()`, `system()`, `shell_exec()`, `passthru()`, `unserialize()` on untrusted data
- **No variable variables**: Avoid `$$var` patterns that can be exploited
- **Secure random generation**: Use `wp_rand()` or `wp_generate_password()` for secure randomness
- **Error handling**: Don't expose system information in error messages (use `WP_DEBUG` conditionally)
- **Dependency updates**: Regularly update Composer dependencies to patch vulnerabilities

#### WordPress-Specific Security

- **Transient safety**: Never store sensitive data in transients (may be cached insecurely)
- **Cron security**: Ensure cron jobs can't be triggered maliciously
- **AJAX security**: All AJAX handlers must verify nonces and capabilities
- **REST API**: If adding REST routes, use proper `permission_callback` functions
- **Rewrite rules**: Custom endpoints must validate input before processing

### Security Testing Requirements

- **All security-sensitive code MUST have test coverage**
- **Test authentication bypass scenarios**: Verify token validation works correctly
- **Test injection attempts**: Verify sanitization prevents SQL injection, XSS, etc.
- **Test authorization boundaries**: Verify users can't access unauthorized functionality
- **Test role assignment limits**: Verify `administrator` role cannot be assigned via webhook

### Code Review Checklist

Before merging any code, verify:

- [ ] All input is validated and sanitized
- [ ] All output is properly escaped
- [ ] Database queries use prepared statements
- [ ] Capability checks protect privileged operations
- [ ] Nonces verify form submissions
- [ ] ABSPATH guard present in all PHP files
- [ ] No sensitive data in logs or error messages
- [ ] Webhook verification token is validated
- [ ] Administrator role cannot be assigned via webhook
- [ ] No use of dangerous PHP functions
- [ ] Tests cover security scenarios

## Critical Conventions

### WordPress Integration Patterns

- **Namespace**: All classes use `MembersForKofi\` namespace with PSR-4 autoloading
- **ABSPATH guard**: Main plugin file checks `defined( 'ABSPATH' ) || exit;`
- **File headers**: All PHP files include GPL-3.0 license block
- **Hooks**: Use `add_action` / `add_filter` with class method arrays: `array( $this, 'method_name' )`

### Testing Patterns

Tests run against a **real install of the newest WordPress**, never against a remote site. The
`wordpress:latest` image is pulled on every `make test-env-up`, so the suite tracks core releases
automatically. Nothing is pinned to `dev.foodgeek.dk`. Core lives in a persistent volume the image
never refreshes on its own, so `bin/test-env-init.sh` copies core over from the image whenever the
two versions differ — without that, the suite silently stays on the version the volume was created with.

- **Environment**: `docker-compose.test.yml` (WordPress + MySQL + WP-CLI), provisioned by
  `bin/test-env-init.sh`, which installs WordPress, activates the plugin, flushes permalinks and
  configures the plugin options including the test verification token
- **Base class**: tests extend `MembersForKofi\Tests\TestCase` (`tests/TestCase.php`), which recreates
  and empties the plugin tables, clears the plugin option, and deletes users a test created. Use
  `$this->create_user()` in place of WP_UnitTestCase's user factory
- **Bootstrap**: `tests/bootstrap.php` boots real WordPress via `wp-load.php` inside the container
- **Integration tests**: `tests/Integration/` drives the site over real HTTP as Ko-fi does
  (`data=<json>` form-encoded). These are the only tests that exercise `php://input` parsing, so
  anything touching raw body handling **must** be covered here — a unit test that passes an array to
  `Webhook::handle()` skips that path entirely and cannot catch such bugs
- **Never hand-write a `CREATE TABLE` in a test.** Use `UserLogger::create_table()` /
  `RequestLogger::create_table()`. A test-only schema that drifts from production hides real bugs

```bash
make test              # unit/WP tests inside the container
make test-integration  # real HTTP donation requests against the local site
make test-all          # both
make test-env-reset    # wipe the site and database
make wp-version        # WordPress version currently under test
```

## Development Workflows

### Running Tests

```bash
# Boot/refresh the disposable test site (pulls newest WordPress)
make test-env-up

# Run all WordPress-loaded tests inside the container
make test

# Run a specific test class
make test-case TEST=WebhookTest

# Real HTTP donation requests against the local site
make test-integration

# Tear down / wipe
make test-env-down
make test-env-reset
```

`make test` runs inside the WordPress container with core loaded. `make test-integration` runs on the
host and drives the site over HTTP at `http://localhost:8101`. Both boot the environment first.

### Code Quality

```bash
# Run PHPCS (WordPress Coding Standards)
./vendor/bin/phpcs

# Auto-fix with PHPCBF
./vendor/bin/phpcbf
```

Config in `.phpcs.xml` - uses `WordPress` ruleset, excludes `WordPress.Files.FileName`, allows long lines.

### Local Development Site

```bash
# Spin up local WordPress site
make site-up

# SSH into WP-CLI container
make site-shell

# Tear down site
make site-down

# Reset site (includes DB)
make site-reset
```

Uses separate `docker-compose.site.yml` for manual QA testing.

### Release Process

```bash
# Package plugin ZIP (excludes dev files via .releaseignore)
make release

# The targets below live in Makefile.local, which is untracked because they
# need push rights or WordPress.org credentials. `make help` says whether they
# are loaded. Without that file the repository still builds and tests normally.

# Create git tag v{VERSION} (requires clean main branch), then push it
make git-tag

# Full release: package + tag + GitHub release (requires gh CLI)
make github-release

# Deploy to WordPress.org SVN (builds production vendor)
make deploy-svn
make commit-svn WPORG_USER=username WPORG_PASS=password
```

`make release` builds `vendor/` fresh with `--no-dev` into a staging directory
rather than copying the working tree's, which carries the whole test toolchain.

Version extracted from `members-for-kofi.php` header (`* Version: 1.3.1`). Production release uses `composer install --no-dev --optimize-autoloader` inside SVN trunk.

#### Release order: dev site → production → WordPress.org (MANDATORY)

Publishing to WordPress.org is the **last** step, never the first. Every site that updates
gets the release at once, so a fault found after publishing has already reached all of them.

1. **Dev site.** It runs the working tree, so it has been running the release all along.
2. **Production, from the tested package.** After commit, tag and push, build the zip with
   `make release OUT_DIR=<dir>` and install that exact zip on production -- not from
   WordPress.org. Back up first: `wp option get members_for_kofi_options --format=json` into a
   private location (it can hold the token). Then watch it: an uncached page, a wrong-token
   webhook probe (expect a 401), and the MySQL processlist for long-running queries.
3. **WordPress.org, last.** Only once production has run the release cleanly: `make deploy-svn`,
   check that SVN trunk matches what production runs, then `make commit-svn`, and check that
   the published zip matches the tag.
4. **GitHub release**, with the WordPress.org package attached.

**Why:** 1.3.0 was published to WordPress.org first and production was then updated from
it. Its upgrade step never finished on production and jammed the site for about 12 minutes;
in that order, every site updating from WordPress.org was exposed at the same moment. The dev
site did not catch it -- half the data and almost no traffic -- so dev passing is necessary,
not sufficient: upgrade code that runs on `init` must also be timed against production-sized
data before release (see Database Schema Changes).

## Key Files & Patterns

### Custom Rewrite Rules

Plugin registers custom endpoint in `Plugin.php`:
```php
add_rewrite_rule( '^webhook-kofi/?$', 'index.php?kofi_webhook=1', 'top' );
```
Activate/deactivate hooks flush rewrite rules. Query var `kofi_webhook` triggers webhook handler.

### Database Tables

UserLogger creates custom table `{$wpdb->prefix}members_for_kofi_user_logs` with columns:
- `id`, `user_id`, `email`, `action`, `role`, `amount`, `currency`, `timestamp`

RequestLogger creates custom table `{$wpdb->prefix}members_for_kofi_request_logs` with columns:
- `id`, `email`, `tier_name`, `amount`, `currency`, `is_subscription`, `payload`, `status_code`, `success`, `error`, `timestamp`

**Never store the verification token, in whole or in part.** The 1.1.x development line had a
`verification_token` column holding the first ten characters of the site's live token on every
request. It was never displayed and never queried, and no public release shipped it. Schema
version 3 drops the column, which is what destroys any historic fragments -- only blanking new
writes would leave every previously logged request still holding part of the secret. The token is also redacted out of the stored `payload` JSON
and out of `DebugLogger` output.

**The token itself is stored only as a hash** (since 1.2.0, schema version 4):
`members_for_kofi_options['verification_token_sha256']`, plain `hash( 'sha256', $token )`. No
`password_hash()` (a slow KDF on every webhook, for a UUID that cannot be brute-forced) and no
`wp_salt()` (rotating the salts would silently break every webhook). All token rules live in
`Webhook\VerificationToken`:

- `migrate()` is the only code allowed to remove a plaintext `verification_token`. It re-reads the
  option past the cache, removes the plaintext only in the same write that stores a non-empty hash,
  leaves an empty or non-string token untouched, and leaves a hash + *different* plaintext untouched
  (`has_conflict()` then shows an admin notice). It runs from `maybe_upgrade()` **and** `activate()` --
  a deactivate/update/reactivate cycle never passes through `maybe_upgrade()`.
- `expected_hash()` carries a **fallback, to be removed in 1.4.0** (kept through 1.3.0): a site still holding only
  plaintext is verified against it and migrated on the spot. When removing it, switch the many tests
  that seed a plaintext `verification_token` through `update_option()` to the hash key.
- The settings field is write-only: never render the stored value, a blank submission keeps the
  saved token, and a submitted `verification_token_sha256` is ignored. The page shows an
  8-character fingerprint so two sites can be compared.
- Keep the migration on `init`, never `admin_init`: Ko-fi's own request must be able to migrate a
  site nobody opens wp-admin on, and the Settings API sanitizer (registered on `admin_init`) must not
  run over the migration's write.
- Tests seeding legacy option shapes must use `TestCase::write_options_raw()`, which bypasses the
  sanitizer; a test that calls `register_settings()` must `unregister_setting()` afterwards, or the
  sanitizer rewrites every later test's fixtures.

Both tables are created during plugin activation and dropped on uninstall.

### Database Schema Changes (MANDATORY)

**Every change to the database structure must be able to upgrade a previous version.**
WordPress fires the activation hook only when a plugin is *activated*, never when it is
updated in place — so anything that relies on `activate()` alone will never reach an
existing site. Sites that installed 1.0.x and updated through WordPress.org ended up with
no `request_logs` table at all, and silently logged nothing, for exactly this reason.

The upgrade path lives in `Plugin::maybe_upgrade()`, hooked on `init` (priority 5) and
guarded by the `members_for_kofi_db_version` option:

```php
public const DB_VERSION = '6';
public const DB_VERSION_OPTION = 'members_for_kofi_db_version';
```

**The upgrade is single-flight and must stay cheap.** It runs on `init`, so a slow step is started
again by every request until it finishes. 1.3.0's account clean-up used `get_users()` with three OR'd
meta `EXISTS` clauses (three self-joins of usermeta); it never finished on foodgeek.io, 42 copies piled
up and the site jammed for ~12 minutes. Since 1.3.1:

- `maybe_upgrade()` takes a non-blocking `GET_LOCK()` (`Plugin::upgrade_lock_name()`); any other
  request skips the upgrade instead of waiting, and the version is recorded only by the request that
  finished. Lock names are global to the MySQL server, so they carry `DB_NAME` and the table prefix
- Never use `get_users()`/`WP_User_Query` meta queries with several clauses in upgrade code; query
  `usermeta` by `meta_key` directly
- Time any upgrade step against production-sized data before release
  (`UpgradeTest::test_the_account_clean_up_is_fast_on_a_large_user_table` failed in 47 s with the
  1.3.0 query), and release in the order dev site → production → WordPress.org

When changing the schema — adding a table, adding or altering a column — you MUST:

1. **Bump `Plugin::DB_VERSION`.** Nothing upgrades without it; the version comparison is
   what makes `maybe_upgrade()` do any work.
2. **Make the change through `dbDelta()`** in the relevant `create_table()` method.
   `dbDelta` creates missing tables and adds missing columns to existing ones, so it is
   safe to re-run. Never `DROP` and recreate a table that holds user data.
3. **Add the new table to `Plugin::install_tables()`** so a fresh install and an upgrade
   produce the same schema.
4. **Cover it both ways:**
   - a unit test in `tests/UpgradeTest.php` that simulates the older install and calls
     `Plugin::maybe_upgrade()` directly, and
   - an integration test in `tests/Integration/SchemaUpgradeTest.php` that breaks the
     schema, makes a **real HTTP request**, and asserts the site healed. Only the
     integration test proves the upgrade is actually reachable by a visitor.
5. **Never destroy existing rows.** `test_upgrade_preserves_existing_log_rows` pins this.

Fixtures in `SchemaUpgradeTest` talk to MySQL directly rather than through WP-CLI: booting
WordPress at all — even with `--skip-plugins` — recreates the table the test needs missing.

### User Metadata for Expiry

When assigning a role via webhook, metadata stored:
```php
update_user_meta( $user->ID, 'kofi_role_assigned_at', time() );
update_user_meta( $user->ID, 'kofi_donation_assigned_role', $role );
```

Cron job queries all users with this metadata and removes roles if timestamp exceeds expiry threshold.
It re-reads the timestamp past the cache just before removing, so a renewal landing mid-run is kept.

A role the user already held when the webhook first assigned it is recorded in
`kofi_role_preexisting` (`Webhook::PREEXISTING_ROLE_META`). Expiry and tier changes stop tracking such a
role but never remove it: someone else granted it. Accounts the plugin creates carry
`kofi_created_by_plugin`.

### Webhook Processing Rules

- **Only support grants membership.** `Webhook::GRANTING_TYPES` is Donation and Subscription (a payload
  with no `type` counts as granting); shop orders and commissions answer 200 and are logged as ignored.
  Filterable via `members_for_kofi_granting_types`
- **One request per donor email at a time.** `process()` takes a MySQL `GET_LOCK()` per email around
  lookup/creation: WordPress checks email uniqueness in PHP with no unique key, and concurrent first
  payments once created three accounts with the same login and email. If the lock cannot be had in
  10 s the request proceeds -- a slow lock must never cost a payment
- **Redelivered messages are ignored**, keyed on `message_id` + email for 7 days. Not on
  `kofi_transaction_id`: Ko-fi's test button always sends the same placeholder one
- **Ko-fi's "Send test" button creates nothing.** Its payload always carries the placeholder
  `kofi_transaction_id` `Webhook::KOFI_TEST_TRANSACTION_ID` (real payments carry random ones, checked
  against production). After authentication it is logged as `Ko-fi test received` and answered 200
  with `"test": true`. Fixtures that stand for real payments must use a random transaction id.
  Sites that pressed it before 1.3.0 have a `jo.example@example.com` account (`Webhook::KOFI_TEST_EMAIL`);
  `AdminSettings::render_test_account_notice()` points administrators at it on the dashboard, Users
  and settings screens. `admin_notices` passes callbacks an empty string, which must mean "current screen"
- **Every decided outcome answers 200**, or Ko-fi retries a request that would be decided the same way
- **The `/webhook-kofi` path is routed without its rewrite rule** (`Plugin::route_webhook_path()` on
  the `request` filter). Plain permalinks used to answer Ko-fi with the front page and a 200 -- a
  silently lost payment -- and multisite subsites 404'd after network activation. On plain permalinks
  the settings page shows `/?kofi_webhook=1` (`AdminSettings::webhook_url()`)
- **Behind a reverse proxy** `REMOTE_ADDR` is the proxy, so all clients share one failure bucket;
  `members_for_kofi_client_ip` lets a site supply the real address. The default stays `REMOTE_ADDR`:
  forwarding headers are client-controlled
- A tier name with no mapping is written to the user log as `Unmapped tier: …`
- The settings page warns when the plugin's cron events are more than a day overdue
- Uninstall walks every site on multisite; user meta is network-wide and removed once

### Debug Logging

Use `DebugLogger::info()`, `DebugLogger::error()` - only outputs when `WP_DEBUG` is true or `MEMBERS_FOR_KOFI_FORCE_DEBUG` constant defined. Never write to filesystem logs.

## External Dependencies

- **WordPress Core**: Requires WordPress environment (no standalone mode)
- **Ko-fi Webhook**: Expects payload format with `verification_token`, `email`, `tier_name`, `is_subscription_payment`
- **Composer dev dependencies**: PHPUnit 9.6, WordPress Coding Standards, PHP Mock for function mocking

## Anti-Patterns to Avoid

### Security Anti-Patterns

- ❌ **NEVER allow `administrator` role assignment** via webhook (critical security risk)
- ❌ **NEVER skip ABSPATH check** in PHP files - every file must have `defined( 'ABSPATH' ) || exit;`
- ❌ **NEVER output unescaped data** - always use `esc_html()`, `esc_attr()`, `esc_url()`, etc.
- ❌ **NEVER use unsanitized input** - all webhook data must be sanitized before use
- ❌ **NEVER skip `wp_unslash()`** when reading from `$_POST` / `file_get_contents('php://input')`
- ❌ **NEVER use direct SQL queries** - always use `$wpdb->prepare()` with placeholders
- ❌ **NEVER log sensitive data** - tokens, passwords, full payloads with PII
- ❌ **NEVER skip verification token validation** - all webhooks must verify token first
- ❌ **NEVER use `eval()`, `exec()`, `system()`, or similar dangerous functions**
- ❌ **NEVER skip nonce verification** for admin forms or AJAX requests
- ❌ **NEVER skip capability checks** for privileged operations
- ❌ **NEVER trust user input** - validate types, ranges, and whitelist values

### Code Quality Anti-Patterns

- ❌ Don't create filesystem logs (removed in v1.0.0 - use database logging only)
- ❌ **NEVER add or change a database table without an upgrade path** - `activate()` does not
  run on plugin updates, so existing sites would never get the change
- ❌ Don't bump the schema without a test that fails when the upgrade is missing
- ❌ Don't use inconsistent option key names - always use `members_for_kofi_options`
- ❌ Don't mix coding styles - follow WordPress Coding Standards (WPCS) strictly
