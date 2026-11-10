---
paths:
  - "src/**/*.php"
  - "members-for-kofi.php"
---

# Security practices for plugin PHP

Standards: the WordPress Plugin Handbook security chapter, WPCS (`WordPress` ruleset via PHPCS),
OWASP Top 10 (injection, broken authentication, misconfiguration, insecure deserialization) and
WordPress VIP review standards.

## Input

- Sanitize all external input: `sanitize_text_field()`, `sanitize_email()`, `absint()`,
  `sanitize_key()`…
- `wp_unslash()` on `$_POST`, `$_GET`, `$_REQUEST`, `$_COOKIE` and `$_SERVER` reads, never on
  `php://input`
- Validate types (string, int, array) and whitelist known values (roles, tiers)

## Output

- Escape late, for the context: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`, `wp_kses_post()`
- JSON with `wp_json_encode()`, never `json_encode()`

## Authorization

- `current_user_can()` on every privileged operation
- Nonces on every form and AJAX request: `wp_verify_nonce()`, `check_admin_referer()`
- REST routes need a real `permission_callback`; cron jobs must not be triggerable from outside

## Database

- `$wpdb->prepare()` for every query with a variable, even sanitized ones. Tables use `$wpdb->prefix`
- LIMIT queries that could grow with the data

## Files, functions and data

- Every PHP file starts with the GPL-3.0 header and `defined( 'ABSPATH' ) || exit;`
- No `eval()`, `exec()`, `system()`, `shell_exec()`, `passthru()`, `unserialize()` on untrusted data,
  no `$$var`
- Randomness from `wp_rand()` / `wp_generate_password()`
- No filesystem logs (removed in 1.0.0): database logging, plus `DebugLogger` to `error_log` only
  when `WP_DEBUG` or `MEMBERS_FOR_KOFI_FORCE_DEBUG` is set. Its context carries IDs, types and tiers,
  never the payload, an email, a name or a message
- No sensitive data in transients or in error messages; system detail only under `WP_DEBUG`
- Classes are `MembersForKofi\` PSR-4 under `src/`; hooks use `array( $this, 'method' )`
- Option key is always `members_for_kofi_options`

## Review checklist

Input sanitized · output escaped · queries prepared · capabilities and nonces checked · ABSPATH
guard · nothing sensitive logged · token validated first · administrator never assignable · no
dangerous functions · tests cover the security scenario.
