<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Reset;

use Founders\Migration\Database\Connection;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;
use Founders\Migration\Model\Import\RestoreDatabase;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are backtick-quoted and values escaped by Connection.

/**
 * Builds a fresh WordPress database in fmwtmp_* tables, next to the live one.
 *
 * The tables come from WordPress's own schema and default options, like a
 * new install. Kept from the live site: its address, name, language, time
 * zone, date formats, permalinks, search engine visibility, the salts stored
 * in the database (so nobody is logged out) and the chosen users, who become
 * administrators with their sessions intact. This plugin stays active and the
 * active theme stays active. SwapStep then switches every table of the site
 * in one atomic RENAME TABLE; until then the live site is untouched.
 *
 * On a network (reset_site) only that site's tables are built (fmwtmp_<id>_*,
 * WordPress's per-site schema) with its own address and settings; the
 * network's users stay where they are and SwapStep gives the kept ones the
 * administrator role on the site and takes it from everyone else.
 *
 * A whole network (reset_network) gets the full schema with the network
 * tables: its site row and main site row as they are, WordPress's default
 * network settings with its name, admin e-mail, address, kind and language
 * kept, and the kept users as super admins; every other site is gone after
 * the switch (see SwapStep).
 *
 * The step is quick and idempotent: after an interruption it starts over.
 *
 * Reads job options: reset, reset_site, reset_network, keep_users, keep_active_plugin, keep_themes, target.table_prefix.
 * Sets job data: has_db, manifest.site.table_prefix.
 */
final class ResetDatabaseStep implements Step {

	/**
	 * Live options carried into the fresh database, raw.
	 */
	const KEEP_OPTIONS = array(
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'admin_email',
		'WPLANG',
		'blog_public',
		'blog_charset',
		'timezone_string',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		'permalink_structure',
		'upload_path',
		'upload_url_path',
		'auth_key',
		'auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'secure_auth_key',
		'secure_auth_salt',
	);

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Database';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException Outside a single-site WordPress, or when no user can be kept.
	 */
	public function run( Job $job, Context $context ): bool {
		if ( ! in_array( 'database', (array) ( $job->options['reset'] ?? array() ), true ) ) {
			return true;
		}
		if ( ! function_exists( 'is_multisite' ) || ! defined( 'ABSPATH' ) ) {
			throw new JobException( 'A database reset needs WordPress.' );
		}
		$site    = (int) ( $job->options['reset_site'] ?? 0 );
		$network = ! empty( $job->options['reset_network'] );
		if ( is_multisite() !== ( $site > 0 || $network ) ) {
			throw new JobException( is_multisite() ? 'On a multisite network, reset one site or the whole network.' : 'This is not a multisite network any more; start the reset again.' );
		}
		if ( $network && ( 1 !== get_main_site_id() || count( get_networks( array( 'number' => 2 ) ) ) > 1 ) ) {
			throw new JobException( 'This network cannot be reset as a whole (several networks, or a main site other than site 1).' );
		}
		if ( $site > 0 && ( ! get_site( $site ) || is_main_site( $site ) || 1 === $site ) ) {
			throw new JobException( sprintf( 'Site %d cannot be reset on its own (it is gone, or it is the main site).', $site ) );
		}
		if ( defined( 'CUSTOM_USER_TABLE' ) || defined( 'CUSTOM_USER_META_TABLE' ) ) {
			throw new JobException( 'This site shares its users table with other sites (CUSTOM_USER_TABLE); its database cannot be reset.' );
		}

		global $wpdb;
		$live = (string) $wpdb->base_prefix;
		if ( (string) ( $job->options['target']['table_prefix'] ?? $live ) !== $live ) {
			throw new JobException( 'The table prefix of this site changed since the reset was started. Start it again.' );
		}

		$restore = new RestoreDatabase();
		$db      = $restore->db();
		$switch  = $site > 0 ? $site : ( $network && get_current_blog_id() !== get_main_site_id() ? get_main_site_id() : 0 );
		if ( $switch > 0 ) {
			switch_to_blog( $switch ); // Its address, settings and theme; queries below name tables explicitly.
		}
		try {
			$restore->drop( array_merge( $restore->imported_tables(), array( RestoreDatabase::PROGRESS ) ) ); // Leftovers of an earlier, unfinished attempt.
			$restore->create_progress();

			$tables = $this->create_tables( $db, $site );
			$this->fill_options( $job, $db, $live, $site );
			$users = $site > 0 ? count( (array) ( $job->options['keep_users'] ?? array() ) ) : $this->copy_users( $job, $db, $live );
			if ( $network ) {
				$this->fill_network( $job, $db, $live );
			}
			$this->add_default_category( $db, $site );
		} finally {
			if ( $switch > 0 ) {
				restore_current_blog();
			}
			$db->close();
		}

		$job->data['has_db']                           = true;
		$job->data['manifest']['site']['table_prefix'] = $live;
		if ( $site > 0 ) {
			$context->log( sprintf( 'Built fresh tables for site %d (%d tables); %d user(s) will be its administrators.', $site, $tables, $users ) );
		} else {
			$context->log( sprintf( $network ? 'Built a fresh network (%d tables) with only its main site, keeping %d user(s) as super admins.' : 'Built a fresh database (%d tables) keeping %d user(s) as administrators.', $tables, $users ) );
		}
		return true;
	}

