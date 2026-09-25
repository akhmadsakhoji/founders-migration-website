# Founders Migration Website

Backup, restore and migrate WordPress sites up to 100 GB and beyond — with open, standard archive formats.

Founders Migration Website (FMW) has the familiar Export / Import / Backups workflow, restores All-in-One WP Migration `.wpress` backups, and its WP-CLI commands and flags mirror `wp ai1wm` (`wp fmw` instead). Underneath it is built for very large sites:

- **Resumable everywhere.** Backup, upload and restore continue from the last checkpoint after a timeout, a dropped SSH session or a server restart.
- **Standard formats, no lock-in.** An `.fmw` file is a TAR archive of TAR/gzip parts, gzip SQL and a JSON manifest. You can restore it by hand with `tar`, `gunzip` and `mysql` if the plugin or WordPress is broken. See [docs/format-v1.md](https://github.com/akhmadsakhoji/founders-migration-website/blob/main/docs/format-v1.md).
- **Honest progress.** Percentage, speed and ETA in both the browser and the terminal.
- **Imports `.wpress`.** Existing All-in-One WP Migration backups (old and new versions, encrypted or compressed) restore with `wp fmw restore`.
- **Site to site.** `wp fmw pull https://old.example.com` copies a site (or a whole multisite network onto another network) over HTTPS with a short-lived pull key: no download and upload by hand.
- **Readable without WordPress.** [FMW Tools](https://github.com/akhmadsakhoji/fmw-tools) inspects, verifies, decrypts and extracts `.fmw` backups on Windows, macOS and Linux.
- **Free and open.** Every feature, multisite, cloud storage and schedules included, is in one GPL plugin with no paid add-ons.

> **Version 1.0.0.** Backup and restore (`.fmw` and `.wpress`), password encryption, reset, scheduled backups, cloud storage (S3-compatible and Google Drive), server-to-server pulls (sites and networks), moving multisite networks to another domain, moving one site out of or into a network, and resetting one site or a whole network. Every job is resumable. As with any migration tool, try a restore on a staging site before you rely on a new setup in production. Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## Requirements

| | Minimum | Recommended |
|---|---|---|
| PHP | 7.4, **64-bit** | 8.3 or newer |
| PHP extensions | zlib, hash, mysqli, json | openssl (encrypted backups, cloud storage, pulls, schedules with a password), curl (cloud storage and pulls), bz2 (bzip2-compressed `.wpress`), pcntl (clean Ctrl+C in WP-CLI) |
| WordPress | 6.0 | Latest |
| MySQL / MariaDB | 5.7 / 10.3 | 8.0 / 10.11 |
| WP-CLI | 2.5 | Latest |

The plugin is written in plain PHP and does not call `exec()` or system binaries, so it runs on shared hosting too.

## Installation

Download `founders-migration-website.zip` from the [latest release](https://github.com/akhmadsakhoji/founders-migration-website/releases/latest) and upload it in **Plugins → Add New → Upload Plugin** (once the plugin is listed on WordPress.org, search for "Founders Migration Website" there instead), then activate it. On a multisite network, activate it network-wide (**Network Admin → Plugins**); its screens are in Network Admin. From the command line:

```bash
wp plugin install https://github.com/akhmadsakhoji/founders-migration-website/releases/latest/download/founders-migration-website.zip --activate   # --activate-network on a network
wp fmw status
```

For development, clone the repository into `wp-content/plugins/` instead (see [Development](#development)).

## Admin screens

**Founders Migration** in the admin menu (Network Admin on a multisite network) has these screens:

| Screen | What it does |
|---|---|
| **Export** | The exclusion checkboxes of `wp fmw backup` (excluding single tables or paths, and the part size, are CLI options), a password, and optionally a cloud storage to upload to. Progress shows the step, bytes, speed and remaining time. When it finishes you get a Download button. |
| **Import** | Drag and drop a `.fmw` or `.wpress` file of any size. It is uploaded in chunks; if the connection drops or the page is closed, choose the same file again and the upload continues where it stopped. Then you confirm the restore, with a password field for encrypted backups and, on networks, the site choices described below. |
| **Backups** | Download (resumable, byte ranges), restore or delete backups. Backups in `wp-content/ai1wm-backups` are listed too; FMW never deletes them. Jobs that stopped part-way (closed tab, timeout, Ctrl+C in WP-CLI) can be continued or cancelled here. |
| **Pull** | Copy another site onto this one: enter its address and a pull key, check it, then pull and restore (or only download) with the same progress dialog. Below, create pull keys for other sites to copy this one (validity, allowed addresses, existing backups), see when they were last used, and revoke them. |
| **Schedules** | Scheduled backups: frequency, time, exclusions, password, retention, e-mail and a cloud storage to upload to. Shows when the scheduler last ran and the system cron line to use. |
| **Cloud storage** | Add, test and remove S3-compatible storages and Google Drive (with the redirect URI to copy and a Connect button); browse, download and delete the backups stored there. |
| **Reset** | Bring the database, media, plugins or themes back to a fresh install after a safety backup; on a network, one site or the whole network. |

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

Everything that describes the site is encrypted, including the manifest, the database part names and the file name (`backup-<date>-<token>.fmw`). A wrong password is refused before anything happens. While a job runs, its password is kept sealed with a key derived from `wp-config.php`'s AUTH salt, never in plain text, and it is removed when the job ends. Without the plugin, a part decrypts with `openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -md sha256` ([docs/format-v1.md](https://github.com/akhmadsakhoji/founders-migration-website/blob/main/docs/format-v1.md), section 7).

Restore onto the same site or a new domain, path or table prefix:

```bash
wp fmw restore <file>                  # asks for confirmation; --yes skips it
wp fmw restore <file> --keep-old-tables
wp fmw cleanup --tables                # drop fmwold_* / leftover fmwtmp_* tables
```

Multisite networks (`.fmw`, or `.wpress` from the All-in-One WP Migration Multisite Extension) restore onto a network of the same kind (subdomains or subdirectories), at the same or another address. The main site and every subsite under the network's address move with it, and the `blogs` and `site` tables follow:

```bash
wp fmw restore network.fmw             # shows where each site goes before asking
wp fmw restore network.fmw --map=brand.example=brand.staging.example   # subsites with their own domain
```

| Backup | Restored on `new.example` |
|---|---|
| `old.example/`, `old.example/shop/` | `new.example/`, `new.example/shop/` |
| `old.example/`, `shop.old.example/` | `new.example/`, `shop.new.example/` |
| `brand.example/` (own domain) | kept, or the domain given with `--map` |

The whole network is replaced: its sites that are not in the backup are removed with the old tables when the restore finishes (kept as `fmwold_*` with `--keep-old-tables`). The main site needs the same ID on both sides (`BLOG_ID_CURRENT_SITE`, normally 1), and installs with several networks are refused. Converting between subdomains and subdirectories is not supported.

One site of a network backup restores onto a single site, for example when a brand leaves the network. This works for `.fmw` network backups and for `.wpress` backups of the Multisite Extension, of the whole network or of sites picked one by one:

```bash
wp fmw restore network.fmw --site=example.com/shop   # or its ID, or its URL; the Restore dialog offers a list
wp fmw restore shop.wpress                            # a .wpress of one picked site needs no --site
```

- Its tables become the site's (`wp_2_posts` → `wp_posts`); other sites' tables and the network tables stay in the backup.
- Its media moves from `uploads/sites/2/` (or `blogs.dir/2/files/` on old networks) to `uploads/`, and its address and media URLs become this site's. Links to the network's other sites, and e-mail addresses at the network's domain, are left as they are.
- Users come along when they have a role, posts, comments or links on it; super admins become administrators. Their keys lose the site ID (`wp_2_capabilities` → `wp_capabilities`), other sites' keys are dropped, and keys shared by all sites (like a two-factor plugin's) stay.
- Network-activated plugins become active plugins. Themes and plugins are shared by the network, so all of them are restored. The network's views and triggers are not carried over.

The other way round, a single-site backup (`.fmw` or `.wpress`) becomes one site of a network, for example when a brand joins it:

```bash
wp fmw restore brand.fmw --site=shop                  # new site: net.example/shop/ (or shop.net.example)
wp fmw restore brand.wpress --site=brand.example      # new site with its own domain
wp fmw restore brand.fmw --site=4                     # replaces site 4 (or give its address)
```

- A name or address no site has yet creates a new site with the next free ID (kept for the restore, so sites made meanwhile get the IDs after it); an existing site's ID or full address replaces that site. A bare name that is already a site (`shop` when `/shop/` exists) is refused, so nothing is replaced by a typo. The main site and site 1 are refused, as are backups without a database and installs with several networks, and so are folders WordPress reserves on subdirectory networks (`blog`, `files`, `wp-admin`, …). In the Restore dialog of Network Admin, type the name or pick a site from the list.
- Its tables get the site's prefix (`wp_posts` → `wp_3_posts`). A replaced site's tables that the backup does not have go aside with the old ones.
- Its media moves to `uploads/sites/<id>/`, and its address and media URLs become the site's. Plugins, themes and languages are restored into the shared folders; must-use plugins, drop-ins (`object-cache.php`, …) and other folders in `wp-content` stay out, since they would change every site.
- Users are merged into the network's: someone with the same login or e-mail address is the network's user (password and profile stay as they are) and gets the backup's role on the site; everyone else is added. Their e-mail addresses are not changed to the network's domain. Posts, comments and links follow the new user IDs (content of users the backup no longer has gets no author, not someone else's); the merge goes in batches, so sites with many customers restore too. People who already had a role on a replaced site keep it.
- The site's own plugins and theme stay active on it; nothing is network-activated. Views and triggers are not carried over.

A `.wpress` backup of sites picked one by one from another network (All-in-One WP Migration Multisite Extension) goes into a network the same way, each site to its own place:

```bash
wp fmw restore picked.wpress --site=shop                 # it holds one site: a new site shop
wp fmw restore picked.wpress --site=2=shop,3=4           # site 2 of the backup becomes a new site, site 3 replaces site 4
wp fmw restore picked.wpress --site=shop.old.example=shop   # sites of the backup by address too; sites left out stay in the backup
```

Each chosen site follows the rules above (new or replaced site, tables, media, users merged once for all of them, URLs), and the plugins it had active, network-activated ones included, stay active on it. Links to the backup network's other sites, and the network's e-mail domain unless its main site moves, are left as they are. In the Restore dialog of Network Admin, each site of the backup gets its own field; left empty, it stays in the backup.

All-in-One WP Migration backups restore the same way, found by name in `wp-content/ai1wm-backups` too:

```bash
wp fmw inspect site.wpress             # source site, plugin version, encryption, compression
wp fmw verify site.wpress              # headers + CRC-32 (archives from recent versions), no password needed
wp fmw restore site.wpress --password=<password>   # password only for encrypted backups; asked for when omitted
```

Plain, encrypted (AES-256) and gzip / bzip2 compressed `.wpress` files from both older (7.85) and current (7.111) versions of All-in-One WP Migration are supported; the format is described in [docs/wpress.md](https://github.com/akhmadsakhoji/founders-migration-website/blob/main/docs/wpress.md). The order is chosen so a bad backup fails early: headers and checksum first, then the database into temporary tables, and only then the files. The plugins and theme the backup had active are switched on again, as All-in-One WP Migration does. Whole-network backups from its Multisite Extension restore onto a network like FMW's own network backups (below), one of their sites, or of a backup of sites picked one by one, restores onto a single site, and picked sites restore into a network (below).

How a restore protects the site:

- Every part is checked against its SHA-256 before it is used; a damaged archive stops the restore before anything live changes.
- The database is imported into `fmwtmp_*` tables and put live with **one atomic `RENAME TABLE`**; the previous tables become `fmwold_*` and are dropped at the end (kept with `--keep-old-tables`). Tables of other sites in a shared database are left alone.
- URLs, paths and e-mail domains are replaced for the new location, serialized data included, without running `unserialize()` on backup data. Addresses match whole: moving `example.com/shop` leaves `example.com/shopping` and `example.com.au` alone. `posts.guid` stays unchanged, as WordPress recommends. A changed table prefix is applied to user roles and user meta keys.
- SQL from the archive is checked against an allowlist (table DDL and literal `INSERT`s only), so a crafted backup cannot run arbitrary queries.
- The FMW plugin folder, backups and storage are never overwritten, the plugin stays active, and files that are not in the backup are kept.

All commands (`wp help fmw <command>` lists every option). Add `alias fmw='wp fmw'` to `~/.bashrc` to type `fmw backup`.

| Command | What it does |
|---|---|
| `wp fmw backup` | Back up the site or the whole network (exclusions, `--password`, `--storage`) |
| `wp fmw restore <file>` | Restore `.fmw` or `.wpress`: sites and whole networks, one site of a network backup onto a single site, a single site or picked sites into a network (`--site`) |
| `wp fmw list-backups`, `delete <file>` | List or delete backups |
| `wp fmw inspect <file>`, `verify <file>` | What is inside a backup; check every part |
| `wp fmw status` | Server requirements and the data folders |
| `wp fmw jobs`, `resume <job_id>`, `cancel <job_id>`, `log <job_id>` | Unfinished and past jobs |
| `wp fmw cleanup` | Storage of finished and stale jobs, and with `--tables` leftover `fmwold_*` / `fmwtmp_*` tables |
| `wp fmw reset` | Fresh database, media, plugins or themes; one site of a network (`--site`) or the whole network (`--network`) |
| `wp fmw schedule list\|add\|update\|delete\|enable\|disable\|run` | Scheduled backups (`run` for a system cron) |
| `wp fmw storage list\|add\|update\|delete\|test\|connect\|disconnect` | Cloud storages (`connect` for Google Drive) |
| `wp fmw storage files\|upload\|download\|remove` | Backups in a cloud storage |
| `wp fmw pull <url>` | Copy another site (or network onto a network) onto this one |
| `wp fmw pull-key create\|list\|revoke` | Keys that let other sites pull this one |

`wp fmw reset --database --media --plugins --themes` (or `--all`, and the Reset screen) brings parts of a site back to a fresh WordPress install:

- A backup of the whole site is made first (unless `--skip-backup`), so a reset can be undone with `wp fmw restore`.
- You confirm by typing the site's domain (`--yes` in scripts).
- The fresh database is built in `fmwtmp_*` tables and switched in with one atomic `RENAME TABLE`. It keeps the site address, title, language, time zone, permalinks and the kept users (all administrators by default, `--keep-user=<id|login|email>`), who stay logged in. This plugin and the active theme stay active.
- Tables and views of other WordPress installs in the same database (another prefix) are not touched. Must-use plugins, drop-ins and `wp-config.php` are never deleted.

On a multisite network one site, or the whole network, is reset, from the CLI or the Reset screen in Network Admin:

```bash
wp fmw reset --site=example.com/shop --all      # or its ID; --all is --database --media for one site
wp fmw reset --network --all                    # the whole network
```

- Its own tables (`wp_<id>_*`, plugin tables too) are replaced by a fresh site with its address, title, language, time zone and theme; other sites and the network's tables are not touched.
- Users are shared by the network, so they stay: the kept ones (its administrators by default, `--keep-user`) are its administrators afterwards, everyone else loses their role on it.
- Media: only its folder (`uploads/sites/<id>/`) and its media library.
- Plugins and themes are shared by every site, so they are not reset for one site. The main site is reset with the whole network. The safety backup is of the whole network.

The whole network (`--network`, or "The whole network" at the end of the Reset screen's list) becomes a fresh network at the same address and of the same kind with only its main site:

- Every other site goes (its tables are switched out in the same atomic `RENAME TABLE` as the fresh ones in), with all users but the kept ones (the super admins by default, you in the Reset screen), who are its super admins and the main site's administrators.
- Kept: the network's name, admin e-mail, language and upload settings, and the main site's address, title and settings as for a single site. Other network settings get WordPress's defaults. New sites get IDs after the old ones (no new site meets an old site's leftover files); the default theme is kept for them.
- `--media`, `--plugins` and `--themes` work on the whole network (without `--database`, every site's active plugins and media library stay consistent, and every site keeps its theme).
- Refused: installs with several networks, or whose main site is not site 1 (reset their sites one by one).

**Scheduled backups** (`wp fmw schedule add`, or the Schedules screen) run hourly, daily, weekly or monthly in the site time zone, with the exclusion flags and optional password of a manual backup (`--exclude-tables`, `--exclude-paths` and `--part-size` are for manual backups only):

- `--keep=<n>` keeps the newest *n* backups of that schedule and deletes older ones; backups made by hand are never touched.
- E-mail on failure (default), after every backup, or never.
- Without a browser: WP-Cron checks every five minutes, and the backup continues in short background requests to the site itself. An interrupted backup is continued automatically (up to 12 times); a failed one is cleaned up, reported and tried again at the next run.
- WP-Cron needs visitors. For reliable schedules on large sites, use a system cron: `0-59/5 * * * * wp fmw schedule run --path=/var/www/example.com --quiet`.
- Schedules are stored in the storage folder, not the database, so restoring, migrating or resetting the site never removes or copies them.

**Cloud storage** (`wp fmw storage add`, or the Cloud storage screen) keeps copies off the server on Amazon S3, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO or any S3-compatible service:

- `wp fmw backup --storage=<id> [--delete-local]`, "Then upload to" on the Export screen, "Upload" on the Backups screen, or a schedule's "Upload to" with its own retention (`--remote-keep`, `--no-keep-local`).
- Pure PHP (curl), no SDK. Multipart uploads with part sizes adapted to the connection (up to 5 TiB, 10,000 parts), resumable after an interruption, every part signed with its SHA-256 so the storage refuses damaged bytes. A cancelled upload is aborted in the storage.
- Download a backup back to the server (resumable, ranged) and restore it from there.
- The secret key is stored encrypted with the site's keys in `fmw-storage/storages.json`, never in the database or in job files. The connection is tested (write, read, list, delete) before a storage is saved, unless you pass `--skip-test`; Google Drive storages are tested when you connect them.

**Google Drive** uses your own Google OAuth client, so backups go straight from your server to your Drive with no third-party server in between:

1. In the [Google Cloud Console](https://console.cloud.google.com/apis/library/drive.googleapis.com), create or pick a project and enable the Google Drive API.
2. Configure the OAuth consent screen (External). Publish the app ("In production"): while it is in "Testing", Google ends the sign-in after 7 days. FMW only asks for the `drive.file` scope, which Google does not require an app review for.
3. Create an OAuth client ID of type "Web application" with the redirect URI shown on the Cloud storage screen: `https://example.com/wp-admin/admin-post.php?action=fmwp_gdrive_callback`.
4. Add the storage with the client ID and secret, then click **Connect** (or run `wp fmw storage connect <id>` and open the printed link) and allow access.

```bash
wp fmw storage add --provider=gdrive --client-id=123-abc.apps.googleusercontent.com --client-secret=GOCSPX-... --prefix="FMW Backups/example.com"
wp fmw storage connect <id>        # prints the Google sign-in link
wp fmw storage test <id>
wp fmw backup --storage=<id>
```

- With `drive.file`, FMW sees only the files it created itself. Backups you put into the folder by hand do not show in the list; upload them with FMW instead.
- Resumable uploads in 256 KiB-aligned chunks sized to the connection. After an interruption, the upload asks Google how much arrived and continues from there. Older copies with the same name in the folder are replaced.
- The folder (default `FMW Backups/<domain>`) is created on first use. The client secret and the Google tokens are stored encrypted like S3 keys. **Disconnect** revokes the sign-in; the backups stay in Drive.

**Pulling a site** (server to server, [docs/pull-v1.md](https://github.com/akhmadsakhoji/founders-migration-website/blob/main/docs/pull-v1.md)) copies a site onto this one without downloading and uploading the backup by hand. On the source site, create a pull key; on the site that should receive the copy, pull:

```bash
# On the source (old) site: prints a key once, valid 24 hours by default.
wp fmw pull-key create --name="new server" --expires=2h --ip=203.0.113.7

# On the target (new) site: backup there, download here, restore here, in one resumable job.
wp fmw pull https://old.example.com --key=fmwpk_1a2b3c4d_... --exclude-cache
```

- The target drives the backup on the source slice by slice, so the source needs no WP-CLI, SSH or working WP-Cron; a shared host is enough.
- The download is resumable (byte ranges checked against the file's version). The temporary backup on the source is deleted only once the copy here is proven intact: after the restore, which checks every part's SHA-256, or after the same check for `--download-only`. `--keep-source-backup` keeps it; `--password` encrypts it while it waits on the source.
- A pull that outlives its key continues with a new one: `wp fmw pull --job=<job_id> --key=<new key>`. Prefer the key prompt or `FMW_PULL_KEY` over `--key=` on shared machines, where the process list and shell history are visible.
- A key can only make backups and download its own (`--allow-existing` also allows the backups already there, for `--backup=<name>`). It expires (at most 30 days), can be limited to addresses or CIDR ranges, is stored only as a SHA-256 hash, and is revoked with `wp fmw pull-key revoke <id>`. Wrong keys are throttled per address, and `define( 'FMWP_DISABLE_PULL', true );` switches pulls off.
- HTTPS with certificate checks is required, except for local addresses or with `--allow-http`.
- Networks: pull a network onto a network of the same kind (subdomains or subdirectories) from the source network's main address, on the CLI or the Pull screen in Network Admin. The whole network is copied and its sites move to this network's address as with `wp fmw restore`; `--map=old=new` gives subsites with their own domain a new one. Kinds that do not match are refused before anything is made on the source.

Every backup, restore and reset runs as a **job** that checkpoints its position. Press Ctrl+C, lose the SSH session or hit a server restart, then continue where it stopped:

```bash
wp fmw jobs                  # find the job ID
wp fmw resume <job_id>       # exit 0 = done, 1 = failed, 3 = stopped again (resumable)
```

## Where data lives

| Folder | Contents |
|---|---|
| `wp-content/fmw-backups/` | Finished `.fmw` backups |
| `wp-content/fmw-storage/` | Job state, logs and temporary parts, unfinished uploads, and the settings: cloud storages (credentials sealed), schedules and pull keys |

Move both outside the web root (recommended on a VPS) in `wp-config.php`:

```php
define( 'FMWP_BACKUPS_PATH', '/home/example.com/fmw-backups' );
define( 'FMWP_STORAGE_PATH', '/home/example.com/fmw-storage' );
```

Both folders get `index.php`, `.htaccess` and `web.config`. Nginx and OpenLiteSpeed usually ignore `.htaccess`, so FMW also checks over HTTP whether the backups folder is reachable and warns you if it is. For Nginx:

```nginx
location ~* /wp-content/fmw-(backups|storage)/ { deny all; }
```

Deleting the plugin (Plugins → Delete) removes its cloud storages with their stored credentials, schedules, pull keys, jobs and their logs, unfinished uploads and transients, then the storage folder's protection files and the folder itself when nothing else is in it. Only files the plugin made are removed, and nothing in the backups folder. **Backups are never deleted**, on the server or in cloud storage. Unfinished `.fmw.partial` files in the backups folder and `fmwold_*` tables kept with `--keep-old-tables` stay too (`wp fmw cleanup --tables` drops those tables beforehand). Deactivating only stops the scheduler; everything stays.

## Constants and hooks

| Name | Type | Purpose |
|---|---|---|
| `FMWP_BACKUPS_PATH`, `FMWP_STORAGE_PATH` | constant | Where backups and job data live (see above) |
| `FMWP_DISABLE_PULL` | constant | `true` switches off the pull routes, so no other site can pull this one |
| `fmwp_web_slice_seconds` | filter | Seconds one browser request works on a job (default 20, less when `max_execution_time` is lower) |
| `fmwp_background_request` | filter | Arguments of the loopback requests that run scheduled backups (`$args`, `$url`), for example HTTP auth on a staging site |
| `fmwp_pull_client_ip` | filter | The address pull keys are checked against, for sites behind a trusted proxy |
| `fmwp_remote_curl_options` | filter | curl options for cloud storage and pull requests (proxy, CA bundle) |


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
lib/archive/                     TAR (PAX) writer and reader, multi-member gzip, safe extractor, .wpress reader
lib/database/                    database dump, import and serialized-safe search-replace
lib/job/                         resumable job engine, job store, sealed secrets
lib/model/                       the steps of backup, restore, reset, pull and cloud jobs
lib/pull/                        pull client, pull keys
lib/storage/                     data folders, protection, backup listing
lib/schedule/                    scheduled backups, WP-Cron and background requests
lib/remote/                      cloud storage drivers (S3 SigV4, Google Drive OAuth), shared curl transport
lib/controller/, lib/view/       admin pages
lib/cli/                         wp fmw
docs/format-v1.md                archive format specification
docs/pull-v1.md                  server-to-server pull protocol
docs/wpress.md                   the .wpress format as FMW reads it
tests/                           PHPUnit tests
tools/                           developer tools (not shipped)
```

PHP functions, constants, options, transients, hooks, script handles and `admin-post` actions use the `fmwp_` / `FMWP_` / `fmwp-` prefix; classes live in the `Founders\\Migration` namespace. The command, the file extension, the folder names, the admin page slugs and the REST namespace (`fmw/v1`) stay `fmw`.

## Roadmap

- Listing on WordPress.org, a documentation site and translations.
- Ideas and requests are welcome in [GitHub issues](https://github.com/akhmadsakhoji/founders-migration-website/issues).

## Contributing and security

- [CONTRIBUTING.md](https://github.com/akhmadsakhoji/founders-migration-website/blob/main/CONTRIBUTING.md): coding standards and the Developer Certificate of Origin (`git commit -s`).
- [SECURITY.md](https://github.com/akhmadsakhoji/founders-migration-website/blob/main/SECURITY.md): report vulnerabilities privately, never in public issues.
- [CODE_OF_CONDUCT.md](https://github.com/akhmadsakhoji/founders-migration-website/blob/main/CODE_OF_CONDUCT.md).

## License

Copyright (C) 2026 PT Founder Media Partner.

Founders Migration Website is free software, licensed under the [GNU General Public License v2.0 or later](LICENSE). The archive format specification in [docs/format-v1.md](https://github.com/akhmadsakhoji/founders-migration-website/blob/main/docs/format-v1.md) is licensed under CC BY 4.0 so that anyone can implement it.

All-in-One WP Migration is a trademark of its owner, ServMask. This project is not affiliated with or endorsed by ServMask; it reads the `.wpress` format for compatibility only.
