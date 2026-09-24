# Founders Migration Website

Backup, restore and migrate WordPress sites up to 100 GB and beyond — with open, standard archive formats.

Founders Migration Website (FMW) works like All-in-One WP Migration: the same Export / Import / Backups screens and the same WP-CLI commands (`wp fmw` instead of `wp ai1wm`). Underneath it is built for very large sites:

- **Resumable everywhere.** Backup, upload and restore continue from the last checkpoint after a timeout, a dropped SSH session or a server restart.
- **Standard formats, no lock-in.** An `.fmw` file is a TAR archive of TAR/gzip parts, gzip SQL and a JSON manifest. You can restore it by hand with `tar`, `gunzip` and `mysql` if the plugin or WordPress is broken. See [docs/format-v1.md](docs/format-v1.md).
- **Honest progress.** Percentage, speed and ETA in both the browser and the terminal.
- **Imports `.wpress`.** Existing All-in-One WP Migration backups (old and new versions, encrypted or compressed) restore with `wp fmw restore`.
- **Free and open.** Base, "unlimited" and multisite features are all in one GPL plugin.

> **Status: phase 2 in progress.** Backup and restore (`.fmw` and `.wpress`), password encryption and reset work end to end and are resumable, both in the admin screens and from WP-CLI. Schedules and cloud storage come next. Test on staging sites before relying on it in production.

## Requirements

| | Minimum | Recommended |
|---|---|---|
| PHP | 7.4, **64-bit** | 8.3 or newer |
| PHP extensions | zlib, hash, mysqli, json | openssl (encrypted backups), bz2 (bzip2-compressed `.wpress`), pcntl (clean Ctrl+C in WP-CLI) |
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

## Admin screens

**Founders Migration** in the admin menu has the same three screens as All-in-One WP Migration:

| Screen | What it does |
|---|---|
| **Export** | The same exclusions as `wp fmw backup`. Progress shows the step, bytes, speed and remaining time. When it finishes you get a Download button. |
| **Import** | Drag and drop a `.fmw` or `.wpress` file of any size. It is uploaded in chunks; if the connection drops or the page is closed, choose the same file again and the upload continues where it stopped. Then you confirm the restore, with a password field for encrypted `.wpress` files. |
| **Backups** | Download (resumable, byte ranges), restore or delete backups. Backups in `wp-content/ai1wm-backups` are listed too; FMW never deletes them. Jobs that stopped part-way (closed tab, timeout, Ctrl+C in WP-CLI) can be continued or cancelled here. |

The browser drives each job in short requests of about 20 seconds, so PHP and proxy timeouts do not matter. Hosts with stricter limits can lower this with the `fmwp_web_slice_seconds` filter. A restore replaces the users table part-way through, so each job has its own random token, stored hashed outside the database. The token lets the browser that started the restore finish it after its login stops being valid. Only one job runs at a time.

## Command line

```bash
wp fmw backup --exclude-cache --exclude-post-revisions
wp fmw list-backups
wp fmw inspect <file>        # what is inside, without reading the parts
wp fmw verify <file>         # SHA-256 check of every part
```

Backup flags follow `wp ai1wm backup`: `--exclude-spam-comments`, `--exclude-post-revisions`, `--exclude-media`, `--exclude-themes`, `--exclude-inactive-themes`, `--exclude-muplugins`, `--exclude-plugins`, `--exclude-inactive-plugins`, `--exclude-cache`, `--exclude-database`. FMW adds `--exclude-transients`, `--exclude-tables=a,b`, `--exclude-paths="uploads/old/*"`, `--part-size=512M` and `--porcelain`.

Password-protected backups (AES-256, PBKDF2 with 600,000 iterations, HMAC):

```bash
wp fmw backup --password               # asked twice without echo; or --password=<password>
wp fmw inspect <file> --password       # without the password only the date is readable
wp fmw verify <file> --password        # SHA-256 and HMAC of every part
wp fmw restore <file> --password
```

