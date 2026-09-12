# Security Policy

## Reporting a vulnerability

Please report security issues **privately**, using GitHub's private vulnerability reporting:

**[Report a vulnerability](https://github.com/trudslev/members-for-kofi/security/advisories/new)**

Please do not open a public issue for a security problem. This plugin assigns
WordPress roles based on incoming payments, so a public report describes a
working attack against every site running it until a fix ships and those sites
update — which, for a WordPress.org plugin, takes considerably longer than
releasing the fix does.

If GitHub is not an option for you, contact the author via
[foodgeek.io](https://foodgeek.io) and ask for a private channel.

## What to include

Whatever you have. The most useful things are:

- the plugin version, WordPress version and PHP version
- what an attacker can do that they should not be able to do
- the steps or the request that demonstrates it
- whether it requires an account on the site, and at what role

A proof of concept is welcome but not required. A clear description of the
weakness is more useful than a working exploit.

## Scope

**In scope:** the plugin's own code — the webhook endpoint, the admin screens
and their AJAX handlers, the logging tables, the cron jobs, and the role
assignment and expiry logic.

**Out of scope:**

- Ko-fi's own platform and its webhook delivery. Report those to Ko-fi.
- WordPress core, other plugins, themes, and server or hosting configuration.
- Anything that requires an administrator account. An administrator can already
  change roles, run code and edit plugin files; needing that access first is not
  a privilege escalation.
- Missing hardening with no demonstrable impact.

The webhook endpoint is deliberately reachable without authentication — Ko-fi
has to be able to POST to it. It is not a finding that the URL responds; it is a
finding if a request *without the correct verification token* can change
anything.

## Supported versions

Security fixes are made to the current release. This is a small plugin with a
single maintainer, so older versions are not patched — updating to the latest
release is the upgrade path.

| Version | Supported |
| --- | --- |
| Latest release | Yes |
| Anything older | No — please update |

## What happens next

You will get an acknowledgement that the report arrived. From there the aim is
to confirm the issue, fix it, release it to WordPress.org, and credit you in the
changelog unless you would rather not be named.

Details are kept private until a fixed version is available and users have had a
reasonable chance to update.
