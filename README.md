# Members for Ko-fi for WordPress

**Members for Ko-fi** is a WordPress plugin that integrates with [Ko-fi](https://ko-fi.com) to manage members based on donation tiers. It automatically creates users, assigns roles, and manages expiration of those roles based on webhook payloads received from Ko-fi.

## Features

- Listens for Ko-fi webhook events.
- Automatically creates new users or updates existing ones.
- Assigns WordPress roles based on Ko-fi membership tiers.
- Supports default roles for unmapped tiers.
- Optional expiration of assigned roles.
- Daily cron job to clean up expired roles.
- Built-in user and webhook request logging stored in WordPress database tables.
- View and manage logs through admin interface with dropdown to switch between log types.
- Automatic log cleanup with configurable retention period.
- Lightweight debug logging to the PHP error log when WP_DEBUG is enabled.

## Installation

1. Clone the repository or download it as a ZIP file.
2. Upload the plugin to your WordPress installation under `wp-content/plugins/members-for-kofi`.
3. Activate the plugin in the WordPress admin panel.
4. Configure your settings via the Members for Ko-fi admin page.
5. Go to `https://ko-fi.com/manage/webhooks?src=sidemenu`.
6. Set your Ko-fi webhook to `https://your-site.com/webhook-kofi`.
6. Copy the Verification Token (under Advanced) to the settings.

## Configuration

- **Verification Token**: A shared secret used to validate incoming webhook requests.
- **Tier-to-Role Mapping**: Assign WordPress roles based on donation tier names.
- **Default Role**: Fallback role if no mapping is found.
- **Only Subscriptions**: Optionally ignore one-time donations.
- **Role Expiry**: Automatically remove roles after a set number of days.
- **Logging**: User activity and webhook requests are stored in dedicated database tables. View logs in the admin interface with the ability to switch between User and Request logs.
- **Automatic Log Cleanup**: Daily cron job automatically deletes old logs based on configurable retention period (default: 30 days).

## Logging

The plugin records user-related events (donations, role assignments) and webhook requests in dedicated custom database tables for auditability. Logs can be viewed and managed through the admin interface:

- **User Logs**: Track donations, role assignments, and user activities
- **Request Logs**: Monitor all incoming webhook requests with success/failure status
- **Automatic Cleanup**: Configure automatic deletion of old logs (default: 30 days retention)

For development or troubleshooting, if `WP_DEBUG` is enabled, a minimal debug logger writes contextual messages to the PHP error log.

## Testing Your Setup

Ko-fi's webhook page has test buttons that send a real request to your site. Use them, then open **Members for Ko-fi → Logs** and check both the Request log and the User log.

All three buttons use the same fake supporter, **Jo Example** (`jo.example@example.com`), so a successful test creates a real WordPress user with that address. Delete it when you are done.

| Button | Subscription? | Tier sent | Amount |
| --- | --- | --- | --- |
| Send single tip test | No | *none* | 3.00 USD |
| Send first monthly test | Yes (first payment) | *none* | 3.00 USD |
| Send membership tier test | Yes (renewal) | `Bronze` | 5.00 USD |

**If you use tier-to-role mappings, `Bronze` is the only tier name any test button sends.** Add a mapping for a tier called `Bronze` if you want the tier test to assign a role. The other two buttons send no tier at all, so they can only ever reach your default role.

### A test ran but nothing seemed to happen

Usually the plugin is behaving correctly:

- **"Only Subscriptions" is enabled.** The single tip test is not a subscription payment, so it is ignored on purpose. The Request log still shows a `200`, and the User log says `Ignored non-subscription payment`. Use one of the subscription tests instead.
- **No matching tier, and no default role.** The supporter is still created and the donation still logged — only the role is skipped. Look for `User created` and `Donation received` without a following `Role assigned`.

### Checking the endpoint itself

If the Request log is empty after a test, the request never reached WordPress. Open your webhook URL in a browser:

- `{"error":"Method not allowed"}` is **correct**. The endpoint accepts only the POST requests Ko-fi sends and refuses everything else, so crawlers cannot fill your logs.
- A **404** means the endpoint is not registered. Re-save permalinks under **Settings → Permalinks** to rebuild the rewrite rule.

If a test returns `Unauthorized` or `Missing verification token`, the token in the plugin settings no longer matches the one on Ko-fi's webhook page. Regenerating it on Ko-fi does not update WordPress, and real payments are rejected exactly the same way.

## Security

This plugin follows WordPress security best practices and implements multiple layers of protection:

### Administrator Role Protection

**Critical Security Boundary:** The plugin explicitly prevents the `administrator` role from being assigned via webhook. This is a fundamental security control that prevents privilege escalation attacks.

- **Why this matters:** If webhooks could assign administrator roles, an attacker who compromises the Ko-fi webhook endpoint (or obtains your verification token) could gain full WordPress admin access to your site.
- **Implementation:** The `Webhook::DISALLOWED_ROLES` constant ensures the administrator role cannot be selected in tier mappings or as a default role.
- **Admin UI:** Administrator role is filtered out from all role selection dropdowns in the plugin settings.

### Other Security Features

- **Webhook Verification:** All incoming webhook requests must include a valid verification token that matches your configured token.
- **Input Sanitization:** All webhook data is sanitized using WordPress security functions before processing.
- **Output Escaping:** All data displayed in the admin interface is properly escaped to prevent XSS attacks.
- **Nonce Verification:** All admin forms and AJAX requests verify WordPress nonces.
- **Capability Checks:** Administrative functions require the `manage_options` capability.
- **Database Security:** All database queries use prepared statements to prevent SQL injection.
- **Direct File Access Protection:** All PHP files check for WordPress context (ABSPATH) before executing.
- **Sensitive Data Protection:** Verification tokens are redacted from database logs to prevent exposure.

### Security Audits

This plugin has undergone security auditing against:
- WordPress Plugin Handbook - Security Best Practices
- WordPress Coding Standards (WPCS)
- OWASP Top 10 Web Application Security Risks
- WordPress VIP Code Review Standards

For detailed security documentation, see [`SECURITY_AUDIT_REPORT.md`](SECURITY_AUDIT_REPORT.md).

## Development

Tests run against a real install of the newest WordPress in Docker, not against
a mocked WordPress, so `make` does the setup for you. Run `make help` for the
full list.

```bash
make test              # WordPress-loaded suite, inside the container
make test-integration  # drives the site over real HTTP, exactly as Ko-fi does
make test-all          # both

make test-case TEST=WebhookTest   # a single class
make test-env-reset               # wipe the site and database and start over
make wp-version                   # the WordPress version currently under test
```

The integration suite is the only one that exercises raw request body parsing,
so anything touching that path needs a test there — a unit test that hands
`Webhook::handle()` a ready-made array skips the parsing entirely.

Coding standards are enforced with PHPCS against the WordPress ruleset:

```bash
./vendor/bin/phpcs     # check
./vendor/bin/phpcbf    # auto-fix
```

Publishing targets (git tagging, GitHub releases, WordPress.org SVN) need push
rights or credentials and live in an untracked `Makefile.local`, so they are not
part of the repository. Everything above works without it.

## Release History

- **1.1.0**: Automatic log cleanup, improved admin UI with reorganized settings, and a request logs viewer. Adds an upgrade path so the request log table is created when updating from 1.0.x, stops storing the Ko-fi verification token, hardens the webhook address against abuse, and fixes donation messages being mangled when they contained quotes or accented characters.
- **1.0.1**: Packaging improvements (production-only vendor build). No functional changes.
- **1.0.0**: Initial release.

## License

This plugin is licensed under the [GNU General Public License v3.0 or later](https://www.gnu.org/licenses/gpl-3.0.en.html). You are free to use, modify, and distribute it under the terms of that license.

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change or add.

## Author

Developed and maintained by **Sune Adelowo Trudslev** ([Foodgeek](https://foodgeek.io)).

For bug reports or support, open an issue on GitHub.
