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
| `multisite.json` | Present only in backups made on a network by the Multisite Extension (below). Encrypted and compressed like site files. |
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
| `Plugins`, `Template`, `Stylesheet` | What was active. The export blanks `active_plugins`, `template` and `stylesheet` in the dump; FMW puts these values back before the switch, leaving out plugins that lock people out after a move (login hiders, some firewalls, and forced-HTTPS plugins when the new address is http://), as All-in-One WP Migration does |

## multisite.json (networks)

Written by the All-in-One WP Migration Multisite Extension (checked against 4.37).

| Field | Meaning |
|---|---|
| `Network` | `true` for the whole network; `false` when sites were picked one by one (their tables then use other placeholders such as `SERVMASK_PREFIX_mainsite_` and get new site IDs on import) |
| `Networks[]` | `SiteID`, `Domain`, `Path` of each network (`site` table rows) |
| `Sites[]` | `BlogID`, `SiteID`, `Domain`, `Path`, `SiteURL`, `HomeURL`, `Plugins`, `Template`, `Stylesheet`, `Uploads`, `UploadsURL`, `WordPress.Uploads`, `WordPress.UploadsURL` per site |
| `Plugins` | Network-activated plugins (`active_sitewide_plugins` is left out of the dumped `sitemeta`) |
| `Admins`, `Plugin.Version` | Super admins, extension version |

A whole-network dump uses the same placeholders as a single site: `SERVMASK_PREFIX_` for the main site and the network tables, `SERVMASK_PREFIX_<BlogID>_` for the other sites. Media of other sites is stored under `uploads/sites/<BlogID>/` (or `blogs.dir/<BlogID>/` for networks from before WordPress 3.5). The kind of network (subdomains or subdirectories) is not recorded; FMW works it out from the sites (a site in a folder of the network's domain means subdirectories, one on a subdomain of it means subdomains) and skips the kind check when the sites do not tell. Plugins and themes are put back per site ID (site 1 has the bare table prefix).

A dump of picked sites (`Network: false`) uses three placeholders instead, in table names and at the start of `option_name` and `meta_key` values: `SERVMASK_PREFIX_mainsite_` for users, usermeta and the network tables (`blogs`, `site`, `sitemeta`, `signups`, …), `SERVMASK_PREFIX_basesite_` for the main site's own tables and keys (site 1, when it was picked), and `SERVMASK_PREFIX_<BlogID>_` for the other sites. Only users with a role on a picked site are in it, with that site's keys (`SERVMASK_PREFIX_2_capabilities`). `Sites[]` lists only the picked sites; `WordPress.UploadsURL` of a site is recorded under the network's address (`https://example.com/wp-content/uploads/sites/2/`).

FMW restores `Network: true` backups onto a multisite network of the same kind, keeping the site IDs and moving the sites to the network's address like its own network backups (subsites with their own domain keep it unless mapped with `--map`). On a single site, one site of either kind of backup is restored as that site (`--site`, not needed when the backup holds one picked site): FMW maps the placeholders of picked sites to those of a whole network (`basesite_` and `mainsite_` keys become the bare placeholder) and then restores the site like one site of an `.fmw` network backup. On a network, picked sites become sites of the network, new or replacing existing ones, as chosen with `--site=<old>=<new>[,...]`: their tables get the new IDs (`SERVMASK_PREFIX_2_posts` → `wp_<new>_posts`, `basesite_` → the main site's new ID), media moves from `uploads/sites/<old>/` (the main site's from `uploads/`) to `uploads/sites/<new>/`, users are merged into the network's and their site keys follow the new IDs, and the dumped `mainsite_blogs` is read for the other sites' addresses (kept in links) and then dropped. Whole `blogs.dir` networks onto a network are refused for now.

## database.sql

The dump is a plain SQL file. Its statements are `DROP TABLE IF EXISTS`, the `CREATE TABLE` from `SHOW CREATE TABLE`, and one `INSERT … VALUES (…)` per row, grouped by `START TRANSACTION` / `COMMIT`. Views come at the end as `DROP VIEW IF EXISTS` / `CREATE VIEW`.

- **Placeholder prefix:** the table prefix is written as `SERVMASK_PREFIX_`, in table names and at the start of values in `options.option_name` and `usermeta.meta_key`. Examples: `SERVMASK_PREFIX_user_roles`, `SERVMASK_PREFIX_capabilities`, and on networks `SERVMASK_PREFIX_2_user_roles` in `SERVMASK_PREFIX_2_options`.
- **Excluded tables:** only tables that use the site prefix are exported.
- **Value encoding:** numbers are written unquoted, binary and blob values as `0x…` hex, and everything else as quoted strings with backslash escapes.

FMW runs the dump through its SQL allowlist and imports it into `fmwtmp_*` tables. Transaction statements from the dump are ignored, because FMW records its own progress in the same transactions. FMW gives masked option names and meta keys their source prefix back, then moves the prefix-based keys (`user_roles`, user meta) to the target prefix. Views are recreated after the tables go live.
