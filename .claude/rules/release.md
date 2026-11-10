---
paths:
  - "Makefile*"
  - ".releaseignore"
  - "readme.txt"
  - "members-for-kofi.php"
---

# Release process

```bash
make release            # package the zip (dev files excluded via .releaseignore)
# In the untracked Makefile.local (push rights / WordPress.org credentials);
# `make help` says whether it is loaded:
make git-tag            # tag v{VERSION} (clean main branch), then push it
make github-release     # package + tag + GitHub release (gh CLI)
make deploy-svn         # stage into WordPress.org SVN (builds production vendor)
make commit-svn WPORG_USER=username WPORG_PASS=password
```

- `make release` builds `vendor/` fresh with `--no-dev` in a staging directory rather than copying
  the working tree's, which carries the whole test toolchain
- The version comes from the `* Version:` header in `members-for-kofi.php`; SVN trunk gets
  `composer install --no-dev --optimize-autoloader`

## Order: dev site → production → WordPress.org (MANDATORY)

Every site that updates from WordPress.org gets a release at once, so publishing there is the
**last** step.

1. **Dev site.** It runs the working tree, so it has run the release all along
2. **Production, from the tested package.** After commit, tag and push, build with
   `make release OUT_DIR=<dir>` and install that exact zip, not one from WordPress.org. Back up
   first (`wp option get members_for_kofi_options --format=json` to a private location; it can hold
   the token). Then watch an uncached page, a wrong-token webhook probe (expect 401) and the MySQL
   processlist for long-running queries
3. **WordPress.org.** Only once production runs cleanly: `make deploy-svn`, check SVN trunk matches
   production, `make commit-svn`, then check the published zip matches the tag
4. **GitHub release**, with the WordPress.org package attached

**Why:** 1.3.0 went to WordPress.org first and production updated from it. Its upgrade never
finished on production and jammed the site for ~12 minutes, while every site updating from
WordPress.org was exposed at the same moment. The dev site did not catch it (half the data, almost
no traffic), so dev passing is necessary, not sufficient: upgrade code on `init` must also be timed
against production-sized data.
