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
- Stores only what it needs, and removes it all when you uninstall.

**Use Cases:**
- Reward your Ko-fi supporters with exclusive access to content or features.
- Automate user role management for subscription-based memberships.
- Track and log user activity for better insights.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/members-for-kofi` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure the plugin under **Members for Ko-fi** in the main admin menu (it has its own entry, with a heart icon).
4. Set up your Ko-fi webhook to point to your WordPress site.

== Frequently Asked Questions ==

= How do I set up the Ko-fi webhook? =
1. Log in to your Ko-fi account and open the webhooks page: https://ko-fi.com/manage/webhooks
2. Set the webhook URL to your site followed by `/webhook-kofi`, for example `https://your-site.com/webhook-kofi`.
3. Copy the Verification Token (under Advanced) into **Members for Ko-fi > Settings** in WordPress.

= How do I test my setup without waiting for a real payment? =

Ko-fi's webhook page has test buttons that send a real request to your site. Use them, then open **Members for Ko-fi > Logs** and check both the Request log and the User log.

The buttons all use the same fake supporter, **Jo Example** (`jo.example@example.com`), so a successful test creates a real WordPress user with that address. Delete it when you are done.

What each button sends:

* **Send single tip test** - a one-off tip of 3.00 USD, with no tier.
* **Send first monthly test** - a first subscription payment of 3.00 USD, with no tier.
* **Send membership tier test** - a renewal subscription payment of 5.00 USD for the tier named **Bronze**.

= I ran a test and nothing happened. Why? =

Three settings decide whether a test does anything, and all three are working as intended when a test appears to be ignored:

* **"Only process subscription payments" is enabled.** The *single tip* test is not a subscription payment, so it is ignored on purpose. Use the *first monthly* or *membership tier* test instead. The Request log will still show the request arriving with a 200, and the User log will say "Ignored non-subscription payment".
* **You use tier mappings but have no matching tier.** Only the *membership tier* test sends a tier name, and the name it sends is **Bronze**. If you want that test to assign a role, add a mapping for a tier called `Bronze`. Without it, the plugin falls back to your default role.
* **You have no default role set.** The *single tip* and *first monthly* tests send no tier at all, so they can only ever use the default role. If that is empty and you rely purely on tier mappings, the supporter is still created as a WordPress user and the donation is still logged - only the role is skipped. The User log will show "User created" and "Donation received" but no "Role assigned".

If the Request log is completely empty after a test, the request never reached WordPress. Check that the webhook URL in Ko-fi matches your site exactly, then open that URL in a browser:

* `{"error":"Method not allowed"}` is the **correct** response. The endpoint only accepts the POST requests Ko-fi sends, and refuses anything else so that crawlers cannot fill your logs. Seeing this means the address is working.
* A "Not Found" or 404 page means the endpoint is not registered. Re-save your permalinks under **Settings > Permalinks** to rebuild the rule, then try again.

= A test says "Unauthorized" or "Missing verification token". What now? =

The verification token in **Members for Ko-fi > Settings** must match the one on Ko-fi's webhook page exactly. If you have ever regenerated it on Ko-fi, paste the new value into the plugin as well - Ko-fi will not warn you that the two no longer match, and real payments will be rejected the same way the test was.

= What happens when a supporter's role expires? =

Each time a payment arrives, the plugin records the date. If that date falls
further in the past than the number of days you configure, a daily job removes
the role it assigned and stops tracking it. Their WordPress account stays, and
so does any other role they have - only the role this plugin gave them is taken
away.

Set the number of days **longer than your Ko-fi billing cycle**, not equal to
it. Ko-fi sends a webhook once when a payment happens and never retries, so if
one delivery is lost - a brief outage, a network problem - the next one is a
whole month away. With a 30-day setting, a single lost delivery costs that
supporter their access. Something around two billing cycles absorbs it: the
next successful payment refreshes the date before anything is removed.

= Why does a supporter have two roles? =

The plugin adds its role to whatever the supporter already has, rather than
replacing it. New accounts get whatever WordPress assigns new users (usually
Subscriber), and the plugin's role is added on top. When the role expires, only
the plugin's role is removed, which leaves the account intact.

= Does this plugin delete data on deactivation? =
No, the plugin does not delete any data on deactivation. However, you can manually delete data by uninstalling the plugin.

= What personal data does this plugin store? =

Two tables of its own:

* **User log** - the WordPress user ID, email address, what happened (user created, role assigned, role removed, donation received), the role, and the amount and currency.
* **Request log** - the email address, tier name, amount, currency, the HTTP status, and the request Ko-fi sent. The verification token is never stored.

Both are removed when you uninstall the plugin, along with its settings. Deactivating keeps them.

The plugin does **not** currently register handlers for WordPress's privacy
export and erase tools, so a data request under GDPR or similar will not pick
these tables up automatically. If you need to remove a supporter's data, delete
their WordPress user and clear the logs from the Logs tab.

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
