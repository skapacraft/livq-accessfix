# Contributing to LivQ AccessFix

This is maintained by one person, so the most useful contribution is usually a
precise report rather than a large patch. Everything below exists to make a
change reviewable, not to add ceremony.

## Before anything else

A security problem does not belong in a public issue. Use the **Security** tab,
then **Report a vulnerability**. What counts as one here is in
[SECURITY.md](SECURITY.md).

## Reporting a bug

Open an issue with the bug template. What makes a report actionable:

- the exact steps, from a clean state, and what you expected instead
- the version, and the platform it ran on
- a file or a screenshot when the trigger is one specific input

If the input holds personal data, describe how to build an equivalent one
rather than attaching it.

## Suggesting a feature

Open an issue with the feature template and describe the problem before the
solution. A feature that does not pull its weight does not ship: that is the
project's standard, not a rejection of the idea.

## Pull requests

Open an issue first for anything beyond a typo or a one-line fix, so the design
is agreed before the work happens.

Once that is settled:

1. Branch from `main`.
2. Keep the change to one concern. Two unrelated fixes are two pull requests.
3. Add a `CHANGELOG.md` entry, and bump the version where the project keeps it.
4. Verify it, and say in the pull request how you did.

## Building and checking locally

```bash
composer install
composer phpcs      # WordPress Coding Standards, must come out clean
composer phpcbf     # fixes what it can automatically
```

CI runs the same PHPCS ruleset plus a syntax check on PHP 7.4 through 8.3, on
every push. A pull request that does not pass PHPCS will not be merged.

## Translations

Community translations live on
[translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/livq-accessfix/),
and that is where to submit one. On a site installed from WordPress.org, those
are what load: WordPress fetches them into `wp-content/languages/plugins/` and
they win.

The `languages/` folder here is a deliberate second path, not a duplicate. That
automatic loading only happens for an install that came from WordPress.org, so a
site running the plugin from a zip or from git would otherwise get nothing. For
those, `load_textdomain()` on `init` priority 0 picks up the bundled `.mo`. Where
WordPress has already loaded a community translation, that call is a no-op.

So: a `.po` or `.mo` in a pull request is fine when it is a language already
bundled and the change is a fix. A new language belongs on
translate.wordpress.org first.

## House rules

- **English only**, in code comments, commit messages and user-facing strings.
- **Commit messages** say what changed and why. The subject line is imperative
  and under 72 characters, the body wraps at 72 and explains the reasoning that
  is not obvious from the diff.
- **No generated or vendored files** in a commit unless the project already
  tracks them.
- **No credentials, keys or personal data**, including in test fixtures.

## Licence

By contributing you agree that your work is distributed under the licence in
[LICENSE](LICENSE).
