---
paths:
  - "src/Webhook/VerificationToken.php"
  - "src/Admin/**"
  - "src/Plugin.php"
  - "tests/Admin/**"
  - "tests/UpgradeTest.php"
  - "tests/Integration/SchemaUpgradeTest.php"
---

# Verification token

**Never store the token, in whole or in part.** The 1.1.x development line had a
`verification_token` log column holding the first ten characters of the live token on every
request (no public release shipped it). Schema version 3 drops the column, which is what destroys
historic fragments: blanking only new writes would have left every logged request holding part of
the secret.

**Only a hash is stored** (since 1.2.0, schema 4): `members_for_kofi_options['verification_token_sha256']`,
plain `hash( 'sha256', $token )`. No `password_hash()` (a slow KDF on every webhook, for a UUID that
cannot be brute-forced) and no `wp_salt()` (rotating the salts would silently break every webhook).
All token rules live in `Webhook\VerificationToken`:

- `migrate()` is the only code allowed to remove a plaintext `verification_token`. It re-reads the
  option past the cache, removes the plaintext only in the same write that stores a non-empty hash,
  leaves an empty or non-string token untouched, and leaves a hash + *different* plaintext untouched
  (`has_conflict()` then shows an admin notice). It runs from `maybe_upgrade()` **and** `activate()`:
  a deactivate/update/reactivate cycle never passes through `maybe_upgrade()`
- Only the hash is a configured token. The plaintext fallback in `expected_hash()` was removed in
  1.4.0: a plaintext token left after the upgrade (settings restored from before 1.2.0) gets
  `401 Unauthorized: no token configured` and the webhook never touches it. Saving the settings page
  converts it (`legacy_hash()`), even with the field left blank
- The settings field is write-only: never render the stored value, a blank submission keeps the
  saved token, and a submitted `verification_token_sha256` is ignored. The page shows an
  8-character fingerprint so two sites can be compared
- Keep the migration on `init`, never `admin_init`: Ko-fi's own request must be able to migrate a site
  nobody opens wp-admin on, and the Settings API sanitizer (registered on `admin_init`) must not run
  over the migration's write

## Tests

- Fixtures seed `verification_token_sha256`, never a plaintext `verification_token`, unless they test
  the migration
- Tests seeding legacy option shapes use `TestCase::write_options_raw()`, which bypasses the sanitizer
- A test that calls `register_settings()` must `unregister_setting()` afterwards, or the sanitizer
  rewrites every later test's fixtures
