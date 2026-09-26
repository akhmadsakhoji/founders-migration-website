=== Founders Migration Website ===
Contributors: akhmadsakhoji
Tags: backup, migration, restore, multisite, wp-cli
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore and migrate WordPress sites and multisite networks of any size, with resumable jobs and open archive formats.

== Description ==

Founders Migration Website (FMW) backs up, restores and moves WordPress sites, from a small blog to a 100 GB store or a multisite network. It has a simple Export / Import / Backups workflow in the admin and a complete set of WP-CLI commands (`wp fmw`), and it is built so that large jobs finish:

* **Resumable everything.** Backups, uploads, downloads and restores continue from their last checkpoint after a timeout, a closed tab, a lost SSH session or a server restart.
* **Open, standard formats.** An `.fmw` backup is a TAR archive of TAR/gzip parts, gzip-compressed SQL and a JSON manifest, with a SHA-256 checksum per part. It can be restored by hand with `tar`, `gunzip` and `mysql`, and read with the free FMW Tools app on Windows, macOS and Linux.
* **Safe restores.** Every part is checked before use, the database is switched in with one atomic `RENAME TABLE`, and SQL from backups is checked against an allowlist.
* **Real progress** with speed and time left, in the browser and the terminal.
* **Restores `.wpress` backups** made with All-in-One WP Migration (plain, encrypted or compressed), including multisite backups.
* **Multisite:** move whole networks to another address, take one site out of a network, bring a site into a network, reset one site or the whole network.
* **Password protection** with AES-256 and PBKDF2.
* **Scheduled backups** with retention and e-mail notifications.
* **Cloud storage:** Amazon S3 and S3-compatible services (Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO) and Google Drive with your own Google OAuth client.
* **Site to site:** pull a site from another server over HTTPS with a short-lived key, without downloading and uploading the backup by hand.
* **Reset:** bring the database, media, plugins or themes back to a fresh install, after a safety backup.

Every feature is included. There are no paid add-ons and no limits on size.

= Command line =

`wp fmw backup`, `wp fmw restore <file>`, `wp fmw pull <url>`, `wp fmw reset`, `wp fmw schedule`, `wp fmw storage`, `wp fmw jobs` and more. Run `wp help fmw` for the full list.

= Documentation and source code =