	/**
	 * Prefix of the fresh tables: fmwtmp_, or fmwtmp_<id>_ for a site of a network.
	 *
	 * @param int $site Site of a network, or 0.
	 * @return string
	 */
	private static function tmp( int $site ): string {
		return RestoreDatabase::TMP . ( $site > 0 ? $site . '_' : '' );
	}

	/**
	 * Creates the WordPress tables as fmwtmp_*.
	 *
	 * @param Connection $db   Connection.
	 * @param int        $site Site of a network (its tables only), or 0.
	 * @return int Number of tables.
	 * @throws JobException When the schema cannot be generated.
	 */
	private function create_tables( Connection $db, int $site ): int {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// wp_get_db_schema() only formats table names from $wpdb: no query runs while the prefix is switched.
		$old = $wpdb->set_prefix( RestoreDatabase::TMP );
		if ( is_wp_error( $old ) ) {
			throw new JobException( 'Cannot prepare the fresh database: ' . $old->get_error_message() );
		}
		try {
			$schema = $site > 0 ? wp_get_db_schema( 'blog', $site ) : wp_get_db_schema( 'all' );
		} finally {
			$wpdb->set_prefix( $old );
		}

		$count = 0;
		foreach ( explode( ';', $schema ) as $sql ) {
			$sql = trim( $sql, " \t\n\r\0\x0B" );
			if ( '' === $sql ) {
				continue;
			}
			if ( 1 !== preg_match( '/^CREATE TABLE `?' . preg_quote( self::tmp( $site ), '/' ) . '[a-z]/i', $sql ) ) {
				throw new JobException( 'Unexpected statement in the WordPress schema.' );
			}
			$db->query( $sql );
			++$count;
		}
		return $count;
	}

	/**
	 * Default options of a new install, with the live site's identity and this plugin active.
	 *
	 * @param Job        $job  Job.
	 * @param Connection $db   Connection.
	 * @param string     $live Live table prefix (the network's base prefix).
	 * @param int        $site Site of a network, or 0.
	 * @return void
	 */
	private function fill_options( Job $job, Connection $db, string $live, int $site ): void {
		global $wpdb;

		$own    = $live . ( $site > 0 ? $site . '_' : '' );
		$themes = array_values( (array) ( $job->options['keep_themes'] ?? array() ) );
		$plugin = (string) ( $job->options['keep_active_plugin'] ?? '' );
		if ( $site > 0 && ! in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ) ) {
			$plugin = ''; // Network-activated (or not active on this site): the site's own list stays empty.
		}
		$options = array(
			'siteurl'           => site_url(),
			'home'              => home_url(),
			'template'          => get_template(),
			'stylesheet'        => get_stylesheet(),
			'active_plugins'    => '' === $plugin ? array() : array( $plugin ),
			$own . 'user_roles' => self::default_roles(),
		);
		if ( is_array( $job->options['active_theme'] ?? null ) ) {
			$options['template']   = (string) $job->options['active_theme']['template'];
			$options['stylesheet'] = (string) $job->options['active_theme']['stylesheet'];
		} elseif ( isset( $themes[0] ) ) {
			$options['template']   = (string) $themes[0]; // Jobs started before active_theme was recorded.
			$options['stylesheet'] = (string) ( $themes[1] ?? $themes[0] );
		}

