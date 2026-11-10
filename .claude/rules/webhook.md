---
paths:
  - "src/Webhook/**"
  - "src/Hooks.php"
  - "src/EmailAliases.php"
  - "tests/Webhook/**"
  - "tests/Security/**"
  - "tests/Integration/**"
  - "tests/EmailAliasesTest.php"
---

# Webhook rules

Flow: Ko-fi POSTs `data=<json>` to `/webhook-kofi` → `Webhook::handle()` → token hash compared with
`members_for_kofi_options['verification_token_sha256']` → user found or created → role from
`tier_role_map` / `default_role` → `kofi_role_assigned_at` stored for expiry.

## Parsing and authentication

- Never `wp_unslash()` the `php://input` body: WordPress slashes only the superglobals. Unslashing
  the raw body breaks `\"` outright and silently corrupts `\\` and `\uXXXX`
- Sanitize the decoded payload recursively (`array_walk_recursive()`) before use
- Validate the token before anything else is processed. Only POST reaches the parser
- An unauthenticated request must not choose what is stored: a failed request is logged without its
  payload, and `RequestLogger::MAX_PAYLOAD_LENGTH` caps what an authenticated one stores. The token
  is redacted from the stored payload and from `DebugLogger` output
- **Rate limiting** (`FAILURE_LIMIT` / `FAILURE_WINDOW`, filter `members_for_kofi_webhook_failure_limit`):
  only failures are counted, and the token is checked *before* the limit is consulted, so an
  authenticated payment is never throttled (throttling a real payment silently loses it). Keep that
  order; `HardeningTest::test_a_valid_donation_is_never_throttled` catches its reversal
- Behind a reverse proxy `REMOTE_ADDR` is the proxy, so all clients share one failure bucket.
  `members_for_kofi_client_ip` lets a site supply the real address; the default stays `REMOTE_ADDR`
  because forwarding headers are client-controlled

## Roles and accounts

- Never grant `administrator` (`Webhook::DISALLOWED_ROLES`) or any role carrying a capability in
  `Webhook::DANGEROUS_CAPABILITIES`. `Webhook::is_assignable_role()` is the single rule; the settings
  sanitizer, the role dropdowns and `validate_role()` all call it. `unfiltered_html` is deliberately
  not on the list: editors carry it, and tier → editor is legitimate
- `create_user()` passes `'role' => ''` to `wp_insert_user()`. Left unset, WordPress applies
  *Settings → General → New User Default Role*, which no plugin check sees: with it set to
  administrator, a payment created an administrator
- New accounts get a random `kofi-…` login and slug, and a display name from Ko-fi's `from_name` only
  when `is_public`. Never the email: themes print display names on author archives
- Every user lookup goes through `EmailAliases::find_user()` (current email first, then the member
  who most recently gave that address up), never `get_user_by( 'email' )`. Ko-fi identifies
  supporters only by email and keeps sending the old one; before 1.5.0 the next payment created a
  second account. Aliases (`kofi_email_alias`, lower-cased, one row each) are recorded on
  `profile_update`, only for users the plugin knows
- A role the user already held when the webhook first assigned it is recorded in
  `kofi_role_preexisting` (`Webhook::PREEXISTING_ROLE_META`). Tier changes and expiry stop tracking it
  but never remove it. Accounts the plugin creates carry `kofi_created_by_plugin`

## Processing

- **Only support grants membership.** `Webhook::GRANTING_TYPES` is Donation and Subscription (no
  `type` counts as granting). Shop orders and commissions answer 200 and are logged as ignored.
  Filterable via `members_for_kofi_granting_types`
- **Every decided outcome answers 200**, or Ko-fi retries a request that would be decided the same way
- **One request per donor email at a time:** `process()` takes a MySQL `GET_LOCK()` per email around
  lookup and creation. WordPress checks email uniqueness in PHP with no unique key, and concurrent
  first payments once created three identical accounts. If the lock takes over 10 s the request
  proceeds anyway: a slow lock must never cost a payment
- **Redelivered messages are ignored**, keyed on `message_id` + email for 7 days. Not on
  `kofi_transaction_id`: Ko-fi's test button always sends the same placeholder
- **Ko-fi's "Send test" creates nothing.** Its payload carries the placeholder `kofi_transaction_id`
  `Webhook::KOFI_TEST_TRANSACTION_ID`. After authentication it is logged as `Ko-fi test received` and
  answered 200 with `"test": true`. Fixtures standing for real payments must use a random
  transaction id. Sites that pressed it before 1.3.0 have a `jo.example@example.com` account
  (`KOFI_TEST_EMAIL`); `AdminSettings::render_test_account_notice()` points administrators at it.
  `admin_notices` passes callbacks an empty string, which must mean "current screen"
- A tier name with no mapping is written to the user log as `Unmapped tier: …`
- `/webhook-kofi` is routed without its rewrite rule (`Plugin::route_webhook_path()` on the `request`
  filter). Plain permalinks used to answer Ko-fi with the front page and a 200, a silently lost
  payment, and multisite subsites 404'd. On plain permalinks the settings page shows
  `/?kofi_webhook=1` (`AdminSettings::webhook_url()`)

## Public hooks (since 1.6.0)

Documented for users in README "For Developers: Hooks"; keep that table in step with the code.

- Every filter and action goes through `MembersForKofi\Hooks`: a throwing filter yields its default,
  a throwing action is skipped, so another plugin cannot turn a payment into a 500
- Callbacks get `Hooks::payload( $body )`, which strips the verification token
- A filtered role still passes `validate_role()`. `members_for_kofi_new_user_args` can never set
  `role` or `user_email`; both are put back after the filter
- A payment refused by `members_for_kofi_create_account` is not marked delivered, so a redelivery
  after the block is lifted is processed
- Hooks from `create_account` onwards run inside the per-email lock; document new ones as such
- Do not rename the existing filters: other plugins depend on them

## Testing

Only `tests/Integration/` drives the site over real HTTP (`data=<json>` form-encoded), so anything
touching raw body handling must be covered there. A unit test that passes an array to
`Webhook::handle()` skips `php://input` entirely.
