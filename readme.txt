=== Founders Migration Website ===
Tags: backup, migration, migrate, restore, wp-cli
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup, restore and migrate WordPress sites up to 100 GB and beyond, with open and standard archive formats.

== Description ==

Founders Migration Website (FMW) moves and backs up WordPress sites of any size. It follows the familiar Export / Import / Backups flow and WP-CLI commands of All-in-One WP Migration, and is built for very large sites:

* Resumable backups, uploads and restores: an interrupted job continues from its last checkpoint.
* Standard formats: an .fmw file is a TAR archive holding TAR/gzip parts, gzip-compressed SQL and a JSON manifest. It can be restored by hand with tar, gunzip and mysql.
* Real progress with speed and estimated time, in the browser and in WP-CLI.
* Imports .wpress backups from All-in-One WP Migration.
* Multisite support, encryption and cloud storage, all free and open source.

This is an early development version. Backup and restore work from the Export / Import / Backups screens and from WP-CLI (`wp fmw backup`, `wp fmw restore`).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin.
3. Open "Founders Migration" in the admin menu, or run `wp fmw status`.

== Frequently Asked Questions ==

= Where are backups stored? =

In `wp-content/fmw-backups/` by default. Define `FMWP_BACKUPS_PATH` in wp-config.php to store them outside the web root.

= Does deleting the plugin delete my backups? =

No. Uninstalling removes the plugin settings only.

== Changelog ==

= 0.1.0 =
* Foundation release: archive library (TAR/PAX, multi-member gzip, safe extraction), protected data folders, admin screens, `wp fmw list-backups`, `delete` and `status`.
