=== Members for Ko-fi ===
Contributors: trudslev
Donate link: https://ko-fi.com/foodgeek
Tags: ko-fi, membership, roles, webhook, user management
Requires at least: 5.6
Tested up to: 6.8
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
New features: Automatic log cleanup and improved admin UI. Settings have been reorganized for better clarity.

= 1.0.1 =
Maintenance release: improved packaging only. No action required.

= 1.0.0 =
Initial release. No upgrade steps required.

== License ==

This plugin is licensed under the GPLv3 or later. See the [GNU General Public License](https://www.gnu.org/licenses/gpl-3.0.html) for more details.
