---
paths:
  - "src/Membership.php"
  - "src/functions.php"
  - "src/Cron/**"
  - "src/Privacy/**"
  - "src/Cli/**"
  - "tests/MembershipTest.php"
  - "tests/Cron/**"
  - "tests/Privacy/**"
---

# Membership, expiry and personal data

## Public API

`members_for_kofi_get_membership( int $user_id ): ?array` (since 1.4.0) and
`members_for_kofi_supports_email_change()` live in `src/functions.php`, loaded by the main plugin
file. Generic by design: other plugins build on them, so no site-specific names, wording or filters.

## User meta

A payment that grants a role stores:

```php
update_user_meta( $user->ID, 'kofi_role_assigned_at', time() );
update_user_meta( $user->ID, 'kofi_donation_assigned_role', $role );
```

- The daily cron (`kofi_members_check_expired_roles`, `RoleExpiryChecker`) removes roles past
  `role_expiry_days`. It re-reads the timestamp past the cache just before removing, so a renewal
  landing mid-run is kept. The separate `kofi_members_cleanup_logs` deletes logs older than
  `log_retention_days` when `auto_clear_logs` is on
- Before deleting the tracking meta, expiry calls `Membership::record_end()`, which keeps
  `kofi_last_assigned_role`, `kofi_last_payment_at` and `kofi_membership_ended_at`. Without them an
  ended membership is indistinguishable from one that never existed
- A new payment (`Membership::record_payment()`) drops that record and stores
  `kofi_last_payment_subscription`. The first granting payment also sets `kofi_member_since`, which
  renewals, endings and rejoins never move. Backfill:
  `wp members-for-kofi member-since set <user-id> <date> [--force]` (`src/Cli/MemberSinceCommand.php`)
- All five keys are in `Membership::meta_keys()`, which uninstall removes
- A role in `kofi_role_preexisting` was granted by someone else: stop tracking it, never remove it
- The settings page warns when the plugin's cron events are more than a day overdue

## Privacy

`src/Privacy/PersonalData.php`: exporter and eraser for both log tables and the email aliases,
suggested privacy-policy text, and the one-off clean-up of donor accounts created before 1.3.0. The
eraser deletes the ended-membership record but keeps a live membership (and says so), since
deleting it would end paid access. Aliases are kept while a membership is live.
