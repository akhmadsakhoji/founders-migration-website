# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

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
