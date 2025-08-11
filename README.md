# Members for Ko-fi for WordPress

**Members for Ko-fi** is a WordPress plugin that integrates with [Ko-fi](https://ko-fi.com) to manage members based on donation tiers. It automatically creates users, assigns roles, and manages expiration of those roles based on webhook payloads received from Ko-fi.

## Features

- Listens for Ko-fi webhook events.
- Automatically creates new users or updates existing ones.
- Assigns WordPress roles based on Ko-fi membership tiers.
- Supports default roles for unmapped tiers.
- Optional expiration of assigned roles.
- Daily cron job to clean up expired roles.
- Built-in user logging stored in a WordPress database table (no file logging).
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
- **Logging**: User activity is stored in the plugin's user log table. Optional debug output goes to the PHP error log when WP_DEBUG is true. No file log is written.

## Logging

The plugin records user-related events (donations, role assignments) in a dedicated custom database table for auditability. No rotating or persistent filesystem log is created. For development or troubleshooting, if `WP_DEBUG` is enabled a minimal debug logger writes contextual messages to the PHP error log.

## Development

This plugin uses:
- PHPUnit for testing

To run tests:

```bash
make test
```

## License

This plugin is licensed under the [GNU General Public License v3.0 or later](https://www.gnu.org/licenses/gpl-3.0.en.html). You are free to use, modify, and distribute it under the terms of that license.

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change or add.

## Author

Developed and maintained by **Sune Adelowo Trudslev** ([Foodgeek](https://foodgeek.io)).

For bug reports or support, open an issue on GitHub.
