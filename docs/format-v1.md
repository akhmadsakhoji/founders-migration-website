# FMW archive format, version 1

Status: stable since version 1.0.0 of the plugin. Changes are backward compatible; anything that is not gets a new format version.
License of this document: [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). Anyone may build readers and writers for this format, under any license.

An `.fmw` file is a standard TAR archive that contains other standard files: TAR parts (optionally gzip-compressed), gzip-compressed SQL, and a JSON manifest. Every piece can be opened with `tar`, `gzip`, `mysql` and `openssl`, so a backup stays recoverable even without the plugin or a working WordPress.

The key words MUST, SHOULD and MAY are used as in RFC 2119.

## 1. Container

| Rule | Value |
|---|---|
| Container | POSIX.1-2001 (PAX) TAR, no outer compression |
| Extension | `.fmw` |
| File name | `<domain>-<YYYYMMDD>-<HHMMSS>-<token>.fmw`, `<token>` = 6 random lowercase hex characters. Encrypted backups use `backup-<YYYYMMDD>-<HHMMSS>-<token>.fmw` so the name reveals no domain |
| First entry | `fmw.json` |
| Last entry | `manifest.json`, or `manifest.json.enc` when encrypted |
| Directory mode | The same entries as plain files in a folder instead of one TAR. The plugin writes TAR containers only; readers MAY also accept a folder (FMW Tools does) |

Readers locate `manifest.json` by walking the TAR headers from the start and seeking over entry data. This needs one 512-byte read per entry and never reads part contents, so it stays fast for a 100 GB archive.

```
example.com-20260924-180000-a1b2c3.fmw
├── fmw.json
├── database/
│   ├── 0001-wp_options.0001.sql.gz
│   ├── 0002-wp_posts.0001.sql.gz
│   ├── 0002-wp_posts.0002.sql.gz
│   └── ...
├── files/
│   ├── part-0001.tar.gz
│   ├── part-0002.tar
│   ├── ...
│   └── root.tar.gz            (reserved, see section 5)
└── manifest.json
```

## 2. TAR profile

Applies to the container and to every file part.

- Headers are ustar. A PAX extended header (`x`) precedes an entry when its path or link target is longer than 100 bytes or not printable ASCII (`path`, `linkpath`), or when its size exceeds 8 GiB − 1 (`size`). Writers MUST NOT emit global PAX headers (`g`). Readers MUST also accept GNU long names (`L`, `K`) and base-256 numeric fields.
- Paths are UTF-8, use `/`, and are relative. They MUST NOT start with `/`, contain a `..` segment, a backslash, a NUL byte, or a drive letter.
- uid and gid are `0`, uname and gname are empty.
- Mode keeps the permission bits; mtime keeps the source modification time.
- Supported types: regular file (`0`), directory (`5`), symlink (`2`). Symlinks are stored as links and never followed. Hard links and special files are not written.
- Each archive ends with two zero blocks.

## 3. fmw.json

Small, always unencrypted, always the first entry. It identifies the format before anything else is read.

```json
{
  "format": "fmw",
  "version": 1,
  "created_at": "2026-09-24T11:00:00Z",
  "generator": "fmw/1.0.0",
  "encrypted": false
}
```

For encrypted backups it also carries the key derivation parameters and the manifest's authentication tag, and nothing that describes the site:

```json
{
  "format": "fmw",
  "version": 1,
  "created_at": "2026-09-24T11:00:00Z",
  "generator": "fmw/1.0.0",
  "encrypted": true,
  "kdf": { "algorithm": "pbkdf2-sha256", "iterations": 600000 },
  "cipher": "aes-256-cbc",
  "manifest_hmac": "hex…"
}
```

Readers MUST refuse a `version` higher than the one they implement and MUST ignore unknown keys.

## 4. manifest.json

The manifest is the single source of truth for verification and restore.

```json
{
  "format": "fmw",
  "version": 1,
  "created_at": "2026-09-24T11:00:00Z",
  "generator": "fmw/1.0.0",
  "site": {
    "home_url": "https://example.com",
    "site_url": "https://example.com",
    "abspath": "/home/example.com/public_html/",
    "content_dir": "wp-content",
    "uploads_dir": "wp-content/uploads",
    "table_prefix": "wp_",
    "multisite": false,
    "sites": [],
    "wp_version": "7.0",
    "php_version": "8.3.12",
    "db": { "engine": "mariadb", "version": "10.11.8", "charset": "utf8mb4", "collate": "utf8mb4_unicode_520_ci" },
    "active_plugins": ["woocommerce/woocommerce.php"],
    "template": "astra",
    "stylesheet": "astra-child"
  },
  "options": {
    "exclude": ["cache", "spam-comments"],
    "exclude_tables": [],
    "exclude_paths": [],
    "include_root_files": false,
    "part_size": 1073741824,
    "encrypted": false
  },
  "totals": { "files": 184213, "tables": 42, "rows": 1250000, "bytes_raw": 98231456789, "bytes_archived": 81234567890 },
  "parts": [
    {
      "path": "database/0002-wp_posts.0001.sql.gz",
      "type": "database",
      "table": "wp_posts",
      "chunk": 1,
      "rows": 250000,
      "compression": "gzip",
      "bytes_raw": 268435456,
      "bytes": 41234567,
      "sha256": "…"
    },
    {
      "path": "files/part-0002.tar",
      "type": "files",
      "compression": "none",
      "entries": 3120,
      "bytes_raw": 1073741824,
      "bytes": 1074790400,
      "sha256": "…"
    }
  ]
}
```

