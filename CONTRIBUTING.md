# Contributing

Thank you for helping. Issues and pull requests are welcome.

## Before you start

- For anything larger than a small fix, open an issue first so we can agree on the approach.
- Security problems go through [SECURITY.md](SECURITY.md), never public issues.
- The archive format is specified in [docs/format-v1.md](docs/format-v1.md). Changes to the format need a spec change in the same pull request.

## Setup

```bash
git clone https://github.com/<your-fork>/founders-migration-website.git
cd founders-migration-website
composer install
composer check
```

`composer check` runs the PHP linter, PHP_CodeSniffer (WordPress Coding Standards), PHPStan (level 6) and PHPUnit. CI runs the linter on PHP 7.4 to 8.4, PHPUnit on PHP 7.4, 8.1, 8.3 and 8.4 (against MySQL 8), PHPCS and PHPStan on PHP 8.3, and WordPress.org's Plugin Check on the built plugin.

## Code rules

- **PHP 7.4 syntax.** No `match`, union types, named arguments, `readonly`, enums or `str_contains()` without a polyfill. PHPCompatibility enforces this.
- **WordPress Coding Standards.** Tabs, `snake_case` functions and variables, Yoda conditions, escaped output.
- **Direct access.** Every PHP file in `lib/` starts with `defined( 'ABSPATH' ) || exit;` (the test bootstrap defines `ABSPATH`).
- **Prefixes.** Globals, hooks, script handles and `admin-post` actions use `fmwp_`, `FMWP_` or `fmwp-`. Classes live in the `Founders\Migration` namespace, one class per file: `Founders\Migration\Archive\TarWriter` → `lib/archive/TarWriter.php`.
- **Plain PHP only.** No `exec()`, `shell_exec()`, `proc_open()` or system binaries in plugin code.
- **Archive input is untrusted.** Never call `unserialize()` on archive data. Validate every path with `PathGuard`.
- **Library code stays WordPress-free.** `lib/archive/` must not call WordPress functions, so it can be tested in isolation.
- **Tests.** New behaviour needs a test in `tests/unit/`. Bugs get a failing test first.
- **File header.** Every PHP file starts with the standard header:

```php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
```

## Commits and pull requests

- Keep pull requests focused on one change.
- Write commit messages in English, imperative mood: `Add PAX size header for files above 8 GiB`.
- Update `CHANGELOG.md` under **Unreleased**.
- New or changed translatable strings: regenerate the template with `wp i18n make-pot . languages/founders-migration-website.pot --exclude=tests,tools,vendor,docs,build`.

### Developer Certificate of Origin

Every commit must be signed off, which certifies that you wrote the change or have the right to submit it under the project's license ([developercertificate.org](https://developercertificate.org/)):

```bash
git commit -s -m "Fix symlink check on Windows paths"
```

This adds a line such as `Signed-off-by: Your Name <you@example.com>` to the commit. Pull requests with unsigned commits cannot be merged.

## Releases

1. Set the version in `founders-migration-website.php` (header), `constants.php` (`FMWP_VERSION`) and `readme.txt` (`Stable tag`), move **Unreleased** in `CHANGELOG.md` to the new version, add it to the changelog in `readme.txt`, update the version line near the top of `README.md` and regenerate the translation template.
2. Merge to `main`, and only once the pull request shows **Merged** (`gh pr view <number>`; auto-merge waits for CI) pull `main` and push a tag `vX.Y.Z` on it. The release workflow checks the versions, builds `founders-migration-website.zip` with `git archive` and attaches it to a GitHub release.

## License

By contributing, you agree that your contributions are licensed under the GNU General Public License v2.0 or later, the license of this project.
