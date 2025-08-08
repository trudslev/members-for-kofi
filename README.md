# Members for Ko-fi for WordPress

**Members for Ko-fi** is a WordPress plugin that integrates with [Ko-fi](https://ko-fi.com) to manage members based on donation tiers. It automatically creates users, assigns roles, and manages expiration of those roles based on webhook payloads received from Ko-fi.

## Features

- Listens for Ko-fi webhook events.
- Automatically creates new users or updates existing ones.
- Assigns WordPress roles based on Members for Ko-fihip tiers.
- Supports default roles for unmapped tiers.
- Optional expiration of assigned roles.
- Daily cron job to clean up expired roles.
- Built-in debug logging.
- Built-in user logging, so you can see when your users have supported you and what roles they have been assigned.

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
- **Logging**: Enable file logging with severity thresholds.

## Logging

Log messages are written to `wp-content/logs/members-for-kofi.log`. 

## Development

This plugin uses:
- [Monolog](https://github.com/Seldaek/monolog) for logging
- [PHPUnit](https://phpunit.de/) for testing

To run tests:

```bash
make test
```

## License

This plugin is licensed under the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.en.html). You are free to use, modify, and distribute it under the terms of that license.

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change or add.

## Author

Developed and maintained by **Sune Adelowo Trudslev** ([Foodgeek](https://foodgeek.io)).

For bug reports or support, open an issue on GitHub.