| Field | Required | Meaning |
|---|---|---|
| `format`, `version` | yes | Same as `fmw.json` |
| `site.home_url`, `site.site_url` | yes | Source values for automatic search-replace on restore |
| `site.abspath`, `site.content_dir`, `site.uploads_dir` | yes | Source paths, used to rewrite absolute paths stored in the database |
| `site.table_prefix` | yes | Prefix used in the SQL files |
| `site.multisite`, `site.sites` | yes | `sites` lists `{ "blog_id", "domain", "path" }` for every subsite on a network |
| `site.network` | no | Networks only: `{ "id", "domain", "path", "subdomain", "main_site", "networks" }` (`SITE_ID_CURRENT_SITE`, `DOMAIN_CURRENT_SITE`, `PATH_CURRENT_SITE`, `SUBDOMAIN_INSTALL`, `BLOG_ID_CURRENT_SITE`, number of networks in the install), `null` for single sites; `sites[]` then also carry `network_id`. Older backups leave these out; readers work them out from `sites` (blog 1 is the main site) |
| `site.db` | yes | Used to warn about incompatible collations before restore |
| `options` | yes | What was deliberately left out (`exclude` flags, `exclude_tables`, `exclude_paths` relative to `wp-content/`), so restore does not treat it as missing; `include_root_files` is always `false` for now |
| `totals` | yes | Progress bars and disk space checks (`files`, `bytes_raw`, `bytes_archived`; `tables` and `rows` may be missing in older backups) |
| `parts[].path` | yes | Entry name inside the container |
| `parts[].type` | yes | `database` or `files`. `root-files` is reserved (section 5); version 1.0.0 of the plugin neither writes nor restores it |
| `parts[].compression` | yes | `gzip` or `none`. New values may be added later without a version bump; readers MUST refuse values they do not know |
| `parts[].bytes_raw` | yes | Size before compression (and before encryption) |
| `parts[].bytes` | yes | Size as stored |
| `parts[].sha256` | yes | SHA-256 of the part as stored (ciphertext when encrypted) |
| `parts[].hmac` | when encrypted | HMAC-SHA256 of the stored ciphertext, hex |
| `parts[].table`, `chunk`, `rows` | database parts | Table name as dumped, chunk number from 1, row count |
| `parts[].entries` | file parts | Number of TAR entries |

## 5. File parts

- Paths inside `files/part-NNNN.*` are relative to `wp-content/`, for example `uploads/2025/01/photo.jpg`.
- `files/root.tar.gz` (reserved, part type `root-files`) is meant for files from the WordPress root, relative to it. The plugin does not write or restore it yet; when it does, these rules apply. Only this allowlist may appear: `.htaccess`, `robots.txt`, `ads.txt`, `google*.html`, `BingSiteAuth.xml`. `wp-config.php`, `.user.ini`, `php.ini` and WordPress core files are never included. Restore leaves existing root files alone unless the user passes `--restore-root-files`.
- Two part kinds: `.tar.gz` for compressible files and `.tar` for files that are already compressed. The default list of stored (not recompressed) extensions is: jpg, jpeg, png, gif, webp, avif, heic, mp4, mov, webm, mkv, mp3, m4a, ogg, zip, gz, tgz, bz2, xz, 7z, rar, zst, woff, woff2, pdf.
- Target part size is 1 GiB of uncompressed data by default, configurable from 128 MiB to 4 GiB. A single file is never split across parts; a file larger than the target gets a part of its own.
- Implementation note: PHP's `gzdecode()` stops after the first gzip member. Readers in PHP must use the `compress.zlib://` stream wrapper or `inflate_add()` in a loop; `gzip`, `tar` and zlib's `gzread()` read all members.
- `.tar.gz` parts are multi-member gzip: a writer MAY close the current gzip member and start a new one at any point (the reference writer does so at every checkpoint). Concatenated members are a valid gzip stream (RFC 1952, section 2.2), so standard tools read the part as one stream. This is what makes a part resumable: on resume, the writer truncates the part at the last committed byte offset and appends a new member.

## 6. Database parts

