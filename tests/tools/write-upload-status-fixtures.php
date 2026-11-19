<?php
/**
 * Snima stvarne odgovore GET /media/uploads/<client_upload_id> u fixtures/v1/ (za testove na strani Bulkbirda).
 *
 *   BBH_TARGET=NEW BBH_STAGE=1 php tests/tools/write-upload-status-fixtures.php   # prijenos testne slike + odgovori
 *   (testnu sliku premjestiti u smeće: tests/tools/trash-test-media.php kroz WP-CLI)
 *   BBH_TARGET=NEW BBH_STAGE=2 php tests/tools/write-upload-status-fixtures.php   # odgovor za sliku u smeću
 *   (zatim cleanup-test-media.php)
 *
 * Stvara jednu testnu sliku (naslov "bbh-test-stanje"). Ključ ide samo u zaglavlju. Ništa tajno se ne
 * ispisuje niti zapisuje u primjere. Stanje "in_progress" se ovako ne može snimiti (traži zahtjev koji traje).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

$target = strtoupper( (string) ( getenv( 'BBH_TARGET' ) ?: 'NEW' ) );
$stage  = (string) getenv( 'BBH_STAGE' );
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
			CURLOPT_USERAGENT      => 'bulkbird-helper-fixtures',
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

$save = static function ( string $name, array $response ) use ( $dir ): void {
	$out = array(
		'status' => $response['status'],
		'body'   => $response['json'],
	);
	file_put_contents( $dir . '/' . $name . '.json', str_replace( rtrim( $env[ "BBH_{$target}_URL" ], '/' ), 'https://example.com', (string) json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . "\n" ); // Adresa trgovine ne ulazi u primjere.
	echo sprintf( "%-40s status %d, retry-after: %s\n", $name, $response['status'], $response['headers']['retry-after'] ?? '-' );
};

$uuid    = '3f2b8c1e-9d4a-4b7e-8f1a-2c3d4e5f6a7b';
$unknown = '3f2b8c1e-9d4a-4b7e-8f1a-2c3d4e5f6a7c';
$status  = $base . '/uploads/';

if ( '1' === $stage ) {
	$im = imagecreatetruecolor( 640, 480 );
	mt_srand( 3 );
	for ( $i = 0; $i < 30; $i++ ) {
		imagefilledellipse( $im, mt_rand( 0, 640 ), mt_rand( 0, 480 ), mt_rand( 20, 200 ), mt_rand( 20, 200 ), imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
	}
	$file = (string) tempnam( sys_get_temp_dir(), 'bbhfx' );
	imagejpeg( $im, $file, 85 );

	$before  = $call( 'GET', $status . $uuid, 'K_READ' );
	$created = $call(
		'POST',
		$base,
		'K_RW',
		array(
			'client_upload_id' => $uuid,
			'title'            => 'bbh-test-stanje',
			'alt'              => 'Primjer alternativnog teksta',
			'file'             => new CURLFile( $file, 'image/jpeg', 'bbh-test-stanje.jpg' ),
		)
	);
	$stored  = $call( 'GET', $status . $uuid, 'K_READ' );
	echo 'prije prijenosa: ' . $before['status'] . ' ' . ( $before['json']['code'] ?? '' ) . '; prijenos: ' . $created['status'] . "\n";
	echo 'sha256 u odgovoru jednak sažetku datoteke: ' . ( hash_file( 'sha256', $file ) === ( $stored['json']['sha256'] ?? '' ) ? 'da' : 'NE' ) . "\n";
	echo 'item jednak odgovoru na POST: ' . ( ( $created['json'] ?? null ) === ( $stored['json']['item'] ?? false ) ? 'da' : 'NE' ) . "\n";

	$save( 'media-upload-status-stored', $stored );
	$save( 'media-upload-status-not-found', $call( 'GET', $status . $unknown, 'K_READ' ) );
	$save( 'media-upload-status-invalid-id', $call( 'GET', $status . 'abc', 'K_READ' ) );
	$save( 'media-upload-status-unauthorized', $call( 'GET', $status . $uuid, '' ) );
	$save( 'media-upload-status-forbidden', $call( 'GET', $status . $uuid, 'K_CUST' ) );
	echo 'ID testne slike: ' . ( $created['json']['id'] ?? '?' ) . "\n";
	@unlink( $file );
} elseif ( '2' === $stage ) {
	$save( 'media-upload-status-trashed', $call( 'GET', $status . $uuid, 'K_READ' ) );
} else {
	fwrite( STDERR, "BBH_STAGE mora biti 1 ili 2.\n" );
	exit( 1 );
}
