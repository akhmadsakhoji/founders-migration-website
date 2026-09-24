# Founders Migration Website

Backup, restore and migrate WordPress sites up to 100 GB and beyond — with open, standard archive formats.

Founders Migration Website (FMW) works like All-in-One WP Migration: the same Export / Import / Backups screens and the same WP-CLI commands (`wp fmw` instead of `wp ai1wm`). Underneath it is built for very large sites:

- **Resumable everywhere.** Backup, upload and restore continue from the last checkpoint after a timeout, a dropped SSH session or a server restart.
- **Standard formats, no lock-in.** An `.fmw` file is a TAR archive of TAR/gzip parts, gzip SQL and a JSON manifest. You can restore it by hand with `tar`, `gunzip` and `mysql` if the plugin or WordPress is broken. See [docs/format-v1.md](docs/format-v1.md).
- **Honest progress.** Percentage, speed and ETA in both the browser and the terminal.
- **Imports `.wpress`.** Existing All-in-One WP Migration backups can be restored.
- **Free and open.** Base, "unlimited" and multisite features are all in one GPL plugin.

> **Status: phase 1 in progress.** `wp fmw backup`, `verify` and `inspect` work and produce complete `.fmw` archives. `wp fmw restore` is next. Test on staging sites before relying on it in production.

## Requirements

| | Minimum | Recommended |
|---|---|---|
| PHP | 7.4, **64-bit** | 8.3 or newer |
| PHP extensions | zlib, hash, mysqli, json | openssl (encrypted backups), pcntl (clean Ctrl+C in WP-CLI) |
| WordPress | 6.0 | Latest |
| MySQL / MariaDB | 5.7 / 10.3 | 8.0 / 10.11 |
| WP-CLI | 2.5 | Latest |

The plugin is written in plain PHP and does not call `exec()` or system binaries, so it runs on shared hosting too.

## Installation

Until the first release on WordPress.org:

```bash
cd wp-content/plugins
git clone https://github.com/akhmadsakhoji/founders-migration-website.git
wp plugin activate founders-migration-website
wp fmw status
```

## Command line

```bash
wp fmw backup --exclude-cache --exclude-post-revisions
wp fmw list-backups
wp fmw inspect <file>        # what is inside, without reading the parts
wp fmw verify <file>         # SHA-256 check of every part
```

Backup flags follow `wp ai1wm backup`: `--exclude-spam-comments`, `--exclude-post-revisions`, `--exclude-media`, `--exclude-themes`, `--exclude-inactive-themes`, `--exclude-muplugins`, `--exclude-plugins`, `--exclude-inactive-plugins`, `--exclude-cache`, `--exclude-database`. FMW adds `--exclude-transients`, `--exclude-tables=a,b`, `--exclude-paths="uploads/old/*"`, `--part-size=512M` and `--porcelain`.

Commands and flags mirror `wp ai1wm`. Add `alias fmw='wp fmw'` to `~/.bashrc` to type `fmw backup`.

| Command | ai1wm equivalent | Available |
|---|---|---|
| `wp fmw list-backups` | `wp ai1wm list-backups` | now |
| `wp fmw delete <file>` | — | now |
| `wp fmw status` | — | now |
| `wp fmw backup` | `wp ai1wm backup` | now |
| `wp fmw restore <file>` | `wp ai1wm restore <file>` | phase 1 (`.wpress`: phase 2) |
| `wp fmw jobs` | — | now |
| `wp fmw resume <job_id>` | — | now |
| `wp fmw cancel <job_id>` | — | now |
| `wp fmw log <job_id>` | — | now |
| `wp fmw cleanup` | — | now |
| `wp fmw verify <file>` | — | now |
| `wp fmw inspect <file>` | — | now |
| `wp fmw reset` | `wp ai1wm reset` | phase 2 |
| `wp fmw pull <url>` | — | phase 3 |

Every backup and restore runs as a **job** that checkpoints its position. Press Ctrl+C, lose the SSH session or hit a server restart, then continue where it stopped:

```bash
wp fmw jobs                  # find the job ID
wp fmw resume <job_id>       # exit 0 = done, 1 = failed, 3 = stopped again (resumable)
```

## Where data lives

| Folder | Contents |
|---|---|
| `wp-content/fmw-backups/` | Finished `.fmw` backups |
| `wp-content/fmw-storage/` | Job state, logs and temporary parts |

Move both outside the web root (recommended on a VPS) in `wp-config.php`:

```php
define( 'FMWP_BACKUPS_PATH', '/home/example.com/fmw-backups' );
define( 'FMWP_STORAGE_PATH', '/home/example.com/fmw-storage' );
```

Both folders get `index.php`, `.htaccess` and `web.config`. Nginx and OpenLiteSpeed usually ignore `.htaccess`, so FMW also checks over HTTP whether the backups folder is reachable and warns you if it is. For Nginx:

```nginx
location ~* /wp-content/fmw-(backups|storage)/ { deny all; }
```

Deleting the plugin removes its settings but never your backups.

## Development

```bash
composer install
composer check      # lint + PHPCS (WordPress standards) + PHPStan level 6 + PHPUnit
composer test
composer package    # build/founders-migration-website.zip without dev files
```

Useful tools:

```bash
# Generate a fake 10 GB wp-content with edge cases (long paths, UTF-8 names, >8 GiB file).
php tools/synthetic-site.php --out=/tmp/site --size=10G

# Pack it into format-v1 parts with the real backup steps (Ctrl+C, then --resume=<job id>).
php tools/pack-dir.php --src=/tmp/site/wp-content --jobs=/tmp/fmw-jobs
```

Code layout:

```
founders-migration-website.php   plugin header and bootstrap
constants.php, functions.php     FMWP_* constants and fmwp_* helpers
loader.php                       autoloader: Founders\Migration\Archive\TarWriter -> lib/archive/TarWriter.php
lib/archive/                     TAR (PAX) writer and reader, multi-member gzip, safe extractor
lib/storage/                     data folders, protection, backup listing
lib/controller/, lib/view/       admin pages
lib/cli/                         wp fmw
docs/format-v1.md                archive format specification
tests/                           PHPUnit tests
tools/                           developer tools (not shipped)
```

PHP globals use the `fmwp_` / `FMWP_` prefix (WordPress.org requires prefixes of at least four characters). The command, the file extension and the folder names stay `fmw`.

## Roadmap

| Phase | Scope |
|---|---|
| 0 — Foundation | Repository, CI, archive library with tests, synthetic site generator |
| 1 — CLI MVP | Job engine, database dump and restore, serialized-safe search-replace, `backup`, `restore`, `resume`, `verify`, `inspect` |
| 2 — UI and compatibility | ai1wm-style screens, resumable uploads, `.wpress` import, encryption, `reset`, schedules, S3-compatible storage and Google Drive |
| 2b — FMW Tools | Standalone app to inspect, verify, decrypt and extract `.fmw` files without PHP |
| 3 — Pull and multisite | Server-to-server migration, network and subsite scenarios |
| 4 — Public release | WordPress.org, documentation site, translations |

## Contributing and security

- [CONTRIBUTING.md](CONTRIBUTING.md): coding standards and the Developer Certificate of Origin (`git commit -s`).
- [SECURITY.md](SECURITY.md): report vulnerabilities privately, never in public issues.
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

## License

Copyright (C) 2026 PT Founder Media Partner.

Founders Migration Website is free software, licensed under the [GNU General Public License v2.0 or later](LICENSE). The archive format specification in [docs/format-v1.md](docs/format-v1.md) is licensed under CC BY 4.0 so that anyone can implement it.
