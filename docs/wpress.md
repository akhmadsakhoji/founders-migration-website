# The `.wpress` format, as FMW reads it

All-in-One WP Migration (ai1wm) stores backups in its own `.wpress` format. FMW restores them so existing backups keep working. This page records what FMW relies on. It was checked against archives made by ai1wm 7.85 and 7.111. The same notes will serve the standalone FMW Tools app.

## Layout

A `.wpress` file is a list of entries followed by an end block. Each entry has a 4377-byte header, then its stored data. All header fields are ASCII and padded with NUL bytes.

| Field | Bytes | Content |
|---|---|---|
| name | 255 | File name |
| size | 14 | Stored data size in bytes, decimal |
| mtime | 12 | Modification time, Unix seconds, decimal |
| path | 4088 | Folder relative to `wp-content`, `/`-separated; `.` for the top |
| crc32 | 8 | CRC-32 (`crc32b`, hex) of the original content; empty in older archives |

Older archives use a 4096-byte path field and no CRC. In those archives the last 8 bytes of the path are always NUL, so one parser reads both versions.

End block, also 4377 bytes:

- **Older archives:** all NUL.
- **Current archives:** 255 NUL bytes, then the archive size before the end block (14 bytes, decimal), then 4100 NUL bytes, then the CRC-32 of everything before the end block (8 hex characters).

## Top-level entries

| Entry | Meaning |
|---|---|
| `package.json` | Source site and backup options (below). Never encrypted or compressed. |
| `database.sql` | SQL dump (below). |
| `multisite.json` | Present only for network backups (FMW: phase 3). Never encrypted or compressed. |
| anything else | A file directly in `wp-content`, for example `index.php` or `object-cache.php`. |

Other entries are site files, stored under `uploads/…`, `plugins/…`, `mu-plugins/…`, `themes/…` or another `wp-content` folder. FMW writes each top folder to the target site's real location for that folder.

## Stored data

Content is processed in chunks of 512,000 bytes:

- **Plain:** the bytes as they are.
- **Encrypted** (`"Encrypted": true`): each chunk is a random 16-byte IV followed by AES-256-CBC ciphertext with PKCS#7 padding. The key is the first 16 bytes of `SHA-1(password)`, padded with NUL bytes to 32 bytes. Without compression, a full chunk takes 512,032 bytes and the last chunk is whatever remains.
- **Compressed** (`"Compression": {"Enabled": true, "Type": "gzip" | "bzip2"}`): each chunk is compressed (zlib stream for "gzip"), then encrypted if needed, and prefixed with its stored length as a 32-bit big-endian number.

Older versions left every file named `package.json` unencrypted, including files inside plugin folders. FMW treats such an entry as stored as is when its data is valid JSON, which encrypted or compressed data never is.

**Password check:** `EncryptedSignature` is a fixed sentence encrypted with the password, base64-encoded. FMW decrypts it and compares its SHA-256 with a stored hash.

## package.json fields FMW uses

| Field | Use |
|---|---|
| `SiteURL`, `HomeURL`, `InternalSiteURL`, `InternalHomeURL` | Old URLs to replace |
| `WordPress.Absolute`, `.Content`, `.Uploads` | Old paths to replace (`Absolute` is missing in older archives) |
| `WordPress.UploadsURL` | Old uploads URL to replace |
| `Replace.OldValues` / `NewValues` | Find / replace pairs chosen at export, applied on restore |
| `Database.Prefix` | The source table prefix |
| `NoDatabase`, `NoEmailReplace` | Skip the database / keep e-mail domains |
| `Plugin.Version`, `Encrypted`, `EncryptedSignature`, `Compression` | As described above |

## database.sql

The dump is a plain SQL file. Its statements are `DROP TABLE IF EXISTS`, the `CREATE TABLE` from `SHOW CREATE TABLE`, and one `INSERT … VALUES (…)` per row, grouped by `START TRANSACTION` / `COMMIT`. Views come at the end as `DROP VIEW IF EXISTS` / `CREATE VIEW`.

- **Placeholder prefix:** the table prefix is written as `SERVMASK_PREFIX_`, in table names and at the start of values in `options.option_name` and `usermeta.meta_key`. Examples: `SERVMASK_PREFIX_user_roles`, `SERVMASK_PREFIX_capabilities`.
- **Excluded tables:** only tables that use the site prefix are exported.
- **Value encoding:** numbers are written unquoted, binary and blob values as `0x…` hex, and everything else as quoted strings with backslash escapes.

FMW runs the dump through its SQL allowlist and imports it into `fmwtmp_*` tables. Transaction statements from the dump are ignored, because FMW records its own progress in the same transactions. FMW gives masked option names and meta keys their source prefix back, then moves the prefix-based keys (`user_roles`, user meta) to the target prefix. Views are recreated after the tables go live.
