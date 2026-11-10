---
paths:
  - "languages/**"
  - "tests/TranslationTest.php"
---

# Translations

- Bundled translations live in `languages/` (`members-for-kofi-<locale>.po`, compiled with `make mo`)
  and are loaded by `Plugin::load_textdomain()` on `init`. WordPress never loads a plugin's own files
  by itself; a language pack from translate.wordpress.org still wins where one exists
- Danish (`da_DK`) is complete. `TranslationTest` fails if a bundled translation misses a string from
  the template or drops a placeholder: a half-translated file ships a mix of languages to every site
- New strings are translated in every bundled `.po` before release. Show the new strings to the user
  for review before writing tests around them
- `make pot` regenerates the template from this checkout alone
