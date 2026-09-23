# Members for Ko-fi - AI Coding Agent Instructions

## Project Overview

This is a WordPress plugin (v1.1.0) that integrates with Ko-fi webhooks to automatically manage WordPress users and roles based on donation tiers. The plugin receives webhook payloads from Ko-fi, creates/updates WordPress users, assigns roles based on tier mappings, and manages role expiration. Features include automatic log cleanup, dual log viewing (User/Request), and organized admin settings.

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

### Data Flow

1. Ko-fi webhook hits `https://your-site.com/webhook-kofi` (custom rewrite rule → query var `kofi_webhook=1`)
2. `Webhook::handle()` validates verification token from `members_for_kofi_options['verification_token']`
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
- **Role restrictions**: NEVER allow `administrator` role assignment via webhook (`Webhook::DISALLOWED_ROLES`)
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

- **Never log sensitive data**: Passwords, tokens, API keys must NEVER appear in logs
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

Version extracted from `members-for-kofi.php` header (`* Version: 1.1.0`). Production release uses `composer install --no-dev --optimize-autoloader` inside SVN trunk.

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
public const DB_VERSION = '3';
public const DB_VERSION_OPTION = 'members_for_kofi_db_version';
```

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
