<?php
/**
 * Snima stvarne odgovore GET /terms/order u fixtures/v1/ (za testove na strani Bulkbirda).
 *
 *   BBH_TARGET=NEW php tests/tools/write-terms-order-fixtures.php
 *   BBH_TARGET=OLD php tests/tools/write-terms-order-fixtures.php
 *
 * Samo čita (ključ samo za čitanje, K_READ). Ključ ide samo u zaglavlju. Ništa tajno se ne ispisuje niti
 * zapisuje u primjere. Popisi se snimaju s per_page=10 da primjeri ostanu čitljivi.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

$target = strtoupper( (string) ( getenv( 'BBH_TARGET' ) ?: 'NEW' ) );
$suffix = 'NEW' === $target ? 'new' : 'old';
$env    = array();
foreach ( (array) file( (string) getenv( 'HOME' ) . '/.config/bulkbird-helper/test.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	if ( preg_match( '/^([A-Z0-9_]+)=(.*)$/', (string) $line, $m ) ) {
		$env[ $m[1] ] = $m[2];
	}
}
$base = rtrim( $env[ "BBH_{$target}_URL" ], '/' ) . '/wp-json/bulkbird/v1/terms/order';
$dir  = dirname( __DIR__, 2 ) . '/fixtures/v1';

$call = static function ( string $query ) use ( $env, $target, $base ): array {
	$headers = array();
	$ch      = curl_init( $base . $query );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_USERAGENT      => 'bulkbird-helper-fixtures',
			CURLOPT_USERPWD        => $env[ "BBH_{$target}_K_READ_CK" ] . ':' . $env[ "BBH_{$target}_K_READ_CS" ],
			CURLOPT_HEADERFUNCTION => static function ( $h, string $line ) use ( &$headers ): int {
				if ( false !== strpos( $line, ':' ) ) {
					list( $n, $v )                        = explode( ':', $line, 2 );
					$headers[ strtolower( trim( $n ) ) ] = trim( $v );
				}
				return strlen( $line );
			},
		)
	);
	$raw    = (string) curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	return array( 'status' => $status, 'headers' => $headers, 'json' => json_decode( $raw, true ) );
};

$save = static function ( string $name, string $query, array $response ) use ( $dir ): void {
	$out = array(
		'request' => 'GET /bulkbird/v1/terms/order' . $query,
		'status'  => $response['status'],
	);
	if ( isset( $response['headers']['x-wp-total'] ) ) {
		$out['headers'] = array(
			'X-WP-Total'      => $response['headers']['x-wp-total'],
			'X-WP-TotalPages' => $response['headers']['x-wp-totalpages'] ?? null,
		);
	}
	$out['body'] = $response['json'];
	file_put_contents( $dir . '/' . $name . '.json', json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	echo sprintf( "%-48s status %d, total: %s\n", $name, $response['status'], $response['headers']['x-wp-total'] ?? '-' );
};

$cases = array(
	"terms-order-product-cat.{$suffix}"   => '?taxonomy=product_cat&per_page=10',
	"terms-order-product-brand.{$suffix}" => '?taxonomy=product_brand&per_page=10',
);
if ( 'NEW' === $target ) {
	$cases['terms-order-invalid-taxonomy.new'] = '?taxonomy=category';
	$cases['terms-order-missing-taxonomy.new'] = '';
}
foreach ( $cases as $name => $query ) {
	$save( $name, $query, $call( $query ) );
}
