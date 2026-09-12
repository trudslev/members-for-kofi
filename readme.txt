=== Members for Ko-fi ===
Contributors: trudslev
Donate link: https://ko-fi.com/foodgeek
Tags: ko-fi, membership, roles, webhook, user management
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Integrate with Ko-fi to manage WordPress users or roles via webhook.

== Description ==

Members for Ko-fi is a WordPress plugin that integrates with Ko-fi to manage WordPress users and roles based on Ko-fi webhooks. This plugin allows you to automate user role assignments, log donations (in a database table), and manage memberships seamlessly.

**Features:**
- Automatically assign roles to users based on Ko-fi donations or memberships.
- Log user actions, such as donations and role changes, in a dedicated database table (no file logging).
- Lightweight debug logging to the PHP error log when WP_DEBUG is enabled.
- Fully compatible with GDPR and WordPress privacy tools.

**Use Cases:**
- Reward your Ko-fi supporters with exclusive access to content or features.
- Automate user role management for subscription-based memberships.
- Track and log user activity for better insights.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/members-for-kofi` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure the plugin settings under **Settings > Members for Ko-fi**.
4. Set up your Ko-fi webhook to point to your WordPress site.

== Frequently Asked Questions ==

= How do I set up the Ko-fi webhook? =
1. Log in to your Ko-fi account.
2. Go to **Settings > Webhooks**.
3. Add your WordPress site's webhook URL (e.g., `https://your-site.com/webhook-kofi`).

= Does this plugin delete data on deactivation? =
No, the plugin does not delete any data on deactivation. However, you can manually delete data by uninstalling the plugin.

= Is this plugin GDPR compliant? =
Yes, the plugin integrates with WordPress's privacy tools to allow exporting and erasing user data.

== Screenshots ==

1. **Settings Page**: Configure plugin options, including role mapping.
2. **User Logs**: View logs of user actions, such as donations and role changes.

== Changelog ==

= 1.1.0 =
* Feature: Added automatic log cleanup with configurable retention period.
* Feature: Reorganized admin settings page with separate sections for Ko-fi Settings, Role Assignment, and Logging.
* Feature: Added support for viewing webhook request logs in addition to user logs.
* Enhancement: Renamed "User Logs" tab to "Logs" with dropdown to switch between User and Request logs.
* Enhancement: Renamed "General" tab to "Settings" for better clarity.
* Enhancement: Added daily cron job to automatically delete old logs based on retention settings.
* Enhancement: Settings now include "Automatically Clear Logs" (default: enabled) and "Number of Days to Keep Logs" (default: 30 days).
* Improvement: Better organization of settings with clear section headers.
* Fix: The request log table is now created when the plugin is updated, not only when it is first activated. Without this, updating from 1.0.x would have left the new Request log permanently empty.
* Fix: Donation messages containing quotes, backslashes or accented characters are no longer mangled, and are stored exactly as they were sent.
* Fix: Log cleanup now actually deletes old entries. It previously compared dates in two different formats and removed nothing.
* Fix: When a supporter changes tier, the role from their previous tier is now removed instead of being left in place.
* Fix: The log viewer no longer errors when an unexpected "rows per page" value is used.
* Fix: A role that no longer exists on the site is no longer assigned to a supporter.
* Security: The Ko-fi verification token is never stored in the request log, and is removed from the stored request details.
* Security: The verification token is no longer written to PHP error logs.
* Security: The webhook address now accepts only the request type Ko-fi actually sends, and ignores an address that keeps failing, so it cannot be used to fill your database. Genuine donations are never affected.
* Security: Tokens are compared in a way that does not reveal how much of a guess was correct.
* Improvement: Automated tests now run against a fresh install of the latest WordPress, including tests that send real donation requests over HTTP.

= 1.0.1 =
* Build: Adjusted release packaging to exclude dev dependencies and include only production-ready vendor autoloader.
* No functional code changes for end users.

= 1.0.0 =
* Initial release.
* Support for Ko-fi webhooks.
* Automatic role assignment based on donations or memberships tiers.
* Logging of user actions in database.

== Upgrade Notice ==

= 1.1.0 =
New features: automatic log cleanup, a request log viewer and a reorganized settings page. Also includes security hardening and several fixes. Your existing settings are kept.

= 1.0.1 =
Maintenance release: improved packaging only. No action required.

= 1.0.0 =
Initial release. No upgrade steps required.

== License ==

This plugin is licensed under the GPLv3 or later. See the [GNU General Public License](https://www.gnu.org/licenses/gpl-3.0.html) for more details.