Documentation, the archive format specification and the source code are on [GitHub](https://github.com/akhmadsakhoji/founders-migration-website). FMW Tools, the standalone backup reader, is at [github.com/akhmadsakhoji/fmw-tools](https://github.com/akhmadsakhoji/fmw-tools).

All-in-One WP Migration is a trademark of ServMask. This plugin is not affiliated with or endorsed by ServMask; it reads the `.wpress` format for compatibility.

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. On a multisite network, activate it network-wide; its screens are in Network Admin.
3. Open "Founders Migration" in the admin menu, or run `wp fmw status`.

The server needs 64-bit PHP 7.4 or newer with the zlib, hash, mysqli and json extensions. openssl is needed for password protection, cloud storage and pulls, and curl for cloud storage and pulls.

== Frequently Asked Questions ==

= Where are backups stored? =

In `wp-content/fmw-backups/` by default, protected from web access. Define `FMWP_BACKUPS_PATH` and `FMWP_STORAGE_PATH` in wp-config.php to keep backups and job data outside the web root. On Nginx, add a rule that denies `/wp-content/fmw-backups/` and `/wp-content/fmw-storage/`; the plugin warns you when the folder can be reached from the web.

= Pages show a 404 error after a restore. =

A backup never carries the `.htaccess` file of the site's root folder. FMW adds WordPress's permalink rules to it after every restore when they are missing, and checks that the web server applies them. On OpenLiteSpeed (CyberPanel), the server may need `autoLoadHtaccess 1` in the site's vhost.conf and a restart (`systemctl restart lsws`); the restore tells you when.

= How large can a site be? =

There is no size limit in the plugin. Backups are split into parts, and every step works in short, resumable slices, so PHP time limits and upload limits do not stop large sites. You need enough free disk space for one backup.

= Can I restore a backup from All-in-One WP Migration? =

Yes. Upload the `.wpress` file on the Import screen, or run `wp fmw restore file.wpress`. Backups in `wp-content/ai1wm-backups` are listed on the Backups screen.

= Can I open a backup without WordPress? =

Yes. An `.fmw` backup is a standard TAR archive with gzip parts and plain SQL. The free FMW Tools app inspects, verifies, decrypts and extracts it on Windows, macOS and Linux.

= Does it work with multisite? =

Yes: whole networks, one site of a network onto a single site, a single site into a network, and reset of one site or of the network. Activate the plugin network-wide.

= Does deleting the plugin delete my backups? =

No. Deleting the plugin removes its settings, cloud storage credentials, schedules, pull keys, jobs and logs. Backups on the server and in cloud storage are kept.

== External services ==

The plugin works entirely on your server. It contacts other services only when you set them up, and sends only what the feature needs:

* **Amazon S3 and S3-compatible storage** (Amazon S3, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, or any endpoint you enter), when you add a cloud storage and when backups are uploaded to, listed in, downloaded from or deleted from it. Sent: the backup files and their names, and requests signed with your access key (the secret key is never sent). Terms and privacy: [Amazon S3](https://aws.amazon.com/service-terms/) ([privacy](https://aws.amazon.com/privacy/)), [Cloudflare](https://www.cloudflare.com/website-terms/) ([privacy](https://www.cloudflare.com/privacypolicy/)), [Wasabi](https://wasabi.com/legal/terms-of-use) ([privacy](https://wasabi.com/legal/privacy-policy)), [Backblaze](https://www.backblaze.com/company/policy/terms-of-service) ([privacy](https://www.backblaze.com/company/policy/privacy)), [DigitalOcean](https://www.digitalocean.com/legal/terms-of-service-agreement) ([privacy](https://www.digitalocean.com/legal/privacy-policy)).
* **Google OAuth and Google Drive** (accounts.google.com, oauth2.googleapis.com, www.googleapis.com), when you connect a Google Drive storage, when its access is refreshed or revoked, and when backups are uploaded to, listed in, downloaded from or deleted from it. Sent: your OAuth client ID and secret, the authorization code and tokens, and the backup files and their names. The plugin asks only for the `drive.file` scope, so it sees only files it created. [Google Terms of Service](https://policies.google.com/terms), [Google Privacy Policy](https://policies.google.com/privacy), [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy).
* **Another WordPress site you pull from**, at the address you enter, when you start a pull. Sent: the pull key, the backup options and, if you chose one, the backup password. The backup is downloaded from that site. As the source of a pull, your site answers only requests with a valid key that you created.

Scheduled backups send e-mail through your site's own mailer. The plugin also makes requests to your own site to continue background jobs and to check whether the backups folder is reachable from the web. There is no tracking and no data is sent to the plugin's authors.

== Changelog ==

= 1.0.3 =
* A second copy of the plugin (another folder or a must-use copy) no longer stops activation with a fatal error; a notice says which copy runs and asks to delete the other.

= 1.0.2 =
* The backups and storage folders are also closed on OpenLiteSpeed (CyberPanel): their .htaccess now has a rewrite rule that denies every request, which OpenLiteSpeed applies when autoLoadHtaccess is on. Folders created by older versions are updated.

= 1.0.1 =
* After a restore, FMW adds missing permalink rules to .htaccess, gives files written by a root WP-CLI run back to the site's owner, empties Elementor's generated CSS, purges the LiteSpeed page cache and checks that the web server applies the rules (CyberPanel / OpenLiteSpeed).

= 1.0.0 =
* First public release: resumable backups and restores of sites and multisite networks in the open `.fmw` format, `.wpress` restores, password protection, reset, scheduled backups, S3-compatible and Google Drive storage, and server-to-server pulls. Full list in CHANGELOG.md.

== Upgrade Notice ==

= 1.0.3 =
Two installed copies of the plugin show a notice instead of a fatal error.

= 1.0.2 =
On OpenLiteSpeed / CyberPanel, backups could be downloaded from the web. Update, then restart lsws once.

= 1.0.1 =
Restores onto fresh CyberPanel / OpenLiteSpeed or Apache sites get their permalink rules and file owner fixed, and tell you when the web server needs a restart.

= 1.0.0 =
First public release.
