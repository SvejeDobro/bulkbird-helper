<?php
/**
 * Zapisuje primjere stvarnih odgovora /media u fixtures/v1/ (za testove na strani Bulkbirda)
 * i usput provjerava odluku P6 (mijenja li promjena alt teksta datum izmjene slike).
 *
 *   BBH_TARGET=NEW php tests/tools/write-media-fixtures.php
 *
 * Stvara dvije testne slike (naslov "bbh-test-…"); nakon toga pokrenuti cleanup-test-media.php.
 * Ništa tajno se ne ispisuje niti zapisuje u primjere.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

$target = strtoupper( (string) ( getenv( 'BBH_TARGET' ) ?: 'NEW' ) );
$env    = array();
foreach ( (array) file( (string) getenv( 'HOME' ) . '/.config/bulkbird-helper/test.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	if ( preg_match( '/^([A-Z0-9_]+)=(.*)$/', (string) $line, $m ) ) {
		$env[ $m[1] ] = $m[2];
	}
}
$base = rtrim( $env[ "BBH_{$target}_URL" ], '/' ) . '/wp-json/bulkbird/v1/media';
$dir  = dirname( __DIR__, 2 ) . '/fixtures/v1';

$call = static function ( string $method, string $url, string $key, $body = null ) use ( $env, $target ): array {
	$headers = array();
	$ch      = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_HEADERFUNCTION => static function ( $h, string $line ) use ( &$headers ): int {
				if ( false !== strpos( $line, ':' ) ) {
					list( $n, $v )                        = explode( ':', $line, 2 );
					$headers[ strtolower( trim( $n ) ) ] = trim( $v );
				}
				return strlen( $line );
			},
		)
	);
	if ( '' !== $key ) {
		curl_setopt( $ch, CURLOPT_USERPWD, $env[ "BBH_{$target}_{$key}_CK" ] . ':' . $env[ "BBH_{$target}_{$key}_CS" ] );
	}
	if ( null !== $body ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
	}
	$raw    = (string) curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	return array( 'status' => $status, 'headers' => $headers, 'json' => json_decode( $raw, true ) );
};

$image = static function ( int $seed ): string {
	$im = imagecreatetruecolor( 640, 480 );
	mt_srand( $seed );
	for ( $i = 0; $i < 30; $i++ ) {
		imagefilledellipse( $im, mt_rand( 0, 640 ), mt_rand( 0, 480 ), mt_rand( 20, 200 ), mt_rand( 20, 200 ), imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
	}
	$path = (string) tempnam( sys_get_temp_dir(), 'bbhfx' );
	imagejpeg( $im, $path, 85 );
	return $path;
};

$save = static function ( string $name, array $response, array $keep_headers = array() ) use ( $dir, $target ): void {
	$out = array( 'status' => $response['status'] );
	foreach ( $keep_headers as $h ) {
		if ( isset( $response['headers'][ $h ] ) ) {
			$out['headers'][ $h ] = $response['headers'][ $h ];
		}
	}
	$out['body'] = $response['json'];
	file_put_contents( $dir . '/' . $name . '.' . strtolower( $target ) . '.json', json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	echo sprintf( "%-34s status %d\n", $name, $response['status'] );
};

$uuid  = '0b7e0a5e-6f1c-4c2e-9a53-5d1f6f0a9c01';
$uuid2 = '0b7e0a5e-6f1c-4c2e-9a53-5d1f6f0a9c02';
$file  = $image( 1 );
$other = $image( 2 );
$form  = static fn( string $path, string $id ): array => array(
	'client_upload_id' => $id,
	'title'            => 'bbh-test-primjer',
	'alt'              => 'Primjer alternativnog teksta',
	'file'             => new CURLFile( $path, 'image/jpeg', 'bbh-test-primjer.jpg' ),
);

$created = $call( 'POST', $base, 'K_RW', $form( $file, $uuid ) );
$save( 'media-item-created', $created );
$save( 'media-item-existing', $call( 'POST', $base, 'K_RW', $form( $file, $uuid ) ) );
$save( 'media-error-upload-id-conflict', $call( 'POST', $base, 'K_RW', $form( $other, $uuid ) ) );
$save( 'media-error-unsupported-type', $call( 'POST', $base, 'K_RW', array( 'client_upload_id' => $uuid2, 'title' => 'bbh-test-primjer', 'file' => new CURLFile( __FILE__, 'image/jpeg', 'bbh-test-primjer.jpg' ) ) ) );
$save( 'media-error-missing-file', $call( 'POST', $base, 'K_RW', array( 'client_upload_id' => $uuid2 ) ) );
$save( 'media-error-invalid-param', $call( 'GET', $base . '?per_page=101', 'K_READ' ) );
$save( 'media-error-unauthorized', $call( 'GET', $base, '' ) );
$save( 'media-error-forbidden', $call( 'GET', $base, 'K_CUST' ) );
$save( 'media-error-read-only-key', $call( 'POST', $base, 'K_READ', array( 'client_upload_id' => $uuid2 ) ) );
$save( 'media-list', $call( 'GET', $base . '?per_page=2&search=' . rawurlencode( 'bbh-test-primjer' ), 'K_READ' ), array( 'x-wp-total', 'x-wp-totalpages' ) );
$save( 'media-list-empty', $call( 'GET', $base . '?page=9999', 'K_READ' ), array( 'x-wp-total', 'x-wp-totalpages' ) );

echo 'ID testne slike: ' . ( $created['json']['id'] ?? '?' ) . ', modified_gmt: ' . ( $created['json']['modified_gmt'] ?? '?' ) . "\n";
@unlink( $file );
@unlink( $other );
