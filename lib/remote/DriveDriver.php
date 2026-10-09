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

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * Google Drive (API v3) with resumable uploads.
 *
 * Backups go into a folder path such as "FMW Backups/example.com", created
 * on first use (with the drive.file scope the plugin only sees what it
 * created itself), in My Drive or in a linked folder, which may be in a
 * shared drive (then every request says supportsAllDrives, and a file the
 * account may not delete for good goes to the shared drive's trash).
 * Uploads use Google's resumable protocol: chunks of 256 KiB multiples, and
 * after an interruption the storage is asked how many bytes it has, so the
 * upload continues exactly there. Keys are file IDs.
 */
final class DriveDriver implements Driver {

	const CHUNK_UNIT  = 262144; // 256 KiB.
	const FOLDER_MIME = 'application/vnd.google-apps.folder';
	const RETRIES     = 3;

	/**
	 * Storage settings.
	 *
	 * @var array<string,mixed>
	 */
	private $storage;

	/**
	 * Waits between retries (tests replace it).
	 *
	 * @var callable(int): void
	 */
	private $sleep;

	/**
	 * Sends a request, like Http::request() (tests replace it).
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Saves fields of the storage found on the way, such as the folder ID (tests replace it).
	 *
	 * @var callable(array<string,mixed>): void
	 */
	private $remember;

	/**
	 * Shared drive of the backups folder ('' for My Drive), known once folder() ran.
	 *
	 * @var string
	 */
	private $drive = '';

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $storage Storage.
	 */
	public function __construct( array $storage ) {
		$this->storage  = $storage;
		$this->sleep    = static function ( int $seconds ): void {
			sleep( $seconds );
		};
		$this->http     = array( Http::class, 'request' );
		$id             = (string) ( $storage['id'] ?? '' );
		$this->remember = static function ( array $fields ) use ( $id ): void {
			Storages::store()->change(
				$id,
				static function ( array $current ) use ( $fields ): array {
					return array_merge( $current, $fields );
				}
			);
		};
	}

	/**
	 * Replaces the wait between retries.
	 *
	 * @param callable(int): void $sleep Sleeper.
	 * @return void
	 */
	public function set_sleep( callable $sleep ): void {
		$this->sleep = $sleep;
	}

	/**
	 * Replaces the HTTP transport and what saves the storage's found fields (for tests).
	 *
	 * @param callable $http     Same arguments and answer as Http::request().
	 * @param callable $remember Gets the fields to save (array<string,mixed>).
	 * @return void
	 */
	public function set_transport( callable $http, callable $remember ): void {
		$this->http     = $http;
		$this->remember = $remember;
	}

	/**
	 * E-mail address of the connected Google account.
	 *
	 * @return string
	 */
	public function account(): string {
		$data = $this->json( 'GET', GoogleAuth::endpoint( 'drive' ) . '/about?fields=user(emailAddress,displayName)' );
		return (string) ( $data['user']['emailAddress'] ?? '' );
	}

	/**
	 * Writes, reads, lists and deletes a small test file.
	 *
	 * @return string What was checked.
	 * @throws RemoteException When a check fails.
	 */
	public function test(): string {
		$folder = $this->folder();
		$file   = $this->json(
			'POST',
			self::url( 'drive', '/files', array( 'fields' => 'id' ) ),
			array(
				'name'    => '.fmw-connection-test-' . bin2hex( random_bytes( 4 ) ),
				'parents' => array( $folder ),
			)
		);
		$id     = (string) ( $file['id'] ?? '' );
		$body   = 'Founders Migration Website connection test ' . gmdate( 'c' );
		try {
			$this->call( 'PATCH', self::url( 'upload', '/files/' . rawurlencode( $id ), array( 'uploadType' => 'media' ) ), array( 'content-type' => 'text/plain' ), array( 'string' => $body ) );
			$back = $this->call( 'GET', self::url( 'drive', '/files/' . rawurlencode( $id ), array( 'alt' => 'media' ) ) );
			if ( $back['body'] !== $body ) {
				throw new RemoteException( 'The test file came back different from what was written.' );
			}
			$this->files( $folder, '' );
		} finally {
			$this->delete( $id );
		}
		$account = (string) ( $this->storage['account'] ?? '' );
		return 'write, read, list and delete work' . ( '' !== $account ? ' (' . $account . ')' : '' );
	}

	/**
	 * Backups (.fmw, .wpress) in the storage folder, newest first.
	 *
	 * @return array<int,array{name:string,key:string,size:int,mtime:int}>
	 */
	public function backups(): array {
		$items = array();
		foreach ( $this->files( $this->folder(), '' ) as $file ) {
			if ( Storages::is_backup_name( $file['name'] ) ) {
				$items[] = $file;
			}
		}
		return Storages::newest_first( $items );
	}

	/**
	 * A backup by file name, or null.
	 *
	 * @param string $name File name.
	 * @return array{name:string,key:string,size:int,mtime:int,etag:string}|null
	 */
	public function find( string $name ): ?array {
		$files = $this->files( $this->folder(), basename( $name ) );
		return $files ? $files[0] + array( 'etag' => '' ) : null;
	}

	/**
	 * Starts an upload.
	 *
	 * @param string $name File name.
	 * @param int    $size Bytes.
	 * @return array<string,mixed> State, with offset 0.
	 * @throws RemoteException When Google does not start the upload.
	 */
	public function upload_open( string $name, int $size ): array {
		$response = $this->call(
			'POST',
			self::url(
				'upload',
				'/files',
				array(
					'uploadType' => 'resumable',
					'fields'     => 'id,size',
				)
			),
			array(
				'content-type'            => 'application/json; charset=UTF-8',
				'x-upload-content-type'   => 'application/octet-stream',
				'x-upload-content-length' => (string) $size,
			),
			array(
				'string' => (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Also runs outside WordPress in tests; names are plain ASCII.
					array(
						'name'    => basename( $name ),
						'parents' => array( $this->folder() ),
					)
				),
			)
		);
		$session  = (string) ( $response['headers']['location'] ?? '' );
		if ( '' === $session ) {
			throw new RemoteException( 'Google Drive did not start the upload (no session).' );
		}
		return array(
			'session'  => $session,
			'name'     => basename( $name ),
			'offset'   => 0,
			'file'     => '',
			'failures' => 0,
		);
	}

	/**
	 * Brings the state in line with what the storage really has (after a crash).
	 *
	 * @param array<string,mixed> $state State.
	 * @param int                 $size  Bytes.
	 * @return array<string,mixed>
	 */
	public function upload_sync( array $state, int $size ): array {
		if ( '' !== (string) $state['file'] ) {
			return $state;
		}
		$response = $this->session( (string) $state['session'], 'PUT', array( 'content-range' => 'bytes */' . $size ), array( 'string' => '' ) );
		return $this->apply( $state, $response, $size );
	}

	/**
	 * Length of the next chunk: $wanted adjusted to the storage's rules.
	 *
	 * @param array<string,mixed> $state  State.
	 * @param int                 $size   Bytes in total.
	 * @param int                 $wanted Bytes the caller would like to send.
	 * @return int
	 */
	public function chunk_length( array $state, int $size, int $wanted ): int {
		$left = $size - (int) $state['offset'];
		$unit = max( self::CHUNK_UNIT, (int) ( floor( $wanted / self::CHUNK_UNIT ) * self::CHUNK_UNIT ) );
		return $unit >= $left ? $left : $unit; // All but the last chunk are 256 KiB multiples.
	}

	/**
	 * Sends bytes offset..offset+length-1.
	 *
	 * @param array<string,mixed> $state  State.
	 * @param string              $path   File.
	 * @param int                 $length Bytes.
	 * @param int                 $size   Bytes in total.
	 * @return array<string,mixed> State with the new offset.
	 * @throws RemoteException When Google keeps failing.
	 */
	public function upload_chunk( array $state, string $path, int $length, int $size ): array {
		$from = (int) $state['offset'];
		try {
			$response = $this->session(
				(string) $state['session'],
				'PUT',
				array( 'content-range' => 'bytes ' . $from . '-' . ( $from + $length - 1 ) . '/' . $size ),
				array(
					'file'   => $path,
					'offset' => $from,
					'length' => $length,
				)
			);
		} catch ( RemoteException $e ) {
			if ( ! $e->is_transient() || (int) $state['failures'] >= 5 ) {
				throw $e;
			}
			// Ask Google what arrived, then carry on from there.
			( $this->sleep )( min( 30, 2 ** (int) $state['failures'] ) );
			$state['failures'] = (int) $state['failures'] + 1;
			return $this->upload_sync( $state, $size );
		}
		$state['failures'] = 0;
		return $this->apply( $state, $response, $size );
	}

	/**
	 * Finishes the upload (idempotent) and checks the size.
	 *
	 * @param array<string,mixed> $state State.
	 * @param int                 $size  Bytes.
	 * @return array{key:string,size:int}
	 * @throws RemoteException When the file is not complete.
	 */
	public function upload_close( array $state, int $size ): array {
		if ( '' === (string) $state['file'] ) {
			$state = $this->upload_sync( $state, $size );
		}
		if ( '' === (string) $state['file'] ) {
			throw new RemoteException( sprintf( 'Google Drive has %d of %d bytes; the upload is not complete.', (int) $state['offset'], $size ) );
		}
		$file = $this->json( 'GET', self::url( 'drive', '/files/' . rawurlencode( (string) $state['file'] ), array( 'fields' => 'id,size' ) ) );
		if ( (int) ( $file['size'] ?? -1 ) !== $size ) {
			throw new RemoteException( sprintf( 'The uploaded copy has %s bytes instead of %d.', (string) ( $file['size'] ?? 'no' ), $size ) );
		}
		// Drive allows several files with one name: remove earlier uploads of the same backup.
		foreach ( $this->files( $this->folder(), (string) $state['name'] ) as $other ) {
			if ( $other['key'] !== (string) $state['file'] ) {
				$this->delete( $other['key'] );
			}
		}
		return array(
			'key'  => (string) $state['file'],
			'size' => $size,
		);
	}

	/**
	 * Abandons an unfinished upload (best effort).
	 *
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	public function upload_abort( array $state ): void {
		if ( '' !== (string) ( $state['session'] ?? '' ) && '' === (string) ( $state['file'] ?? '' ) ) {
			try {
				$this->session( (string) $state['session'], 'DELETE', array(), array( 'string' => '' ) );
			} catch ( RemoteException $e ) {
				unset( $e ); // Google drops unfinished sessions after a week anyway.
			}
		}
	}

	/**
	 * Downloads bytes $from..$to of a backup into an open file.
	 *
	 * @param string   $key  Key.
	 * @param int      $from First byte.
	 * @param int      $to   Last byte.
	 * @param resource $sink Writable handle at the right position.
	 * @param string   $etag Version seen when the download started ('' for any).
	 * @return int Bytes written.
	 * @throws RemoteException When the answer is not the range asked for.
	 */
	public function download_range( string $key, int $from, int $to, $sink, string $etag ): int {
		$start = (int) ftell( $sink );
		$this->call( 'GET', self::url( 'drive', '/files/' . rawurlencode( $key ), array( 'alt' => 'media' ) ), array( 'range' => 'bytes=' . $from . '-' . $to ), array( 'string' => '' ), $sink, $to - $from + 1 );
		$written = (int) ftell( $sink ) - $start;
		if ( $written !== $to - $from + 1 ) {
			throw new RemoteException( sprintf( 'Download stopped after %d of %d bytes.', $written, $to - $from + 1 ) );
		}
		return $written;
	}

	/**
	 * Deletes a backup (no error when it is already gone).
	 *
	 * In a shared drive only managers may delete a file for good; for a
	 * content manager the file goes to the shared drive's trash instead,
	 * which Google empties after 30 days.
	 *
	 * @param string $key Key.
	 * @return void
	 * @throws RemoteException When Google refuses.
	 */
	public function delete( string $key ): void {
		$url = self::url( 'drive', '/files/' . rawurlencode( $key ) );
		try {
			try {
				$this->call( 'DELETE', $url );
			} catch ( RemoteException $e ) {
				if ( 403 !== $e->status ) {
					throw $e;
				}
				$this->json( 'PATCH', $url, array( 'trashed' => true ) );
			}
		} catch ( RemoteException $e ) {
			if ( 404 === $e->status ) {
				return;
			}
			if ( 403 === $e->status ) {
				throw new RemoteException( 'Google Drive does not let this account delete files in the backups folder. In a shared drive it needs the Content manager role (a Contributor can only add files). ' . $e->getMessage(), 403, $e->error_code );
			}
			throw $e;
		}
	}

	/**
	 * Updates an upload state from Google's answer (308: this many bytes so far; 200/201: done).
	 *
	 * @param array<string,mixed>                                        $state    State.
	 * @param array{status:int,headers:array<string,string>,body:string} $response Response.
	 * @param int                                                        $size     Bytes in total.
	 * @return array<string,mixed>
	 */
	private function apply( array $state, array $response, int $size ): array {
		if ( in_array( $response['status'], array( 200, 201 ), true ) ) {
			$file            = json_decode( $response['body'], true );
			$state['file']   = is_array( $file ) ? (string) ( $file['id'] ?? '' ) : '';
			$state['offset'] = $size;
			return $state;
		}
		// 308 Resume Incomplete. "Range: bytes=0-N" means N+1 bytes arrived; no Range means none.
		$range           = (string) ( $response['headers']['range'] ?? '' );
		$state['offset'] = 1 === preg_match( '/bytes=0-(\d+)/', $range, $match ) ? (int) $match[1] + 1 : 0;
		return $state;
	}

	/**
	 * A request to an upload session URI; after a 401 once more with a new token.
	 *
	 * @param string                                                     $session Session URI.
	 * @param string                                                     $method  Method.
	 * @param array<string,string>                                       $headers Headers.
	 * @param array{string?:string,file?:string,offset?:int,length?:int} $body    Body.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 * @throws RemoteException UploadExpired when Google forgot the session, otherwise on an error.
	 */
	private function session( string $session, string $method, array $headers, array $body ): array {
		if ( 0 !== strpos( $session, GoogleAuth::endpoint( 'upload' ) . '/' ) ) {
			throw new RemoteException( 'Unexpected upload session address.' ); // Only ever talk to Google's upload host.
		}
		$headers['authorization'] = 'Bearer ' . GoogleAuth::access_token( $this->storage );
		$response                 = ( $this->http )( $session, $method, $headers, $body );
		if ( 401 === $response['status'] ) {
			// The token was revoked or expired early; Google keeps no bytes of a refused request.
			$headers['authorization'] = 'Bearer ' . GoogleAuth::access_token( $this->storage, true );
			$response                 = ( $this->http )( $session, $method, $headers, $body );
		}
		if ( in_array( $response['status'], array( 404, 410 ), true ) ) {
			throw new RemoteException( 'Google Drive no longer knows the unfinished upload.', $response['status'], 'UploadExpired' );
		}
		if ( 'DELETE' === $method && 499 === $response['status'] ) {
			return $response; // Cancelled.
		}
		if ( ! in_array( $response['status'], array( 200, 201, 308 ), true ) ) {
			throw new RemoteException( ...self::error( $response ) );
		}
		return $response;
	}

	/**
	 * Files in a folder (optionally with one name), newest first.
	 *
	 * @param string $folder Folder ID.
	 * @param string $name   Exact name, or '' for all.
	 * @return array<int,array{name:string,key:string,size:int,mtime:int}>
	 */
	private function files( string $folder, string $name ): array {
		$query = "'" . self::quote( $folder ) . "' in parents and trashed = false and mimeType != '" . self::FOLDER_MIME . "'";
		if ( '' !== $name ) {
			$query .= " and name = '" . self::quote( $name ) . "'";
		}
		$files = array();
		$token = '';
		do {
			$params = array(
				'q'        => $query,
				'fields'   => 'nextPageToken,files(id,name,size,modifiedTime)',
				'pageSize' => '1000',
				'orderBy'  => 'modifiedTime desc',
				'spaces'   => 'drive',
			) + $this->corpus();
			if ( '' !== $token ) {
				$params['pageToken'] = $token;
			}
			$data = $this->json( 'GET', self::url( 'drive', '/files', $params ) );
			foreach ( (array) ( $data['files'] ?? array() ) as $file ) {
				$files[] = array(
					'name'  => (string) ( $file['name'] ?? '' ),
					'key'   => (string) ( $file['id'] ?? '' ),
					'size'  => (int) ( $file['size'] ?? 0 ),
					'mtime' => (int) strtotime( (string) ( $file['modifiedTime'] ?? '' ) ),
				);
			}
			$token = (string) ( $data['nextPageToken'] ?? '' );
			$count = count( $files );
		} while ( '' !== $token && $count < 20000 );
		return $files;
	}

	/**
	 * ID of the backups folder, found or created along its path (in My Drive or the linked folder) and remembered.
	 *
	 * @return string
	 * @throws RemoteException When it cannot be created, or the linked folder cannot be used.
	 */
	private function folder(): string {
		$path   = trim( (string) $this->storage['prefix'], '/' );
		$linked = (string) ( $this->storage['parent'] ?? '' );
		$where  = ( '' !== $linked ? $linked . ':' : '' ) . $path; // What a remembered folder ID stands for.
		$id     = (string) ( $this->storage['folder_id'] ?? '' );
		if ( '' !== $id && (string) ( $this->storage['folder_path'] ?? '' ) === $where ) {
			try {
				$data = $this->json( 'GET', self::url( 'drive', '/files/' . rawurlencode( $id ), array( 'fields' => 'id,trashed,driveId' ) ) );
				if ( empty( $data['trashed'] ) ) {
					$this->drive = (string) ( $data['driveId'] ?? '' );
					return $id;
				}
			} catch ( RemoteException $e ) {
				if ( 404 !== $e->status ) {
					throw $e;
				}
			}
		}

		$this->drive = '';
		$name        = '' !== $linked ? $this->linked( $linked ) : '';
		$parent      = '' !== $linked ? $linked : 'root';
		foreach ( '' === $path ? array() : explode( '/', $path ) as $segment ) {
			$found = $this->subfolder( $parent, $segment );
			for ( $attempt = 1; '' === $found && $attempt <= self::RETRIES; $attempt++ ) {
				try {
					// Sent once: a retried create after a lost answer would make a second folder.
					$made  = $this->json(
						'POST',
						self::url( 'drive', '/files', array( 'fields' => 'id' ) ),
						array(
							'name'     => $segment,
							'mimeType' => self::FOLDER_MIME,
							'parents'  => array( $parent ),
						),
						1
					);
					$found = (string) ( $made['id'] ?? '' );
				} catch ( RemoteException $e ) {
					if ( $attempt >= self::RETRIES || ! $e->is_transient() ) {
						throw $e;
					}
					( $this->sleep )( 2 ** ( $attempt - 1 ) );
					$found = $this->subfolder( $parent, $segment ); // Maybe it was created after all.
				}
			}
			if ( '' === $found ) {
				throw new RemoteException( sprintf( 'Cannot create the folder "%s" in Google Drive.', $segment ) );
			}
			$parent = $found;
		}

		$found         = array(
			'folder_id'   => $parent,
			'folder_path' => $where,
			'parent_name' => $name,
		);
		$this->storage = $found + $this->storage;
		( $this->remember )( $found );
		return $parent;
	}

	/**
	 * Checks the linked folder and returns its name; notes its shared drive.
	 *
	 * @param string $id Folder ID from the link.
	 * @return string
	 * @throws RemoteException When it is missing, not a folder, in the trash, or read-only for this account.
	 */
	private function linked( string $id ): string {
		try {
			$data = $this->json( 'GET', self::url( 'drive', '/files/' . rawurlencode( $id ), array( 'fields' => 'id,name,mimeType,trashed,driveId,capabilities(canAddChildren)' ) ) );
		} catch ( RemoteException $e ) {
			if ( 404 === $e->status ) {
				$account = (string) ( $this->storage['account'] ?? '' );
				throw new RemoteException( sprintf( 'Google Drive cannot find the linked folder%s. Check the link, and that this account can open the folder (in a shared drive: as a member with the Content manager role).', '' !== $account ? ' with ' . $account : '' ), 404, $e->error_code );
			}
			throw $e;
		}
		if ( self::FOLDER_MIME !== (string) ( $data['mimeType'] ?? '' ) ) {
			throw new RemoteException( 'The link points to a file, not a folder. Link the folder that should hold the backups.' );
		}
		if ( ! empty( $data['trashed'] ) ) {
			throw new RemoteException( 'The linked folder is in the trash of Google Drive.' );
		}
		if ( isset( $data['capabilities']['canAddChildren'] ) && ! $data['capabilities']['canAddChildren'] ) {
			throw new RemoteException( 'This Google account cannot add files to the linked folder. Give it edit access (in a shared drive: the Content manager role).' );
		}
		$this->drive = (string) ( $data['driveId'] ?? '' );
		return (string) ( $data['name'] ?? '' );
	}

	/**
	 * Search parameters that reach into the shared drive of the backups folder.
	 *
	 * @return array<string,string>
	 */
	private function corpus(): array {
		$params = array( 'includeItemsFromAllDrives' => 'true' );
		if ( '' !== $this->drive ) {
			$params['corpora'] = 'drive';
			$params['driveId'] = $this->drive;
		}
		return $params;
	}

	/**
	 * Address of a Drive API call; every call may touch shared drives.
	 *
	 * @param string               $endpoint drive or upload.
	 * @param string               $path     Path, such as /files/ID.
	 * @param array<string,string> $query    Query parameters.
	 * @return string
	 */
	private static function url( string $endpoint, string $path, array $query = array() ): string {
		return GoogleAuth::endpoint( $endpoint ) . $path . '?' . http_build_query( $query + array( 'supportsAllDrives' => 'true' ), '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * ID of a folder by name inside a parent, or ''.
	 *
	 * @param string $within Parent folder ID.
	 * @param string $name   Folder name.
	 * @return string
	 */
	private function subfolder( string $within, string $name ): string {
		$query = "'" . self::quote( $within ) . "' in parents and trashed = false and mimeType = '" . self::FOLDER_MIME . "' and name = '" . self::quote( $name ) . "'";
		$data  = $this->json( 'GET', self::url( 'drive', '/files', array( 'q' => $query, 'fields' => 'files(id)', 'spaces' => 'drive' ) + $this->corpus() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Query.
		return (string) ( $data['files'][0]['id'] ?? '' );
	}

	/**
	 * A JSON API call.
	 *
	 * @param string                   $method Method.
	 * @param string                   $url    URL.
	 * @param array<string,mixed>|null $body   JSON body.
	 * @param int                      $tries  Attempts after network trouble (1 for requests that must not repeat).
	 * @return array<string,mixed>
	 */
	private function json( string $method, string $url, ?array $body = null, int $tries = self::RETRIES ): array {
		$response = null === $body
			? $this->call( $method, $url, array(), array( 'string' => '' ), null, 0, $tries )
			: $this->call( $method, $url, array( 'content-type' => 'application/json; charset=UTF-8' ), array( 'string' => (string) json_encode( $body ) ), null, 0, $tries ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Also runs outside WordPress in tests; folder and file names are plain ASCII.
		$data     = json_decode( $response['body'], true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * An authorized API call, retried after network trouble, server errors and rate limits, and once after a 401 with a new token.
	 *
	 * @param string                                                     $method  Method.
	 * @param string                                                     $url     URL.
	 * @param array<string,string>                                       $headers Headers.
	 * @param array{string?:string,file?:string,offset?:int,length?:int} $body    Body.
	 * @param resource|null                                              $sink    Range download target.
	 * @param int                                                        $limit   Bytes expected into $sink.
	 * @param int                                                        $tries   Attempts.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 * @throws RemoteException When it fails for good.
	 */
	private function call( string $method, string $url, array $headers = array(), array $body = array( 'string' => '' ), $sink = null, int $limit = 0, int $tries = self::RETRIES ): array {
		$refreshed = false;
		$start     = null === $sink ? 0 : (int) ftell( $sink );
		for ( $attempt = 1; ; $attempt++ ) {
			$headers['authorization'] = 'Bearer ' . GoogleAuth::access_token( $this->storage, $refreshed );
			try {
				$response = ( $this->http )( $url, $method, $headers, $body, $sink, $limit );
				if ( 401 === $response['status'] && ! $refreshed ) {
					$refreshed = true; // The token was revoked or expired early: get a new one, once.
					continue;
				}
				if ( $response['status'] >= 200 && $response['status'] < 300 ) {
					return $response;
				}
				throw new RemoteException( ...self::error( $response ) );
			} catch ( RemoteException $e ) {
				if ( $attempt >= $tries || ! $e->is_transient() ) {
					throw $e;
				}
				if ( null !== $sink ) {
					ftruncate( $sink, $start );
					fseek( $sink, $start );
				}
				( $this->sleep )( 2 ** ( $attempt - 1 ) );
			}
		}
	}

	/**
	 * Message, status and reason of a Google API error (RemoteException arguments).
	 *
	 * @param array{status:int,headers:array<string,string>,body:string} $response Response.
	 * @return array{0:string,1:int,2:string}
	 */
	private static function error( array $response ): array {
		$data    = json_decode( $response['body'], true );
		$error   = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		$reason  = (string) ( $error['errors'][0]['reason'] ?? ( $error['status'] ?? '' ) );
		$message = (string) ( $error['message'] ?? 'HTTP ' . $response['status'] );
		$hints   = array(
			'storageQuotaExceeded'    => 'The Google Drive is full; free up space or buy more storage.',
			'accessNotConfigured'     => 'Enable the Google Drive API in the Google Cloud project of the OAuth client.',
			'insufficientPermissions' => 'Connect the storage again and allow access to Google Drive.',
			'appNotAuthorizedToFile'  => 'Connect the storage again so that the plugin may use the linked folder.',
		);
		$status  = in_array( $reason, array( 'rateLimitExceeded', 'userRateLimitExceeded' ), true ) ? 429 : $response['status'];
		return array( trim( sprintf( 'Google Drive error %d%s: %s %s', $response['status'], '' !== $reason ? ' ' . $reason : '', $message, $hints[ $reason ] ?? '' ), " \t\n\r\0\x0B" ), $status, $reason );
	}

	/**
	 * Escapes a value for a Drive search query.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function quote( string $value ): string {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
	}
}
