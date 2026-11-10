---
paths:
  - "src/Plugin.php"
  - "src/Logging/**"
  - "tests/UpgradeTest.php"
  - "tests/Logging/**"
  - "tests/Integration/SchemaUpgradeTest.php"
---

# Database tables and upgrades

## Tables

- `{$wpdb->prefix}members_for_kofi_user_logs` (`UserLogger`): `id`, `user_id`, `email`, `action`,
  `role`, `amount`, `currency`, `timestamp`. `action` is 50 characters
- `{$wpdb->prefix}members_for_kofi_request_logs` (`RequestLogger`): `id`, `email`, `tier_name`,
  `amount`, `currency`, `is_subscription`, `payload`, `status_code`, `success`, `error`, `timestamp`

Both are created on activation and by the upgrade, and dropped on uninstall. Uninstall walks every
site on multisite; user meta is network-wide and removed once.

## Schema changes (MANDATORY)

WordPress fires the activation hook only on *activation*, never on an in-place update, so anything
that relies on `activate()` alone never reaches existing sites. Sites that installed 1.0.x and
updated through WordPress.org had no `request_logs` table at all, and silently logged nothing.

The upgrade path is `Plugin::maybe_upgrade()` on `init` (priority 5), guarded by
`members_for_kofi_db_version` (`Plugin::DB_VERSION`). For any table or column change you MUST:

1. **Bump `Plugin::DB_VERSION`.** Nothing upgrades without it
2. **Make the change through `dbDelta()`** in the relevant `create_table()`. It creates missing
   tables and adds missing columns, so it is safe to re-run. Never `DROP` and recreate a table that
   holds user data
3. **Add a new table to `Plugin::install_tables()`**, so a fresh install and an upgrade produce the
   same schema
4. **Cover it both ways:** a unit test in `tests/UpgradeTest.php` that simulates the older install
   and calls `Plugin::maybe_upgrade()`, and an integration test in
   `tests/Integration/SchemaUpgradeTest.php` that breaks the schema, makes a **real HTTP request**
   and asserts the site healed. Only the integration test proves a visitor reaches the upgrade
5. **Never destroy existing rows** (`test_upgrade_preserves_existing_log_rows`)

`SchemaUpgradeTest` fixtures talk to MySQL directly: booting WordPress at all, even with
`--skip-plugins`, recreates the table the test needs missing.

## The upgrade is single-flight and must stay cheap

It runs on `init`, so a slow step is restarted by every request until it finishes. 1.3.0's account
clean-up used `get_users()` with three OR'd meta `EXISTS` clauses (three usermeta self-joins); it
never finished on foodgeek.io, 42 copies piled up and the site jammed for ~12 minutes. Since 1.3.1:

- `maybe_upgrade()` takes a non-blocking `GET_LOCK()` (`Plugin::upgrade_lock_name()`). Other requests
  skip the upgrade instead of waiting, and only the request that finished records the version. Lock
  names are global to the MySQL server, so they carry `DB_NAME` and the table prefix
- Never use `get_users()` / `WP_User_Query` meta queries with several clauses in upgrade code; query
  `usermeta` by `meta_key` directly
- Time any upgrade step against production-sized data before release
  (`UpgradeTest::test_the_account_clean_up_is_fast_on_a_large_user_table` failed in 47 s with the
  1.3.0 query)