		// populate_options() only runs direct queries on $wpdb->options (no cached reads or writes of options).
		$table         = $wpdb->options;
		$wpdb->options = self::tmp( $site ) . 'options';
		$host          = ! isset( $_SERVER['HTTP_HOST'] );
		if ( $host ) {
			$_SERVER['HTTP_HOST'] = (string) wp_parse_url( home_url(), PHP_URL_HOST ); // wp_guess_url() reads it; WP-CLI does not set it.
		}
		try {
			populate_options( $options );
		} finally {
			$wpdb->options = $table;
			if ( $host ) {
				unset( $_SERVER['HTTP_HOST'] );
			}
		}

		// Carry the live values over byte for byte (no sanitizing, no filters).
		$tmp  = Connection::identifier( self::tmp( $site ) . 'options' );
		$list = implode( ', ', array_map( array( $db, 'quote' ), self::KEEP_OPTIONS ) );
		$rows = $db->rows( 'SELECT `option_name`, `option_value`, `autoload` FROM ' . Connection::identifier( $own . 'options' ) . " WHERE `option_name` IN ({$list})" );
		foreach ( $rows as $row ) {
			$name = $db->quote( (string) $row['option_name'] );
			$db->query( "DELETE FROM {$tmp} WHERE `option_name` = {$name}" );
			$db->query( "INSERT INTO {$tmp} (`option_name`, `option_value`, `autoload`) VALUES ({$name}, {$db->quote( (string) $row['option_value'] )}, {$db->quote( (string) $row['autoload'] )})" );
		}
	}

	/**
	 * Default roles, built in memory (the live roles and database are not touched).
	 *
	 * @return array<string,mixed>
	 */
	private static function default_roles(): array {
		global $wp_roles;
		$saved = $wp_roles;

		$fresh               = new \WP_Roles();
		$fresh->roles        = array();
		$fresh->role_objects = array();
		$fresh->role_names   = array();
		$fresh->use_db       = false; // populate_roles() then never calls update_option().
		$wp_roles            = $fresh; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
		try {
			populate_roles();
			return $fresh->roles;
		} finally {
			$wp_roles = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring.
		}
	}

	/**
	 * Copies the kept users (with all their meta, sessions included) and makes them administrators.
	 *
	 * @param Job        $job  Job.
	 * @param Connection $db   Connection.
	 * @param string     $live Live table prefix.
	 * @return int Users copied.
	 * @throws JobException When none of them exists any more.
	 */
	private function copy_users( Job $job, Connection $db, string $live ): int {
		$ids = array_values( array_filter( array_map( 'intval', (array) ( $job->options['keep_users'] ?? array() ) ) ) );
		if ( ! $ids ) {
			throw new JobException( 'No user to keep: nobody could log in after the reset.' );
		}
		$in = implode( ', ', $ids );

		$copied = 0;
		foreach ( array(
			'users'    => 'ID',
			'usermeta' => 'user_id',
		) as $base => $key ) {
			$columns = array_intersect( self::columns( $db, RestoreDatabase::TMP . $base ), self::columns( $db, $live . $base ) );
			$list    = implode( ', ', array_map( array( Connection::class, 'identifier' ), $columns ) );
			$db->query( 'INSERT INTO ' . Connection::identifier( RestoreDatabase::TMP . $base ) . " ({$list}) SELECT {$list} FROM " . Connection::identifier( $live . $base ) . " WHERE `{$key}` IN ({$in})" );
			if ( 'users' === $base ) {
				$copied = (int) ( $db->column( 'SELECT COUNT(*) FROM ' . Connection::identifier( RestoreDatabase::TMP . 'users' ) )[0] ?? 0 );
				if ( 0 === $copied ) {
					throw new JobException( 'None of the users to keep exists any more; start the reset again.' );
				}
			}
		}

		$meta = Connection::identifier( RestoreDatabase::TMP . 'usermeta' );
		$caps = $db->quote( $live . 'capabilities' );
		$lvl  = $db->quote( $live . 'user_level' );
		$db->query( "DELETE FROM {$meta} WHERE `user_id` IN ({$in}) AND `meta_key` IN ({$caps}, {$lvl})" );
		foreach ( $db->column( 'SELECT `ID` FROM ' . Connection::identifier( RestoreDatabase::TMP . 'users' ) ) as $id ) {
			$id = (int) $id;
			$db->query( "INSERT INTO {$meta} (`user_id`, `meta_key`, `meta_value`) VALUES ({$id}, {$caps}, 'a:1:{s:13:\"administrator\";b:1;}'), ({$id}, {$lvl}, '10')" );
		}
		return $copied;
	}

	/**
	 * The network tables of a fresh network: its site row and main site row copied, WordPress's
	 * default network settings with the live name, admin e-mail, address, kind, language and file
	 * settings, the kept users as super admins, this plugin network-active if it was. The kept users'
	 * keys for the other sites go (new sites reuse those IDs).
	 *
	 * @param Job        $job  Job.
	 * @param Connection $db   Connection.
	 * @param string     $live Network prefix.
	 * @return void
	 * @throws JobException When the network's rows are missing.
	 */
	private function fill_network( Job $job, Connection $db, string $live ): void {
		global $wpdb;
		$network = (int) get_current_network_id();
		$main    = (int) get_main_site_id();
		foreach ( array(
			'site'  => '`id` = ' . $network,
			'blogs' => '`blog_id` = ' . $main,
		) as $name => $where ) {
			$columns = array_intersect( self::columns( $db, RestoreDatabase::TMP . $name ), self::columns( $db, $live . $name ) );
			$list    = implode( ', ', array_map( array( Connection::class, 'identifier' ), $columns ) );
			$db->query( 'INSERT INTO ' . Connection::identifier( RestoreDatabase::TMP . $name ) . " ({$list}) SELECT {$list} FROM " . Connection::identifier( $live . $name ) . " WHERE {$where}" );
			if ( 1 !== (int) ( $db->column( 'SELECT COUNT(*) FROM ' . Connection::identifier( RestoreDatabase::TMP . $name ) )[0] ?? 0 ) ) {
				throw new JobException( sprintf( 'The network\'s %s row was not found; nothing was changed.', $name ) );
			}
		}

		$logins = array();
		foreach ( $db->rows( 'SELECT `ID`, `user_login` FROM ' . Connection::identifier( RestoreDatabase::TMP . 'users' ) . ' ORDER BY `ID`' ) as $row ) {
			$logins[ (int) $row['ID'] ] = (string) $row['user_login'];
		}
		$plugin   = (string) ( $job->options['keep_active_plugin'] ?? '' );
		$sitewide = array();
		if ( '' !== $plugin && isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $plugin ] ) ) {
			$sitewide[ $plugin ] = time();
		}
		$meta = array(
			'site_admins'             => array_values( $logins ),
			'admin_user_id'           => (int) array_key_first( $logins ),
			'active_sitewide_plugins' => $sitewide,
			'subdomain_install'       => is_subdomain_install() ? 1 : 0,
		);
		foreach ( array( 'site_name', 'admin_email', 'siteurl', 'WPLANG', 'upload_filetypes', 'blog_upload_space', 'fileupload_maxk', 'upload_space_check_disabled', 'ms_files_rewriting' ) as $key ) {
			$value = get_network_option( $network, $key, null );
			if ( null !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		// populate_network_meta() only inserts into $wpdb->sitemeta (and reads live settings and users).
		$table          = $wpdb->sitemeta;
		$wpdb->sitemeta = RestoreDatabase::TMP . 'sitemeta';
		try {
			populate_network_meta( $network, $meta );
		} finally {
			$wpdb->sitemeta = $table;
		}

		// Salts WordPress generated into the network settings (no constants in wp-config.php): byte for byte,
		// so nobody is logged out and what was sealed with them (schedule passwords) still opens.
		$tmp_meta = Connection::identifier( RestoreDatabase::TMP . 'sitemeta' );
		$salts    = implode( ', ', array_map( array( $db, 'quote' ), array( 'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'secret_key', 'secret_salt' ) ) );
		$db->query( "DELETE FROM {$tmp_meta} WHERE `meta_key` IN ({$salts})" );
		$db->query( "INSERT INTO {$tmp_meta} (`site_id`, `meta_key`, `meta_value`) SELECT `site_id`, `meta_key`, `meta_value` FROM " . Connection::identifier( $live . 'sitemeta' ) . " WHERE `site_id` = {$network} AND `meta_key` IN ({$salts})" );

		// New sites get IDs after the old ones: their leftover media, keys or caches never meet a new site.
		$next   = (int) ( $db->column( 'SELECT COALESCE(MAX(`blog_id`), 1) + 1 FROM ' . Connection::identifier( $live . 'blogs' ) )[0] ?? 2 );
		$status = $db->rows( 'SHOW TABLE STATUS LIKE ' . $db->quote( addcslashes( $live . 'blogs', '\\%_' ) ) );
		$next   = max( $next, (int) ( $status[0]['Auto_increment'] ?? 0 ) );
		$db->query( 'ALTER TABLE ' . Connection::identifier( RestoreDatabase::TMP . 'blogs' ) . ' AUTO_INCREMENT = ' . $next );

		// The kept users' roles and settings on the other sites, which are gone.
		$usermeta = Connection::identifier( RestoreDatabase::TMP . 'usermeta' );
		foreach ( get_sites(
			array(
				'number'  => 0,
				'fields'  => 'ids',
				'network' => $network,
			)
		) as $id ) {
			if ( (int) $id !== $main ) {
				$db->query( "DELETE FROM {$usermeta} WHERE `meta_key` LIKE " . $db->quote( addcslashes( $live . (int) $id . '_', '\\%_' ) . '%' ) );
			}
		}
		$db->query( "UPDATE {$usermeta} SET `meta_value` = '{$main}' WHERE `meta_key` = 'primary_blog'" );
	}

	/**
	 * The "Uncategorized" category (term 1), which default_category points at.
	 *
	 * @param Connection $db   Connection.
	 * @param int        $site Site of a network, or 0.
	 * @return void
	 */
	private function add_default_category( Connection $db, int $site ): void {
		$name = __( 'Uncategorized' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress's own string, as in a new install.
		$slug = sanitize_title( _x( 'Uncategorized', 'Default category slug' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Same.
		$db->query( 'INSERT INTO ' . Connection::identifier( self::tmp( $site ) . 'terms' ) . " (`term_id`, `name`, `slug`, `term_group`) VALUES (1, {$db->quote( $name )}, {$db->quote( $slug )}, 0)" );
		$db->query( 'INSERT INTO ' . Connection::identifier( self::tmp( $site ) . 'term_taxonomy' ) . " (`term_taxonomy_id`, `term_id`, `taxonomy`, `description`, `parent`, `count`) VALUES (1, 1, 'category', '', 0, 0)" );
	}

	/**
	 * Column names of a table.
	 *
	 * @param Connection $db    Connection.
	 * @param string     $table Table.
	 * @return string[]
	 */
	private static function columns( Connection $db, string $table ): array {
		return array_map( 'strval', $db->column( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote( $table ) . ' ORDER BY ORDINAL_POSITION' ) );
	}
}
