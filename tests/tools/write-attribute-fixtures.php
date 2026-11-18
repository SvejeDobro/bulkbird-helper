<?php
/**
 * Snima stvarni odgovor GET /terms/order za testni atribut u postojeći primjer u fixtures/v1/ i ispisuje
 * usporedbu s onim što je u primjeru bilo prije (status, zaglavlja, oblik, niz vrijednosti "order").
 *
 *   BBH_TARGET=NEW BBH_FIXTURE=terms-order-attribute-name.new php tests/tools/write-attribute-fixtures.php
 *   BBH_TARGET=NEW BBH_FIXTURE=terms-order-invalid-taxonomy-0.5.0 php tests/tools/write-attribute-fixtures.php
 *
 * Testni atribut i njegovu postavku prije toga priprema tests/tools/probe-attribute-order.php. Ovaj alat samo
 * čita (ključ samo za čitanje, K_READ, samo u zaglavlju). Polja "request", "attribute_order_by" i "note"
 * ostaju iz postojećeg primjera. ID-jevi vrijednosti su pri svakom snimanju novi, pa se ne uspoređuju.
 * Ništa tajno se ne ispisuje niti zapisuje.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

$target = strtoupper( (string) ( getenv( 'BBH_TARGET' ) ?: 'NEW' ) );
$name   = (string) getenv( 'BBH_FIXTURE' );
$file   = dirname( __DIR__, 2 ) . '/fixtures/v1/' . $name . '.json';
if ( ! preg_match( '/^terms-order-[a-z0-9.-]+$/', $name ) || ! is_file( $file ) ) {
	fwrite( STDERR, "BBH_FIXTURE mora biti naziv postojećeg primjera terms-order-…\n" );
	exit( 1 );
}
$before = json_decode( (string) file_get_contents( $file ), true );
$env    = array();
foreach ( (array) file( (string) getenv( 'HOME' ) . '/.config/bulkbird-helper/test.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	if ( preg_match( '/^([A-Z0-9_]+)=(.*)$/', (string) $line, $m ) ) {
		$env[ $m[1] ] = $m[2];
	}
}
$query   = (string) substr( (string) $before['request'], strlen( 'GET /bulkbird/v1/terms/order' ) );
$headers = array();
$ch      = curl_init( rtrim( $env[ "BBH_{$target}_URL" ], '/' ) . '/wp-json/bulkbird/v1/terms/order' . $query );
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
$body   = json_decode( (string) curl_exec( $ch ), true );
$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
curl_close( $ch );

$after           = $before;
$after['status'] = $status;
if ( isset( $before['captured'] ) ) {
	$after['captured'] = 'Snimljeno ' . gmdate( 'j. n. Y.' ) . ' stvarnim HTTP-om (plugin 0.5.0 iz oznake v0.5.0, ključ samo za čitanje); testni atribut bbh-test-atribut, nakon snimanja obrisan.';
}
if ( isset( $before['headers'] ) ) {
	$after['headers'] = array(
		'X-WP-Total'      => $headers['x-wp-total'] ?? null,
		'X-WP-TotalPages' => $headers['x-wp-totalpages'] ?? null,
	);
}
$after['body'] = $body;

$is_list = isset( $before['headers'] );
$orders  = static fn ( $rows ): array => is_array( $rows ) ? array_map( static fn ( $row ) => is_array( $row ) ? ( $row['order'] ?? null ) : '?', $rows ) : array();
$shape   = static fn ( $rows ): array => is_array( $rows ) ? array_values( array_unique( array_map( static fn ( $row ) => is_array( $row ) ? implode( ',', array_keys( $row ) ) . '|parent=' . json_encode( $row['parent'] ?? '?' ) : '?', $rows ) ) ) : array();
$same    = array(
	'status'  => $before['status'] === $status,
	'headers' => ( $before['headers'] ?? null ) === ( $after['headers'] ?? null ),
	'body'    => $is_list
		? ( $orders( $before['body'] ) === $orders( $body ) && $shape( $before['body'] ) === $shape( $body ) )
		: $before['body'] === $body,
);
if ( '' === (string) getenv( 'BBH_COMPARE_ONLY' ) ) {
	file_put_contents( $file, json_encode( $after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
}
echo sprintf(
	"%-58s status %d, total %s; prema prijašnjem primjeru: status %s, zaglavlja %s, tijelo %s (%s)\n",
	$name,
	$status,
	$headers['x-wp-total'] ?? '-',
	$same['status'] ? 'isti' : 'RAZLIKA',
	$same['headers'] ? 'ista' : 'RAZLIKA',
	$same['body'] ? 'isto' : 'RAZLIKA',
	$is_list ? 'niz order: ' . json_encode( $orders( $body ) ) : 'usporedba cijelog tijela'
);
exit( in_array( false, $same, true ) ? 2 : 0 );
