# Members for Ko-fi

WordPress plugin (v1.6.1, `members-for-kofi.php` header) that turns Ko-fi webhook payments into
WordPress accounts and roles by tier, expires roles that are not renewed, and logs users and
requests to two custom tables. Work happens on `develop`.

<!-- Detail lives in .claude/rules/*.md, which load only when Claude touches matching files.
     Keep this file to what every session needs; target under 200 lines. -->

## Where things are

- `src/Plugin.php`: bootstrap, hooks, webhook routing, cron registration, `maybe_upgrade()`
- `src/Webhook/Webhook.php`: authentication, account lookup/creation, role assignment
- `src/Webhook/VerificationToken.php`: every rule about the hashed token
- `src/Hooks.php`: runs the public filters and actions (README "For Developers: Hooks")
- `src/EmailAliases.php`: previous addresses of members who changed their email
- `src/Membership.php` + `src/functions.php`: the public API for other plugins
- `src/Admin/AdminSettings.php`: settings and log viewer. `src/Cron/`: expiry and log clean-up.
  `src/Logging/`: user/request loggers and `DebugLogger`. `src/Privacy/`: export and erase.
  `src/Cli/`: WP-CLI commands

Rules for each area are in `.claude/rules/` (webhook, token, database-schema, membership,
php-security, test-environment, translations). They load automatically when you open
matching files; read the relevant one first when starting work in an area from scratch.

## Non-negotiable

Security comes first; evaluate every change against these before writing it.

- **The webhook never grants `administrator`** or any role with a capability in
  `Webhook::DANGEROUS_CAPABILITIES`. `Webhook::is_assignable_role()` is the single rule, and filtered
  roles pass it too. New accounts are created with `'role' => ''`, or WordPress applies the site's
  default role behind every check
- **The token is validated before anything else**, and stored only as a SHA-256 hash. Never store,
  log or echo it, in whole or in part
- **Never log personal data**: no payload, email, name or message in `DebugLogger` context. A failed
  webhook request is logged without its payload
- **Every decided webhook outcome answers 200**, or Ko-fi retries it. Authenticated payments are
  never throttled: failures are counted only after the token check
- **Never `wp_unslash()` `php://input`** (WordPress slashes only the superglobals); always unslash
  `$_POST`/`$_GET`/`$_REQUEST`/`$_COOKIE`
- Sanitize input, escape output late, `$wpdb->prepare()` every query with a variable, capability and
  nonce checks on every privileged action, `defined( 'ABSPATH' ) || exit;` and the GPL-3.0 header in
  every PHP file, none of `eval`/`exec`/`system`/`unserialize` on untrusted data
- **Any database schema change needs an upgrade path** through `Plugin::maybe_upgrade()` with a
  `DB_VERSION` bump: `activate()` never runs on updates. Read `.claude/rules/database-schema.md` first
- Upgrade code runs on `init` and must stay cheap and single-flight: a slow 1.3.0 upgrade once jammed
  a large site for ~12 minutes
- New translatable strings are translated in every bundled `.po` before release (Danish is complete)

## Testing

Tests run against a real install of the newest WordPress in Docker, never against a remote site.

```bash
make test                    # unit/WP tests inside the container
make test-case TEST=HooksTest
make test-integration        # real HTTP requests against http://localhost:8101
make test-all                # both
make test-env-status         # anything still running?
./vendor/bin/phpcs           # WordPress Coding Standards; must be clean
```

- Tests extend `MembersForKofi\Tests\TestCase`, which resets the plugin tables and option and deletes
  users a test created. Use `$this->create_user()`, not the WP_UnitTestCase factory
- Raw-body handling (`php://input`) is only exercised by `tests/Integration/`; changes there need an
  integration test
- Never hand-write a `CREATE TABLE` in a test: use `UserLogger::create_table()` /
  `RequestLogger::create_table()`
- Security-sensitive code needs tests: token bypass, injection, authorization, role limits
- **Nothing is left running.** Test targets stop what they started. If you ran `make test-env-up`,
  run `make test-env-down` when done, and tear down any rehearsal compose project with `down -v`
- The test stack is memory-capped and refuses to start with under 1.5 GB free. Never remove the caps
- Publish test ports on loopback only (`"127.0.0.1:8101:80"`): Docker's NAT bypasses the host
  firewall, and the test site's login is `admin`/`admin`

## Packaging

`make release` builds the plugin zip: dev files are excluded via `.releaseignore`, and `vendor/` is
built fresh with `--no-dev` in a staging directory rather than copied from the working tree, which
carries the whole test toolchain.
