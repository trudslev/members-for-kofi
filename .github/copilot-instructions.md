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
5. Daily cron (`kofi_members_check_role_expiry`) removes roles past `role_expiry_days` threshold

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
- **Always use `wp_unslash()`** when reading from `$_POST`, `$_GET`, or `file_get_contents('php://input')`
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
- **Rate limiting**: Consider implementing rate limiting for webhook endpoint to prevent abuse

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

- **WordPress tests**: Extend `WP_UnitTestCase` (e.g., `tests/Cron/RoleExpiryCheckerTest.php`)
- **Unit tests**: Extend `PHPUnit\Framework\TestCase` (e.g., `tests/Webhook/WebHookTest.php`)
- **Mocking**: Use `$this->createMock()` for WordPress-independent classes
- **Setup**: `tests/bootstrap.php` loads WordPress test framework and plugin via `tests_add_filter('muplugins_loaded', 'kofi_members_manually_load_plugin')`
- **Environment**: Tests use `.env` file loaded via `vlucas/phpdotenv` for `KOFI_VERIFICATION_TOKEN` and DB credentials

## Development Workflows

### Running Tests

```bash
# Run all tests in Docker container
make test

# Run specific test class
make test-case TEST=WebhookTest

# Rebuild test container
make rebuild
```

Tests run in Docker container with isolated WordPress test environment and MySQL database.

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

# Create git tag v{VERSION} (requires clean main branch)
make git-tag

# Full release: package + tag + GitHub release (requires gh CLI)
make github-release

# Deploy to WordPress.org SVN (builds production vendor)
make deploy-svn
make commit-svn WPORG_USER=username WPORG_PASS=password
```

Version extracted from `members-for-kofi.php` header (`* Version: 1.0.1`). Production release uses `composer install --no-dev --optimize-autoloader` inside SVN trunk.

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
- `id`, `email`, `tier_name`, `amount`, `currency`, `is_subscription`, `verification_token`, `payload`, `status_code`, `success`, `error`, `timestamp`

Both tables are created during plugin activation and dropped on uninstall.

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
- ❌ Don't use inconsistent option key names - always use `members_for_kofi_options`
- ❌ Don't mix coding styles - follow WordPress Coding Standards (WPCS) strictly

## Custom Reports

Custom reports (security audits, test summaries, analysis reports) should be placed in the `reports/` directory to keep the root clean and prevent them from being committed to version control.

### Report Directory Rules

- **Location:** All custom reports go in `reports/` directory (created automatically if missing)
- **Git Handling:** The `reports/` folder is in `.gitignore` - reports are local/temporary only
- **File Types:** Markdown (`.md`), JSON (`.json`), or text (`.txt`) formats
- **Naming Convention:** Use descriptive names like `SECURITY_AUDIT_REPORT.md`, `TEST_COVERAGE_REPORT.md`
- **Cleanup:** Reports can be safely deleted - they are not part of the committed codebase

### Example Report Files

- `reports/SECURITY_AUDIT_REPORT.md` - Comprehensive security audit with findings and CVSS scores
- `reports/SECURITY_FIXES_SUMMARY.md` - Summary of security fixes implemented
- `reports/TEST_COVERAGE_REPORT.md` - Test coverage analysis
- `reports/PERFORMANCE_ANALYSIS.md` - Performance profiling results

### When to Generate Reports

- After major security audits or fixes
- When significant code changes are made
- As part of release documentation (but don't commit)
- For stakeholder communication about code quality
