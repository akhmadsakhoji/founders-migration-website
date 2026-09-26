<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Remote;

use Founders\Migration\Job\Secrets;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * Checks and builds cloud storage settings from user input.
 *
 * A storage: id, name, provider, endpoint, region, bucket, prefix (folder
 * in the bucket, '' for the root), access_key, secret (the secret key,
 * sealed with the site's salts), path_style, storage_class, created_at.
 */
final class StorageOptions {

	const STORAGE_CLASSES = array( 'STANDARD', 'STANDARD_IA', 'ONEZONE_IA', 'INTELLIGENT_TIERING', 'GLACIER_IR' );

	/**
	 * Provider presets: label, endpoint template ({region}), default region, path-style addressing.
	 *
	 * @return array<string,array{label:string,endpoint:string,region:string,path_style:bool}>
	 */
	public static function providers(): array {
		return array(
			'aws'    => array(
				'label'      => 'Amazon S3',
				'endpoint'   => 'https://s3.{region}.amazonaws.com', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- API endpoint of the user's own S3 bucket for backups, no assets are loaded from it.
				'region'     => 'ap-southeast-3',
				'path_style' => false,
			),
			'r2'     => array(
				'label'      => 'Cloudflare R2',
				'endpoint'   => 'https://<account-id>.r2.cloudflarestorage.com',
				'region'     => 'auto',
				'path_style' => true,
			),
			'wasabi' => array(
				'label'      => 'Wasabi',
				'endpoint'   => 'https://s3.{region}.wasabisys.com',
				'region'     => 'ap-southeast-1',
				'path_style' => true,
			),
			'b2'     => array(
				'label'      => 'Backblaze B2',
				'endpoint'   => 'https://s3.{region}.backblazeb2.com',
				'region'     => 'us-west-004',
				'path_style' => true,
			),
			'spaces' => array(
				'label'      => 'DigitalOcean Spaces',
				'endpoint'   => 'https://{region}.digitaloceanspaces.com',
				'region'     => 'sgp1',
				'path_style' => false,
			),
			'gdrive' => array(
				'label'      => 'Google Drive',
				'endpoint'   => '',
				'region'     => '',
				'path_style' => false,
			),
			'custom' => array(
				'label'      => 'Other S3-compatible (MinIO, ...)',
				'endpoint'   => '',
				'region'     => 'us-east-1',
				'path_style' => true,
			),
		);
	}

	/**
	 * A new or changed storage.
	 *
	 * @param array<string,mixed>      $input    Fields to set; secret_key '' keeps the saved one.
	 * @param array<string,mixed>|null $existing Storage being changed, or null.
	 * @param int                      $now      Unix time.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On an invalid value.
	 */
	public static function build( array $input, ?array $existing, int $now ): array {
		$storage   = $existing ?? array(
			'id'            => bin2hex( random_bytes( 4 ) ),
			'name'          => '',
			'provider'      => 'custom',
			'endpoint'      => '',
			'region'        => '',
			'bucket'        => '',
			'prefix'        => '',
			'access_key'    => '',
			'secret'        => '',
			'path_style'    => true,
			'storage_class' => '',
			'created_at'    => $now,
		);
		$providers = self::providers();

		if ( array_key_exists( 'provider', $input ) ) {
			$provider = strtolower( (string) $input['provider'] );
			if ( ! isset( $providers[ $provider ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Provider must be one of: %s.', implode( ', ', array_keys( $providers ) ) ) );
			}
			if ( null !== $existing && ( 'gdrive' === $provider ) !== ( 'gdrive' === $existing['provider'] ) ) {
				throw new \InvalidArgumentException( 'A storage cannot change between Google Drive and S3; add a new storage instead.' );
			}
			$storage['provider'] = $provider;
			if ( 'gdrive' === $provider ) {
				return self::build_drive( $input, $existing, $now, (string) $storage['id'] );
			}
			if ( null === $existing ) {
				$storage['path_style'] = $providers[ $provider ]['path_style'];
				$storage['region']     = $providers[ $provider ]['region'];
			}
		}
		if ( 'gdrive' === $storage['provider'] ) {
			return self::build_drive( $input, $existing, $now, (string) $storage['id'] );
		}
		$preset = $providers[ $storage['provider'] ];

		if ( array_key_exists( 'name', $input ) ) {
			$storage['name'] = self::clean( (string) $input['name'], 100 );
		}
		if ( array_key_exists( 'region', $input ) && '' !== trim( (string) $input['region'], " \t\n\r\0\x0B" ) ) {
			$region = strtolower( trim( (string) $input['region'], " \t\n\r\0\x0B" ) );
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $region ) ) {
				throw new \InvalidArgumentException( 'Region looks wrong (for example ap-southeast-3, auto, sgp1).' );
			}
			$storage['region'] = $region;
		}
		if ( '' === (string) $storage['region'] ) {
			$storage['region'] = $preset['region'];
		}
		if ( array_key_exists( 'endpoint', $input ) ) {
			$storage['endpoint'] = trim( (string) $input['endpoint'], " \t\n\r\0\x0B" );
		}
		if ( '' === $storage['endpoint'] && false !== strpos( $preset['endpoint'], '{region}' ) ) {
			$storage['endpoint'] = str_replace( '{region}', (string) $storage['region'], $preset['endpoint'] );
		}
		$storage['endpoint'] = self::endpoint( (string) $storage['endpoint'] );

		if ( array_key_exists( 'bucket', $input ) ) {
			$storage['bucket'] = trim( (string) $input['bucket'], " \t\n\r\0\x0B" );
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{1,254}$/', (string) $storage['bucket'] ) ) {
			throw new \InvalidArgumentException( 'Bucket name must be 3 to 255 letters, digits, dots, dashes or underscores.' );
		}
		if ( array_key_exists( 'prefix', $input ) ) {
			$storage['prefix'] = self::prefix( (string) $input['prefix'] );
		}
		if ( array_key_exists( 'access_key', $input ) ) {
			$storage['access_key'] = trim( (string) $input['access_key'], " \t\n\r\0\x0B" );
		}
		if ( 1 !== preg_match( '/^\S{3,128}$/', (string) $storage['access_key'] ) ) {
			throw new \InvalidArgumentException( 'Enter the access key (key ID).' );
		}
		if ( isset( $input['secret_key'] ) && '' !== (string) $input['secret_key'] ) {
			$secret = trim( (string) $input['secret_key'], " \t\n\r\0\x0B" );
			if ( 1 !== preg_match( '/^\S{8,256}$/', $secret ) ) {
				throw new \InvalidArgumentException( 'The secret key looks wrong (no spaces, at least 8 characters).' );
			}
			$storage['secret'] = Secrets::seal( $secret );
		}
		if ( '' === (string) $storage['secret'] ) {
			throw new \InvalidArgumentException( 'Enter the secret key.' );
		}
		if ( array_key_exists( 'path_style', $input ) ) {
			$storage['path_style'] = self::bool( $input['path_style'] );
		}
		if ( array_key_exists( 'storage_class', $input ) ) {
			$class = strtoupper( trim( (string) $input['storage_class'], " \t\n\r\0\x0B" ) );
			if ( '' !== $class && ! in_array( $class, self::STORAGE_CLASSES, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Storage class must be one of: %s.', implode( ', ', self::STORAGE_CLASSES ) ) );
			}
			$storage['storage_class'] = $class;
		}
		if ( '' === $storage['name'] ) {
			$storage['name'] = $preset['label'] . ' · ' . $storage['bucket'];
		}
		return $storage;
	}

	/**
	 * Storage without its sealed secret, for display and the REST API.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return array<string,mixed>
	 */
	public static function public_view( array $storage ): array {
		$storage['provider_label'] = self::providers()[ $storage['provider'] ]['label'] ?? (string) $storage['provider'];
		if ( 'gdrive' === $storage['provider'] ) {
			$storage['connected'] = ! empty( $storage['refresh'] );
			$storage['location']  = 'My Drive/' . $storage['prefix'];
		} else {
			$storage['location'] = $storage['bucket'] . ( '' !== $storage['prefix'] ? '/' . $storage['prefix'] : '' );
		}
		unset( $storage['secret'], $storage['refresh'], $storage['access'] );
		return $storage;
	}

	/**
	 * A new or changed Google Drive storage.
	 *
	 * Fields: client_id and client_secret of the site's own OAuth client
	 * ("Web application" in the Google Cloud Console), prefix (folder path
	 * in My Drive). A new client ID drops the Google sign-in.
	 *
	 * @param array<string,mixed>      $input    Fields; client_secret '' keeps the saved one.
	 * @param array<string,mixed>|null $existing Storage being changed.
	 * @param int                      $now      Unix time.
	 * @param string                   $id       ID of a new storage.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On an invalid value.
	 */
	private static function build_drive( array $input, ?array $existing, int $now, string $id ): array {
		$host    = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
		$storage = $existing ?? array(
			'id'           => $id,
			'name'         => '',
			'provider'     => 'gdrive',
			'auth'         => 'own',
			'client_id'    => '',
			'secret'       => '',
			'prefix'       => 'FMW Backups' . ( '' !== $host ? '/' . $host : '' ),
			'folder_id'    => '',
			'folder_path'  => '',
			'refresh'      => '',
			'access'       => '',
			'account'      => '',
			'connected_at' => 0,
			'created_at'   => $now,
		);
		if ( array_key_exists( 'name', $input ) ) {
			$storage['name'] = self::clean( (string) $input['name'], 100 );
		}
		if ( array_key_exists( 'client_id', $input ) ) {
			$client = trim( (string) $input['client_id'], " \t\n\r\0\x0B" );
			if ( 1 !== preg_match( '/^[A-Za-z0-9._-]{8,200}\.apps\.googleusercontent\.com$/', $client ) ) {
				throw new \InvalidArgumentException( 'The client ID looks wrong; it ends with .apps.googleusercontent.com.' );
			}
			if ( $client !== $storage['client_id'] ) {
				// Another OAuth client: the earlier sign-in belongs to the old one.
				$storage['refresh']   = '';
				$storage['access']    = '';
				$storage['account']   = '';
				$storage['folder_id'] = '';
			}
			$storage['client_id'] = $client;
		}
		if ( '' === (string) $storage['client_id'] ) {
			throw new \InvalidArgumentException( 'Enter the client ID of your Google OAuth client.' );
		}
		if ( isset( $input['client_secret'] ) && '' !== (string) $input['client_secret'] ) {
			$secret = trim( (string) $input['client_secret'], " \t\n\r\0\x0B" );
			if ( 1 !== preg_match( '/^\S{8,200}$/', $secret ) ) {
				throw new \InvalidArgumentException( 'The client secret looks wrong.' );
			}
			$storage['secret'] = Secrets::seal( $secret );
		}
		if ( '' === (string) $storage['secret'] ) {
			throw new \InvalidArgumentException( 'Enter the client secret of your Google OAuth client.' );
		}
		if ( array_key_exists( 'prefix', $input ) ) {
			$prefix = self::prefix( (string) $input['prefix'] );
			if ( '' === $prefix ) {
				throw new \InvalidArgumentException( 'Enter a folder for the backups in Google Drive, for example "FMW Backups".' );
			}
			if ( $prefix !== $storage['prefix'] ) {
				$storage['folder_id'] = '';
			}
			$storage['prefix'] = $prefix;
		}
		if ( '' === $storage['name'] ) {
			$storage['name'] = 'Google Drive · ' . $storage['prefix'];
		}
		return $storage;
	}

	/**
	 * Object key of a backup in a storage.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @param string              $name    Backup file name.
	 * @return string
	 */
	public static function key( array $storage, string $name ): string {
		return ( '' !== (string) $storage['prefix'] ? $storage['prefix'] . '/' : '' ) . basename( $name );
	}

	/**
	 * A checked endpoint URL: http(s), a host, no user, query or fragment; no trailing slash.
	 *
	 * @param string $url URL.
	 * @return string
	 * @throws \InvalidArgumentException When it is not one.
	 */
	public static function endpoint( string $url ): string {
		$url   = rtrim( trim( $url, " \t\n\r\0\x0B" ), '/' );
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Also used without WordPress.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || false !== strpos( $url, '<' ) ) {
			throw new \InvalidArgumentException( 'Enter the endpoint URL, for example https://s3.ap-southeast-3.amazonaws.com.' );
		}
		return $url;
	}

	/**
	 * A folder inside the bucket: segments of safe characters, no leading or trailing slash.
	 *
	 * @param string $prefix Folder.
	 * @return string
	 * @throws \InvalidArgumentException On unsafe characters or "..".
	 */
	public static function prefix( string $prefix ): string {
		$prefix = trim( str_replace( '\\', '/', trim( $prefix, " \t\n\r\0\x0B" ) ), '/' );
		if ( '' === $prefix ) {
			return '';
		}
		foreach ( explode( '/', $prefix ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment || 1 !== preg_match( '/^[A-Za-z0-9._ -]{1,100}$/', $segment ) ) {
				throw new \InvalidArgumentException( 'Folder may contain letters, digits, spaces, dots, dashes and underscores, separated by "/".' );
			}
		}
		return substr( $prefix, 0, 300 );
	}

	/**
	 * A yes/no value from JSON or a flag.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function bool( $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}

	/**
	 * Single-line text without control characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function clean( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ), " \t\n\r\0\x0B" );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}
}
