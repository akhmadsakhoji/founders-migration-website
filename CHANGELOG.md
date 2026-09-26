# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.1] - 2026-09-26

### Added

- After a restore, a new "Server" step makes the site work on its server (`fmwp_server_fixes` filter to switch parts off):
  - Adds WordPress's permalink rules (`# BEGIN WordPress`, or the network rules on a multisite network) to the root `.htaccess` when they are missing (an empty block counts as missing). A backup never carries that file, so restores onto a fresh CyberPanel / OpenLiteSpeed or Apache site answered 404 on every page but the home page.
  - Gives what a restore run as root (WP-CLI) wrote in `wp-content` (and custom uploads, plugins or themes folders), the root `.htaccess` and FMW's folders to the owner of `wp-config.php` or the site folder, so WordPress can write uploads and updates. Only entries owned by root and changed since the restore started; links are never followed, and folders outside the site that do not belong to it are not walked.
  - Empties Elementor's generated CSS and element caches, rebuilt on the next visit with the new URLs.
  - Purges the LiteSpeed page cache on the next uncached request.
  - Requests an unknown address from this server (127.0.0.1, whatever the DNS says) and, when the web server answers with its own 404 page, ends the restore with what to do (on OpenLiteSpeed: make sure the site's `vhost.conf` has `autoLoadHtaccess 1`, then `systemctl restart lsws`).
- Notes from a restore are shown after it: as warnings in WP-CLI and in the restore dialog.

### Fixed

- Plugin Check (WordPress.org rules) passes again; it had failed in CI since 1.0.0. Exception messages are marked as plain text file by file (they reach WP-CLI, logs and JSON, and the admin screens insert them as text), and PHP_CodeSniffer now enforces the rule instead of switching it off for the whole plugin. The S3 endpoint is marked as the user's own storage, `load_plugin_textdomain()` is gone (WordPress loads translations itself since 4.6), and the Google Drive account shown after connecting is sanitized first.

## [1.0.0] - 2026-09-26

First public release. The development history before it is in the [pull requests](https://github.com/akhmadsakhoji/founders-migration-website/pulls?q=is%3Apr+is%3Amerged) and the git log.

### Backup

- `wp fmw backup` and the Export screen make resumable `.fmw` backups: a TAR container of `.tar.gz` / `.tar` file parts (1 GiB by default, 128 MiB to 4 GiB), gzip-compressed plain SQL per table and a JSON manifest with a SHA-256 per part. Restorable by hand with `tar`, `gunzip` and `mysql` ([format specification](https://github.com/akhmadsakhoji/founders-migration-website/blob/main/docs/format-v1.md), CC BY 4.0).
- Built for very large sites: constant-memory file scan, keyset-paginated database dump with a consistent snapshot, files larger than 8 GiB, resumable in the middle of a table or a file, parts deleted as they are packed (little more than one copy of the site on disk).
- Exclusions of `wp ai1wm backup` (spam comments, revisions, media, themes, plugins, inactive ones, must-use plugins, cache, database) plus transients, tables (`--exclude-tables`), path patterns (`--exclude-paths`) and the part size.
- Password protection: AES-256-CBC (OpenSSL `enc` format) with PBKDF2-SHA256 (600,000 iterations) and HMAC-SHA256; the manifest, table names and the file name are hidden too.

### Restore

- `wp fmw restore` and the Import / Backups screens restore `.fmw` and All-in-One WP Migration `.wpress` backups (plain, encrypted, gzip or bzip2 compressed, versions 7.85 to 7.111) onto the same or another address, path or table prefix.
- Safety: every part is checked before use; the database goes into `fmwtmp_*` tables and live with one atomic `RENAME TABLE` (the previous tables are kept as `fmwold_*` until the end, or with `--keep-old-tables`); SQL from backups is checked against an allowlist; the plugin, its folders and other installs in a shared database are never touched.
- Serialized-safe search-replace of URLs, paths and e-mail domains without `unserialize()`, matching whole addresses only.
- Resumable uploads of any size in the browser (continue by choosing the same file again) and resumable downloads with byte ranges.

### Multisite

- Whole networks back up and restore onto a network of the same kind at any address, `.fmw` or `.wpress` (Multisite Extension); subsites move with the network's address, and those with their own domain can be mapped with `--map`.
- One site of a network backup restores onto a single site (`--site`), a single-site backup becomes a new or existing site of a network, and sites picked from a `.wpress` network backup restore into a network, with users merged and authors kept.
- Reset of one site or of the whole network.

### Reset

- `wp fmw reset` and the Reset screen bring the database, media, plugins or themes back to a fresh install, after a safety backup and a typed confirmation, keeping the address, title, language and the chosen administrators.

### Schedules and cloud storage

- Scheduled backups (hourly, daily, weekly, monthly in the site time zone) with retention, e-mail and optional password, driven by WP-Cron (on a network, the main site's) or a system cron (`wp fmw schedule run`); interrupted runs continue in the background.
- Cloud storage on Amazon S3 and S3-compatible services (Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO, others) and Google Drive with your own OAuth client: resumable uploads and downloads, retention per schedule, credentials stored encrypted.

### Pull

- `wp fmw pull` and the Pull screen copy another site, or a network onto a network, server to server in one resumable job, authorised by a short-lived pull key (`wp fmw pull-key`) that can be limited to addresses ([protocol](https://github.com/akhmadsakhoji/founders-migration-website/blob/main/docs/pull-v1.md)).

### Operations

- Every backup, restore, reset, upload and pull is a job that checkpoints its position: continue after Ctrl+C, a lost SSH session, a timeout or a server restart (`wp fmw jobs`, `resume`, `cancel`, `log`, `cleanup`). Real progress with speed and time left in the browser and the terminal.
- Data folders protected from the web, with an HTTP check and a warning where the server ignores `.htaccess`; both can be moved outside the web root.
- Deleting the plugin removes its settings, credentials, jobs and logs, never backups.
- Requirements: 64-bit PHP 7.4 or newer, WordPress 6.0 or newer.

[Unreleased]: https://github.com/akhmadsakhoji/founders-migration-website/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/akhmadsakhoji/founders-migration-website/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/akhmadsakhoji/founders-migration-website/releases/tag/v1.0.0
