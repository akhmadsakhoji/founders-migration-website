# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- Cancelling a backup (browser, `wp fmw cancel`, or a failed scheduled run) now also deletes its unfinished `.fmw.partial` archive in the backups folder.

### Added

- Scheduled backups: `wp fmw schedule list|add|update|delete|enable|disable|run` and a Schedules screen. Hourly, daily, weekly or monthly in the site time zone (daylight saving safe, missed runs are not caught up), the backup exclusions, an optional password (stored sealed), retention per schedule (`--keep`, only that schedule's backups are deleted) and e-mail on failure, always or never.
- Background jobs without a browser: a WP-Cron event every five minutes works one slice, then the job continues through non-blocking loopback requests authenticated with the job token (`fmwp_background_request` filter for staging sites behind HTTP auth). Interrupted scheduled backups are continued (at most 12 times), failed ones are recorded, reported and their partial files deleted. `wp fmw schedule run` does the same from a system cron, in the foreground.
- No scheduled backup starts, and "Run now" is refused, while a restore or reset is unfinished; retention never deletes a backup an unfinished restore reads. A job that makes no progress in 12 attempts is failed; each ended job is recorded exactly once.
- Schedules live in `fmw-storage/schedules.json` (atomic writes under a lock, a damaged file is never overwritten), so a restore, migration or reset leaves them alone. The WP-Cron event exists only while a schedule is enabled and is removed on deactivation and uninstall.

- `wp fmw reset` and a Reset screen (the Reset Hub of All-in-One WP Migration): `--database`, `--media`, `--plugins`, `--themes` or `--all`. A safety backup is made first (`--skip-backup` to skip), the site's domain must be typed to confirm (`--yes` in scripts), and multisite is refused for now.
- Database reset: WordPress's own schema, default options and roles are built in `fmwtmp_*` tables, keeping the site address, title, language, time zone, date formats, permalinks, database-stored salts and the kept users (`--keep-user`, default all administrators; the Reset screen keeps you) as administrators with their sessions. One atomic `RENAME TABLE` replaces every table of the site, plugin tables included, and removes its views; other installs sharing the database are recognised by their own `<prefix>options` table and left alone.
- Files reset, resumable and time-sliced: plugins except FMW, themes except the active one (and its parent), the uploads folder. Symbolic links are removed, never followed; protected FMW folders are kept; a file that cannot be deleted stops the job with its path. Without a database reset, plugins are deactivated first and media library entries (attachments, their meta, term links and featured-image references) are removed.

- Password encryption of `.fmw` backups (format v1, section 7): `wp fmw backup --password` and "Protect this backup with a password" on the Export screen. Parts and manifest are AES-256-CBC in the OpenSSL `enc` format with PBKDF2-SHA256 (600,000 iterations) and HMAC-SHA256. Database part names drop the table name, and the file name drops the domain. Encryption streams into the container with one read-back per part for its checksums, and resumes mid-part.
- Encrypted restores: the password is checked against the manifest HMAC before anything happens; parts are checked (SHA-256 from the authenticated manifest) and then decrypted resumably. `wp fmw restore|verify|inspect --password`, and a password field in the restore confirmation.
- Job secrets (backup passwords, `.wpress` keys) are stored sealed with AES-256-GCM, using a key derived from the site's AUTH salt, and removed when a job completes or is cancelled.

- Admin screens: Export (the same exclusions as `wp fmw backup`, a progress dialog with step, bytes, speed and remaining time, then Download), Import (drag and drop of `.fmw` or `.wpress` files, chunked uploads of any size, then a restore confirmation with a password field for encrypted `.wpress`) and Backups (download, restore and delete, `ai1wm-backups` listed read-only, unfinished jobs to continue or cancel).
- Resumable uploads: each chunk carries its offset and is accepted only at the end of the partial file. Choosing the same file again after a dropped connection or a closed tab continues the upload. The chunk size follows `post_max_size` and halves when a proxy answers 413.
- Resumable downloads through `admin-post.php` with HTTP byte ranges; the backups folder stays closed to the web.
- REST API `fmw/v1` (backups, uploads, jobs). Every route needs the plugin capability, except running, reading and cancelling a job, which also accept the job's own token (hashed on disk). This lets a restore finish after it replaced the users table. Only one job runs at a time.
- `fmwp_web_slice_seconds` filter: how long one browser request works on a job (default 20 s, less when `max_execution_time` is lower).

- `.wpress` import: `wp fmw restore <file>.wpress [--password=<password>]` restores All-in-One WP Migration backups, plain, encrypted (AES-256-CBC) or gzip / bzip2 compressed, from older (7.85, no checksums) and current (7.111, CRC-32) versions. Files are looked up in `wp-content/ai1wm-backups` too.
- `.wpress` restore order for safety: every header and the archive CRC-32 are checked first, then `database.sql` is imported into `fmwtmp_*` tables, and only then are files written (each checked against its CRC-32). Resumable in the middle of large files.
- All-in-One WP Migration's `SERVMASK_PREFIX_` placeholders are mapped back: tables to the target prefix, option names and user meta keys to their real names; its URL, path, uploads URL and export-time find / replace values are applied with the serialized-safe replacer.
- `wp fmw inspect` and `wp fmw verify` accept `.wpress` files (verify needs no password).
- `docs/wpress.md`: the `.wpress` layout as FMW reads it.
- SQL dumps may contain `START TRANSACTION`, `COMMIT` and `SET autocommit`; they are ignored because the importer manages its own transactions.

- `wp fmw restore <file>`: resumable restore of `.fmw` backups (check, restore parts, replace, swap, finish) with `--yes`, `--keep-old-tables`, `--exclude-email-replace`, `--skip-space-check` and `--[no-]progress`.
- Restore safety: SHA-256 check of every part before use, free disk space check, import into `fmwtmp_*` tables and one atomic `RENAME TABLE` (previous tables kept as `fmwold_*` until the end), exactly-once SQL import and replacement through a progress table committed with the data.
- Serialized-safe search-replace for URLs (plain, protocol-relative, JSON-escaped and URL-encoded), paths and e-mail domains; nested serialized strings are re-measured without `unserialize()`; table prefix changes applied to `user_roles` and user meta keys.
- `SqlGuard`: allowlist for SQL from archives (session `SET`, `DROP`/`CREATE TABLE`, literal-only `INSERT`); views and triggers are recreated after the swap with the target prefix.
- Streaming SQL reader with byte offsets for resume, `DELIMITER` support and multi-member gzip.
- `wp fmw cleanup --tables`: drops leftover `fmwtmp_*` and kept `fmwold_*` tables.
- The FMW plugin folder, backups and storage are protected during restore and the plugin stays active; symlinks that point outside the site are skipped.

- `wp fmw backup`: complete, resumable backups into `.fmw` archives (scan, database, files, package), with the `wp ai1wm backup` exclusion flags plus `--exclude-transients`, `--exclude-tables`, `--exclude-paths`, `--part-size` and `--porcelain`.
- Package step: writes `fmw.json`, database parts, file parts and `manifest.json` into the TAR container as `<name>.fmw.partial` and renames it when complete; parts are deleted as they are packed, so a backup needs little more than one copy of the site on disk.
- `wp fmw inspect`: site, versions, totals and exclusions from the manifest without reading the parts (`--format=json` for the full manifest).
- `wp fmw verify`: SHA-256 check of every part against the manifest, reporting corrupt, missing, duplicate and unexpected entries.
- Archive reader that refuses newer format versions with a clear message.

- Export database step: plain-SQL dump per table in `database/NNNN-<table>.CCCC.sql.gz` chunks, restorable with the stock `mysql` client. Keyset pagination on the primary key (or a NOT NULL unique key, else LIMIT/OFFSET), consistent snapshot per process, resumable mid-table, binary values as hex, BIT read as numbers, generated columns left out, views and triggers without DEFINER (triggers restored after the data).
- Database row exclusions matching `wp ai1wm backup`: spam comments, post revisions, transients (subsite tables included), plus `exclude_tables`.
- Own mysqli connection built from `DB_HOST` (host, port, socket and IPv6 forms); credentials are never written to job state.
- CI starts MySQL 8 so the database tests run against a real server; they are skipped when none is reachable.

- Export scan step: resumable depth-first walk of wp-content into an on-disk file list (constant memory for millions of files); symlinks recorded, never followed; non-UTF-8 names kept byte-exact.
- Export files step: packs files into format-v1 parts (`.tar.gz` for compressible files, `.tar` for media), rotates at the part size without ever splitting a file, resumes in the middle of large files, records SHA-256 per part, skips files deleted after the scan and logs files that changed size.
- Exclusions matching `wp ai1wm backup`: cache, media, themes, inactive themes, mu-plugins, plugins, inactive plugins, and path patterns. The FMW backups and storage folders and WordPress `upgrade` folders are never included.
- `tools/pack-dir.php` now runs the real scan and files steps as a job: Ctrl+C and `--resume=<job id>` work outside WordPress.

### Changed

- Backups no longer include `wp-content/ai1wm-backups`.
- `.wpress` restores run the search-replace before writing files, so files and database disagree for as short a time as possible.
- The runner reloads a job's state after taking its lock, so a retried request never continues from an outdated copy.
- A cancelled job no longer keeps a `.wpress` decryption key.
- `wp fmw list-backups` also lists `.wpress` files in `wp-content/ai1wm-backups`; `wp fmw delete` refuses to delete them.

- A job slice always performs at least one unit of work, so jobs progress even when a web request's time budget is nearly spent.
- Job engine: resumable jobs with atomic `state.json` checkpoints, per-step cursors, an exclusive lock with heartbeat and stale-holder takeover, time-boxed slices for web requests, graceful Ctrl+C / SIGTERM, and cancellation.
- WP-CLI: `wp fmw jobs`, `resume`, `cancel`, `log`, `cleanup`, with exit codes 0 (done), 1 (failed) and 3 (stopped, resumable).
- Terminal progress bar with percentage, bytes, speed (30-second moving average) and ETA; plain lines every 5% when output is not a terminal.
- `fmwp_register_job_types` action for registering job types.

- Phase 0 foundation.
- Archive library: PAX TAR writer and reader, files above 8 GiB, UTF-8 and long paths, anonymous owner fields.
- Multi-member gzip sink with commit points, so interrupted parts resume by truncating to the last commit.
- File slices, so one large file can be written across several requests.
- Safe extractor that rejects path traversal, escaping symlinks and writes through symlinked directories.
- Protected data folders (`fmw-backups`, `fmw-storage`) with an HTTP exposure check.
- Admin screens: Export, Import, Backups.
- WP-CLI: `wp fmw list-backups`, `delete`, `status`, and placeholders for the phase 1 commands.
- Archive format specification v1 (`docs/format-v1.md`).
- Developer tools: synthetic site generator and part packer.
- CI: lint, PHPCS, PHPStan and PHPUnit on PHP 7.4–8.4, plus GNU tar and bsdtar compatibility.