- One or more files per table: `NNNN-<table>.<CCCC>.sql.gz`. `NNNN` is the restore order, `CCCC` the chunk number, both zero-padded to 4 digits. In encrypted backups the table name is left out of the stored name (`NNNN.<CCCC>.sql.gz.enc`); it is only in the encrypted manifest.
- Plain SQL, gzip-compressed, so `gunzip -c file.sql.gz | mysql db` works.
- Every file starts with `SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';`.
- Chunk 1 of each table starts with `DROP TABLE IF EXISTS` and `CREATE TABLE` (from `SHOW CREATE TABLE`).
- Rows are extended `INSERT` statements of at most about 1 MB each. Binary and BLOB columns are written as hex literals (`0x…`).
- Views and triggers are included with their `DEFINER` clause removed. Stored procedures are not included in v1.
- Chunks target 256 MB of raw SQL and are paginated by primary key (keyset pagination).
- Table names keep the source prefix (`site.table_prefix`). Restoring tools rename them.
- Restore MAY only execute these statements: `SET`, `DROP TABLE`, `CREATE TABLE`, `INSERT`, `CREATE VIEW`, `CREATE TRIGGER`, plus the matching `DROP VIEW` / `DROP TRIGGER` and the `DELIMITER` lines around trigger bodies. Anything else (for example `GRANT`, `CREATE USER`, `LOAD DATA`, `INTO OUTFILE`) MUST be rejected.

## 7. Encryption

Optional, enabled with `--password`. Every part and the manifest are encrypted separately; `fmw.json` stays readable.

- Cipher: AES-256-CBC in the OpenSSL `enc` file format: the ASCII string `Salted__`, an 8-byte random salt, then the ciphertext. Every part gets its own salt.
- Key derivation: PBKDF2-HMAC-SHA256 over the password and the part's salt, with the iteration count from `fmw.json` (default 600,000), producing 80 bytes. Bytes 0–31 are the AES key, 32–47 the IV, 48–79 the HMAC key. The first 48 bytes are exactly what `openssl enc -pbkdf2` derives, which keeps the ciphertext compatible with the OpenSSL command line.
- Authentication (encrypt-then-MAC): HMAC-SHA256 over the whole stored file, stored in the manifest (`parts[].hmac`) or, for the manifest itself, in `fmw.json` (`manifest_hmac`). Readers MUST verify the manifest's HMAC before decrypting it. For a part, the manifest's `sha256` of the stored bytes is already authenticated by that HMAC, so readers MUST verify either the part's SHA-256 or its HMAC before using decrypted data; FMW's restore checks the SHA-256 and `wp fmw verify --password` checks both.
- Part records of encrypted backups: `path` is the stored name, `bytes`, `sha256` and `hmac` describe the stored (encrypted) file, `bytes_plain` is the size after decryption, `bytes_raw` the size before compression.
- Names: encrypted entries get an extra `.enc` suffix (`files/part-0001.tar.gz.enc`, `manifest.json.enc`), and database parts drop the table name (section 6), so nothing outside the encryption describes the site. The backup's file name has no domain either (section 1).
- `fmw.json` is written first, with `manifest_hmac` as 64 zeros; writers replace them in place once the manifest is written. A reader that finds all zeros MUST treat the archive as incomplete.
- A wrong password is detected immediately by the manifest HMAC, before any heavy work.

Manual decryption without the plugin (does not verify the HMAC):

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -md sha256 \
  -in files/part-0001.tar.gz.enc -out files/part-0001.tar.gz
```

[FMW Tools](https://github.com/akhmadsakhoji/fmw-tools), a standalone command-line program maintained next to the plugin, inspects, verifies, decrypts and extracts archives on Windows, macOS and Linux without PHP (`fmw-tools decrypt backup.fmw`).

## 8. Manual restore

```bash
mkdir restore && cd restore
tar -xf ../example.com-20260924-180000-a1b2c3.fmw
for p in files/part-*.tar.gz; do tar -xzf "$p" -C /path/to/wp-content; done
for p in files/part-*.tar;    do tar -xf  "$p" -C /path/to/wp-content; done
for s in database/*.sql.gz;   do gunzip -c "$s" | mysql -u USER -p DB_NAME; done
```

Then replace the old URL if the domain changed, for example with `wp search-replace`. For a multisite network also set the new domain and path in the `blogs` and `site` tables (they hold bare domains, not URLs) and in `DOMAIN_CURRENT_SITE` / `PATH_CURRENT_SITE` in `wp-config.php`.

## 9. Compatibility rules

- A reader MUST refuse `version` values above the one it supports.
- A reader MUST ignore unknown JSON keys.
- A reader MUST refuse unknown `compression` values and unknown `parts[].type` values rather than skip data silently.
- Additive changes (new optional keys, new compression values) do not change `version`. Anything that would make a v1 reader restore incorrectly does.
