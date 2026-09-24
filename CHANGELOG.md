# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Export database step: plain-SQL dump per table in `database/NNNN-<table>.CCCC.sql.gz` chunks, restorable with the stock `mysql` client. Keyset pagination on the primary key (or a NOT NULL unique key, else LIMIT/OFFSET), consistent snapshot per process, resumable mid-table, binary values as hex, BIT read as numbers, generated columns left out, views and triggers without DEFINER (triggers restored after the data).
- Database row exclusions matching `wp ai1wm backup`: spam comments, post revisions, transients (subsite tables included), plus `exclude_tables`.
- Own mysqli connection built from `DB_HOST` (host, port, socket and IPv6 forms); credentials are never written to job state.
- CI starts MySQL 8 so the database tests run against a real server; they are skipped when none is reachable.

- Export scan step: resumable depth-first walk of wp-content into an on-disk file list (constant memory for millions of files); symlinks recorded, never followed; non-UTF-8 names kept byte-exact.
- Export files step: packs files into format-v1 parts (`.tar.gz` for compressible files, `.tar` for media), rotates at the part size without ever splitting a file, resumes in the middle of large files, records SHA-256 per part, skips files deleted after the scan and logs files that changed size.
- Exclusions matching `wp ai1wm backup`: cache, media, themes, inactive themes, mu-plugins, plugins, inactive plugins, and path patterns. The FMW backups and storage folders and WordPress `upgrade` folders are never included.
- `tools/pack-dir.php` now runs the real scan and files steps as a job: Ctrl+C and `--resume=<job id>` work outside WordPress.

### Changed

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
