<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Tests\Unit;

use Founders\Migration\Job\Secrets;
use Founders\Migration\Remote\DriveDriver;
use Founders\Migration\Remote\GoogleAuth;
use Founders\Migration\Remote\RemoteException;
use Founders\Migration\Remote\StorageOptions;
use Founders\Migration\Remote\Storages;
use Founders\Migration\Tests\TestCase;

/**
 * Google Drive: linked folders (shared drives), the scope they need, and the API requests (against a fake Google).
 */
final class GoogleDriveTest extends TestCase {

	const FOLDER = 'application/vnd.google-apps.folder';

	/**
	 * Requests the fake Google received.
	 *
	 * @var array<int,array{method:string,url:string,body:string}>
	 */
	private $requests = array();

	/**
	 * Fields the driver saved.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $remembered = array();

	public function test_folder_links_from_the_browser_are_read(): void {
		$id = '1AbCdEfGhIjKlMnOpQrStUvWxYz_0123-';
		foreach (
			array(
				'https://drive.google.com/drive/folders/' . $id,
				'https://drive.google.com/drive/folders/' . $id . '?usp=sharing',
				'https://drive.google.com/drive/u/1/folders/' . $id,
				'https://drive.google.com/drive/mobile/folders/' . $id . '/',
				'https://drive.google.com/open?id=' . $id,
				'https://drive.google.com/open?usp=sharing&id=' . $id,
				"  $id\n",
			) as $link
		) {
			$this->assertSame( $id, StorageOptions::drive_folder( $link ), $link );
		}
		$this->assertSame( '0AFk2uZx9shared', StorageOptions::drive_folder( 'https://drive.google.com/drive/folders/0AFk2uZx9shared' ), 'Root of a shared drive.' );
		$this->assertSame( '', StorageOptions::drive_folder( ' ' ) );

		foreach (
			array(
				'https://drive.google.com/drive/my-drive',
				'https://drive.google.com/file/d/' . $id . '/view',
				'http://drive.google.com/drive/folders/' . $id,
				'https://drive.google.com.example.com/drive/folders/' . $id,
				'https://example.com/?x=https://drive.google.com/drive/folders/' . $id,
				"1AbCdEf' or name contains '",
				'short',
			) as $link
		) {
			try {
				StorageOptions::drive_folder( $link );
				$this->fail( 'Accepted ' . $link );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'drive.google.com/drive/folders/', $e->getMessage() );
			}
		}
	}

	public function test_a_linked_folder_asks_for_the_full_scope_and_drops_a_narrower_sign_in(): void {
		$link    = 'https://drive.google.com/drive/folders/0AFk2uZx9shared';
		$storage = StorageOptions::build(
			array(
				'provider'      => 'gdrive',
				'client_id'     => '1234567890-abc.apps.googleusercontent.com',
				'client_secret' => 'GOCSPX-very-secret',
				'folder_link'   => $link,
			),
			null,
			1
		);
		$this->assertSame( '0AFk2uZx9shared', $storage['parent'] );
		$this->assertSame( '', $storage['prefix'], 'Without a folder the backups go right into the linked folder.' );
		$this->assertSame( 'Google Drive · linked folder', $storage['name'] );
		$this->assertSame( GoogleAuth::SCOPE_FULL, GoogleAuth::scope( $storage ) );
		$view = StorageOptions::public_view( $storage );
		$this->assertSame( $link, $view['folder_link'] );
		$this->assertSame( $link, $view['location'] );
		$view = StorageOptions::public_view( array( 'parent_name' => 'ngobrolyuk.com' ) + $storage );
		$this->assertSame( 'ngobrolyuk.com', $view['location'] );

		$inside = StorageOptions::build( array( 'prefix' => 'ngobrolyuk.com' ), $storage, 1 );
		$this->assertSame( 'website-client/ngobrolyuk.com', StorageOptions::public_view( array( 'parent_name' => 'website-client' ) + $inside )['location'] );

		// A My Drive storage, connected with drive.file.
		$mine = array(
			'refresh'     => 'sealed',
			'access'      => 'sealed',
			'account'     => 'a@example.com',
			'folder_id'   => 'F1',
			'folder_path' => 'FMW Backups',
		) + StorageOptions::build(
			array(
				'provider'      => 'gdrive',
				'client_id'     => '1234567890-abc.apps.googleusercontent.com',
				'client_secret' => 'GOCSPX-very-secret',
				'prefix'        => 'FMW Backups',
			),
			null,
			1
		);
		unset( $mine['parent'], $mine['parent_name'] ); // Saved by an earlier version.
		$this->assertSame( GoogleAuth::SCOPE, GoogleAuth::scope( $mine ) );
		$this->assertSame( 'My Drive/FMW Backups', StorageOptions::public_view( $mine )['location'] );
		$this->assertSame( 'sealed', StorageOptions::build( array( 'folder_link' => '' ), $mine, 1 )['refresh'], 'No link before or after: nothing changes.' );

		$linked = StorageOptions::build( array( 'folder_link' => $link ), $mine, 1 );
		$this->assertSame( '', $linked['refresh'], 'The full scope needs a new sign-in.' );
		$this->assertSame( '', $linked['account'] );
		$this->assertSame( '', $linked['folder_id'] );
		$this->assertSame( 'FMW Backups', $linked['prefix'], 'An existing storage keeps its folder path.' );
		$kept = Storages::keep_found( $mine, $linked );
		$this->assertSame( '', $kept['refresh'], 'Saving does not bring back the narrower sign-in.' );
		$this->assertSame( '', $kept['folder_id'], 'Nor the folder found in My Drive.' );

		$connected = array(
			'refresh'     => 'sealed-full',
			'folder_id'   => 'F2',
			'parent_name' => 'website-client',
		) + $linked;
		$renamed   = StorageOptions::build( array( 'name' => 'Shared' ), $connected, 1 );
		$this->assertSame( 'sealed-full', Storages::keep_found( $connected, $renamed )['refresh'] );
		$this->assertSame( 'F2', Storages::keep_found( $connected, $renamed )['folder_id'] );
		$this->assertSame( 'website-client', Storages::keep_found( $connected, $renamed )['parent_name'] );

		$other = StorageOptions::build( array( 'folder_link' => 'https://drive.google.com/drive/folders/1OtherFolder99' ), $connected, 1 );
		$this->assertSame( 'sealed-full', $other['refresh'], 'Another linked folder needs the same scope.' );
		$this->assertSame( '', $other['folder_id'] );
		$this->assertSame( '', $other['parent_name'] );
		$this->assertSame( '', Storages::keep_found( $connected, $other )['folder_id'] );

		$back = StorageOptions::build( array( 'folder_link' => '' ), $connected, 1 );
		$this->assertSame( '', $back['refresh'], 'Back to drive.file: sign in again.' );
		$this->assertSame( '', $back['parent'] );

		foreach ( array( array( 'folder_link' => '', 'prefix' => '' ), array( 'folder_link' => 'https://drive.google.com/file/d/1AbCdEfGhIjKl/view' ) ) as $change ) {
			try {
				StorageOptions::build( $change, $connected, 1 );
				$this->fail( 'Accepted ' . json_encode( $change ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_a_folder_in_a_shared_drive_is_used_with_shared_drive_requests(): void {
		$driver = $this->driver(
			array(
				'parent' => '0AFk2uZx9shared',
				'prefix' => 'ngobrolyuk.com',
			),
			array(
				array( 'GET', '#/files/0AFk2uZx9shared\?#', 200, array( 'id' => '0AFk2uZx9shared', 'name' => 'website-client', 'mimeType' => self::FOLDER, 'driveId' => '0ADRIVE', 'capabilities' => array( 'canAddChildren' => true ) ) ),
				array( 'GET', "#/files\?.*q='0AFk2uZx9shared' in parents.*name = 'ngobrolyuk\.com'#", 200, array( 'files' => array( array( 'id' => 'SUB1' ) ) ) ),
				array( 'GET', '#/files/SUB1\?.*fields=id,trashed,driveId#', 200, array( 'id' => 'SUB1', 'driveId' => '0ADRIVE' ) ),
				array( 'GET', "#/files\?.*q='SUB1' in parents#", 200, array( 'files' => array( array( 'id' => 'B1', 'name' => 'ngobrolyuk.com-20261009-020000-a1b2c3.fmw', 'size' => '10', 'modifiedTime' => '2026-10-09T02:00:00Z' ), array( 'id' => 'N1', 'name' => 'notes.txt', 'size' => '1', 'modifiedTime' => '2026-10-01T00:00:00Z' ) ) ) ),
				array( 'POST', '#/upload/drive/v3/files\?uploadType=resumable#', 200, array(), array( 'location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=U1' ) ),
				array( 'DELETE', '#/files/B1\?#', 403, array( 'error' => array( 'errors' => array( array( 'reason' => 'insufficientFilePermissions' ) ), 'message' => 'The user does not have sufficient permissions for this file.' ) ) ),
				array( 'PATCH', '#/files/B1\?#', 200, array( 'id' => 'B1' ) ),
				array( 'DELETE', '#/files/GONE\?#', 404, array( 'error' => array( 'message' => 'File not found: GONE.' ) ) ),
				array( 'DELETE', '#/files/RO\?#', 403, array( 'error' => array( 'errors' => array( array( 'reason' => 'insufficientFilePermissions' ) ), 'message' => 'No.' ) ) ),
				array( 'PATCH', '#/files/RO\?#', 403, array( 'error' => array( 'errors' => array( array( 'reason' => 'insufficientFilePermissions' ) ), 'message' => 'No.' ) ) ),
			)
		);

		$backups = $driver->backups();
		$this->assertSame( array( 'B1' ), array_column( $backups, 'key' ), 'Only backups, found in the existing subfolder.' );
		$this->assertSame(
			array(
				array(
					'folder_id'   => 'SUB1',
					'folder_path' => '0AFk2uZx9shared:ngobrolyuk.com',
					'parent_name' => 'website-client',
				),
			),
			$this->remembered
		);

		$state = $driver->upload_open( 'ngobrolyuk.com-20261010-020000-d4e5f6.fmw', 1000 );
		$this->assertSame( 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=U1', $state['session'] );
		$open = $this->requests[ count( $this->requests ) - 1 ];
		$this->assertSame( array( 'SUB1' ), json_decode( $open['body'], true )['parents'] );

		$driver->delete( 'B1' );
		$trash = $this->requests[ count( $this->requests ) - 1 ];
		$this->assertSame( 'PATCH', $trash['method'], 'A content manager cannot delete for good: the file goes to the trash.' );
		$this->assertSame( array( 'trashed' => true ), json_decode( $trash['body'], true ) );
		$driver->delete( 'GONE' );
		try {
			$driver->delete( 'RO' );
			$this->fail( 'A read-only file was "deleted".' );
		} catch ( RemoteException $e ) {
			$this->assertSame( 403, $e->status );
			$this->assertStringContainsString( 'Content manager', $e->getMessage() );
		}

		foreach ( $this->requests as $request ) {
			$this->assertStringContainsString( 'supportsAllDrives=true', $request['url'], $request['method'] . ' ' . $request['url'] );
			if ( 'GET' === $request['method'] && false !== strpos( $request['url'], ' in parents' ) ) {
				$this->assertStringContainsString( 'includeItemsFromAllDrives=true', $request['url'] );
				$this->assertStringContainsString( 'corpora=drive', $request['url'] );
				$this->assertStringContainsString( 'driveId=0ADRIVE', $request['url'] );
			}
		}
	}

	public function test_my_drive_still_starts_at_the_root(): void {
		$driver = $this->driver(
			array( 'prefix' => 'FMW Backups' ),
			array(
				array( 'GET', "#/files\?.*q='root' in parents.*name = 'FMW Backups'#", 200, array( 'files' => array() ) ),
				array( 'POST', '#/drive/v3/files\?fields=id#', 200, array( 'id' => 'NEW1' ) ),
				array( 'GET', "#/files\?.*q='NEW1' in parents#", 200, array( 'files' => array() ) ),
			)
		);
		$this->assertSame( array(), $driver->backups() );
		$this->assertSame( 'FMW Backups', $this->remembered[0]['folder_path'], 'Same remembered path as before, so saved folders stay valid.' );
		$this->assertSame( 'NEW1', $this->remembered[0]['folder_id'] );
		$this->assertSame( array( 'root' ), json_decode( $this->requests[1]['body'], true )['parents'] );
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( 'corpora=', $request['url'] );
		}
	}

	public function test_a_linked_folder_that_cannot_be_used_says_why(): void {
		$cases = array(
			'cannot find the linked folder' => array( 404, array( 'error' => array( 'message' => 'File not found: 1LinkedFolder.' ) ) ),
			'not a folder'                  => array( 200, array( 'id' => '1LinkedFolder', 'mimeType' => 'application/pdf' ) ),
			'in the trash'                  => array( 200, array( 'id' => '1LinkedFolder', 'mimeType' => self::FOLDER, 'trashed' => true ) ),
			'cannot add files'              => array( 200, array( 'id' => '1LinkedFolder', 'mimeType' => self::FOLDER, 'capabilities' => array( 'canAddChildren' => false ) ) ),
		);
		foreach ( $cases as $message => $answer ) {
			$driver = $this->driver(
				array(
					'parent'  => '1LinkedFolder',
					'prefix'  => '',
					'account' => 'oji@example.com',
				),
				array( array( 'GET', '#/files/1LinkedFolder\?#', $answer[0], $answer[1] ) )
			);
			try {
				$driver->backups();
				$this->fail( 'Used a folder that is ' . $message );
			} catch ( RemoteException $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}
		}
		$this->assertStringContainsString( 'oji@example.com', $this->driver_error( 404 ) );
	}

	/**
	 * Message for a linked folder Google answers with $status for.
	 *
	 * @param int $status HTTP status.
	 * @return string
	 */
	private function driver_error( int $status ): string {
		$driver = $this->driver(
			array(
				'parent'  => '1LinkedFolder',
				'prefix'  => '',
				'account' => 'oji@example.com',
			),
			array( array( 'GET', '#/files/1LinkedFolder\?#', $status, array( 'error' => array( 'message' => 'x' ) ) ) )
		);
		try {
			$driver->backups();
		} catch ( RemoteException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	/**
	 * A connected Drive driver that talks to a fake Google.
	 *
	 * Routes: [method, pattern on the decoded URL, status, JSON answer, headers]; the first match answers.
	 *
	 * @param array<string,mixed>    $storage Storage fields.
	 * @param array<int,array<mixed>> $routes  Routes.
	 * @return DriveDriver
	 */
	private function driver( array $storage, array $routes ): DriveDriver {
		$this->requests   = array();
		$this->remembered = array();
		$driver           = new DriveDriver(
			$storage + array(
				'id'          => 's1',
				'name'        => 'Drive',
				'provider'    => 'gdrive',
				'parent'      => '',
				'folder_id'   => '',
				'folder_path' => '',
				'access'      => Secrets::seal( (string) json_encode( array( 'token' => 'T', 'expires' => time() + 3600 ) ) ),
			)
		);
		$driver->set_sleep(
			static function (): void {
			}
		);
		$driver->set_transport(
			function ( string $url, string $method, array $headers, array $body = array( 'string' => '' ) ) use ( $routes ): array {
				$this->assertSame( 'Bearer T', $headers['authorization'] );
				$this->requests[] = array(
					'method' => $method,
					'url'    => rawurldecode( $url ),
					'body'   => (string) ( $body['string'] ?? '' ),
				);
				foreach ( $routes as $route ) {
					if ( $route[0] === $method && 1 === preg_match( $route[1], rawurldecode( $url ) ) ) {
						return array(
							'status'  => $route[2],
							'headers' => $route[4] ?? array(),
							'body'    => (string) json_encode( $route[3] ),
						);
					}
				}
				throw new \RuntimeException( 'Unexpected request: ' . $method . ' ' . rawurldecode( $url ) );
			},
			function ( array $fields ): void {
				$this->remembered[] = $fields;
			}
		);
		return $driver;
	}
}