Everything that describes the site is encrypted, including the manifest, the database part names and the file name (`backup-<date>-<token>.fmw`). A wrong password is refused before anything happens. While a job runs, its password is kept sealed with a key derived from `wp-config.php`'s AUTH salt, never in plain text, and it is removed when the job ends. Without the plugin, a part decrypts with `openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -md sha256` ([docs/format-v1.md](docs/format-v1.md), section 7).

Restore onto the same site or a new domain, path or table prefix:

```bash
wp fmw restore <file>                  # asks for confirmation; --yes skips it
wp fmw restore <file> --keep-old-tables
wp fmw cleanup --tables                # drop fmwold_* / leftover fmwtmp_* tables
```

All-in-One WP Migration backups restore the same way, found by name in `wp-content/ai1wm-backups` too:

```bash
wp fmw inspect site.wpress             # source site, plugin version, encryption, compression
wp fmw verify site.wpress              # headers + CRC-32 (archives from recent versions), no password needed
wp fmw restore site.wpress --password=<password>   # password only for encrypted backups; asked for when omitted
```

Plain, encrypted (AES-256) and gzip / bzip2 compressed `.wpress` files from both older (7.85) and current (7.111) versions of All-in-One WP Migration are supported; the format is described in [docs/wpress.md](docs/wpress.md). The order is chosen so a bad backup fails early: headers and checksum first, then the database into temporary tables, and only then the files. Multisite `.wpress` backups arrive in phase 3.

How a restore protects the site:

- Every part is checked against its SHA-256 before it is used; a damaged archive stops the restore before anything live changes.
- The database is imported into `fmwtmp_*` tables and put live with **one atomic `RENAME TABLE`**; the previous tables become `fmwold_*` and are dropped at the end (kept with `--keep-old-tables`). Tables of other sites in a shared database are left alone.
- URLs, paths and e-mail domains are replaced for the new location, serialized data included, without running `unserialize()` on backup data. `posts.guid` stays unchanged, as WordPress recommends. A changed table prefix is applied to user roles and user meta keys.
- SQL from the archive is checked against an allowlist (table DDL and literal `INSERT`s only), so a crafted backup cannot run arbitrary queries.
- The FMW plugin folder, backups and storage are never overwritten, the plugin stays active, and files that are not in the backup are kept.

Commands and flags mirror `wp ai1wm`. Add `alias fmw='wp fmw'` to `~/.bashrc` to type `fmw backup`.

| Command | ai1wm equivalent | Available |
|---|---|---|
| `wp fmw list-backups` | `wp ai1wm list-backups` | now |
| `wp fmw delete <file>` | — | now |
| `wp fmw status` | — | now |
| `wp fmw backup` | `wp ai1wm backup` | now |
| `wp fmw restore <file>` | `wp ai1wm restore <file>` | now (`.fmw` and `.wpress`) |
| `wp fmw jobs` | — | now |
| `wp fmw resume <job_id>` | — | now |
| `wp fmw cancel <job_id>` | — | now |
| `wp fmw log <job_id>` | — | now |
| `wp fmw cleanup` | — | now |
| `wp fmw verify <file>` | — | now |
| `wp fmw inspect <file>` | — | now |
| `wp fmw reset` | Reset Hub | now (single site) |
| `wp fmw pull <url>` | — | phase 3 |

`wp fmw reset --database --media --plugins --themes` (or `--all`, and the Reset screen) brings parts of a site back to a fresh WordPress install:

- A backup of the whole site is made first (unless `--skip-backup`), so a reset can be undone with `wp fmw restore`.
- You confirm by typing the site's domain (`--yes` in scripts).
- The fresh database is built in `fmwtmp_*` tables and switched in with one atomic `RENAME TABLE`. It keeps the site address, title, language, time zone, permalinks and the kept users (all administrators by default, `--keep-user=<id|login|email>`), who stay logged in. This plugin and the active theme stay active.
- Tables and views of other WordPress installs in the same database (another prefix) are not touched. Must-use plugins, drop-ins and `wp-config.php` are never deleted.

Every backup, restore and reset runs as a **job** that checkpoints its position. Press Ctrl+C, lose the SSH session or hit a server restart, then continue where it stopped:

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
