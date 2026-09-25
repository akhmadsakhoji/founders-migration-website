# FMW pull protocol, version 1

Status: draft, frozen at the first 1.0.0 release of the plugin.
License of this document: [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).

A pull copies a WordPress site (the **source**) onto another one (the **target**) over HTTPS. The target makes the source create a backup, downloads it in byte ranges, and restores it. The source needs nothing but Founders Migration Website and a **pull key**: no SSH, FTP, WordPress login or WP-CLI, and no WP-Cron, because the target drives the backup.

The key words MUST, SHOULD and MAY are used as in RFC 2119.

## 1. Pull keys

| Rule | Value |
|---|---|
| Format | `fmwpk_<id>_<secret>`: `<id>` 8 lowercase hex characters, `<secret>` 32 random bytes in URL-safe base64 without padding (43 characters) |
| Stored on the source | Only `sha256(<secret>)` (hex), in `fmw-storage/pull-keys.json`, outside the database |
| Lifetime | 1 minute to 30 days (default 24 hours); expired keys are deleted a day later |
| Address limit | Optional list of IPv4/IPv6 addresses and CIDR ranges, checked against `REMOTE_ADDR` (a site behind a trusted proxy can change the address with the `fmwp_pull_client_ip` filter) |
| Scope | Only the routes below. A key can list and download only backups it made, unless it was created with `allow_existing`; it can delete only backups it made |
| Revocation | Deleting the record; the next request fails |

The source MUST compare secrets in constant time and SHOULD throttle addresses that send wrong keys (FMW: 30 failures in 10 minutes block further wrong keys from that address, while a valid key keeps working). `FMWP_DISABLE_PULL` switches the routes off entirely.

## 2. Transport

- Routes live in the WordPress REST API, namespace `fmw/v1`, under `/pull`. Clients SHOULD call them as `<site>/?rest_route=/fmw/v1/pull/...`, which works with and without pretty permalinks.
- The key goes in the `X-FMW-Pull-Key` header (not `Authorization`, which some hosts strip; never in the URL, which ends up in logs).
- Clients MUST use HTTPS with certificate verification, except for local addresses (`localhost`, `127.0.0.0/8`, `::1`, `*.localhost`, `*.local`, `*.test`) or when the user explicitly allows plain HTTP.
- Clients MUST NOT follow redirects silently: a redirect is reported so the user can correct the address.
- Responses, errors included, carry `Cache-Control: no-store, private`. Errors are WordPress REST errors: `{"code": "...", "message": "...", "data": {"status": 4xx}}`.

## 3. Routes

| Route | Purpose | Answer |
|---|---|---|
| `GET /pull` | Check the key, learn about the source | `protocol` (1), `fmw` (version), `format` (1), `site` (`home_url`, `name`, `wp_version`, `php_version`, `multisite`), `key` (`name`, `expires_at`, `allow_existing`), and with `allow_existing` a `backups` list (`name`, `size`, `mtime`) |
| `POST /pull/backups` | Start a backup | Body: `flags` (the exclusion flags of `wp fmw backup`: `exclude-media`, `exclude-tables`, `part-size`, ...; others are ignored) and optionally `password` (the backup is then encrypted while it waits on the source). Answer `201` with a job summary. A key has at most one unfinished job: while one exists (failed ones included), the same summary comes back with `200`, so a repeated start never makes a second backup. A key that left 3 backups on the source gets `409` (`fmw_pull_limit`) until it deletes them |
| `POST /pull/jobs/<id>/run` | Work one time slice (up to about 20 s) | Job summary |
| `GET /pull/jobs/<id>` | Job status | Job summary |
| `POST /pull/jobs/<id>/cancel` | Cancel the backup | Job summary |
| `GET /pull/backups/<name>` | Size and version of a backup | `name`, `size`, `mtime`, `etag` |
| `GET /pull/backups/<name>/file` | The bytes, one range per request | `206` with `Content-Range` (or `200` without `Range`), `ETag`; `If-Match` that names neither this tag nor `*` gives `412`; `HEAD` sends the headers only |
| `DELETE /pull/backups/<name>` | Delete a backup the key made | `{"deleted": true}` |

A backup becomes the key's when any of its routes sees the job completed. Revoking a key, or its expiry, cancels its unfinished jobs.

A job summary is `id`, `status` (`running`, `completed`, `failed`, `cancelled`), `phase`, `progress` (0 to 1), `bytes_done`, `bytes_total`, `error`, and once completed `backup` (`name`, `size`). Jobs and backups of other keys answer `404`. When another backup or restore runs on the source, starting or running answers `409` (`fmw_busy`); clients SHOULD wait and retry.

## 4. Flow on the target

1. `GET /pull`: refuse a different `protocol`, a multisite source (not supported in version 1), and the target's own address.
2. `POST /pull/backups`, sent once (a retry could start a second backup). Checkpoint the job id.
3. `POST /pull/jobs/<id>/run` until `completed`. `failed` stops (a later resume runs the same job again); `cancelled` or `404` forgets the job, so a resume starts a new backup.
4. `GET /pull/backups/<name>`, reserve a local file, checkpoint, then download ranges with `If-Match`, sizing them from the measured speed. A restart truncates the file to the last checkpoint, or to the file's real size when a crash lost writes (never fill a gap). `412` means the file changed: download it again.
5. Open the archive (and check the password of an encrypted one).
6. Prove the copy intact before touching the source: a restore checks every part against its SHA-256 before use (format v1); a download-only pull checks every part the same way. Only then `DELETE /pull/backups/<name>`, unless the user keeps it. Without the password of an encrypted backup the parts cannot be checked, and the source's copy is kept.

If the target cancels, it cancels the source's job and deletes the backup it made. The key is sealed in the target's job (AES-256-GCM with the site's salts) and removed when the job ends or is cancelled, before any cleanup on the source. A job can continue with a new key (the old one expired or was revoked); the source's backup job then starts again, unless the new key may download existing backups.

## 5. Compatibility

- A client MUST refuse a `protocol` it does not implement. Additive changes (new optional fields, new routes) keep version 1.
- Unknown JSON fields MUST be ignored.
