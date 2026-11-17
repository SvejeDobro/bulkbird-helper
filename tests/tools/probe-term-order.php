<?php
/**
 * Stvara testne pojmove za mjerenje poretka i jednakosti naziva (docs/measurements/term-order.md)
 * kroz WooCommerce REST, ključem Shop managera, i ispisuje odgovore kao JSON.
 *
 *   BBH_TARGET=NEW php tests/tools/probe-term-order.php > izlaz.json
 *
 * Svi pojmovi se zovu "bbh-test-…"; nakon mjerenja pokrenuti tests/tools/cleanup-test-terms.php.
 * Ključ ide samo u zaglavlju. Ništa tajno se ne ispisuje.
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
$base = rtrim( $env[ "BBH_{$target}_URL" ], '/' ) . '/wp-json/wc/v3/products/';

$call = static function ( string $method, string $url, ?array $json = null ) use ( $env, $target ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_USERAGENT      => 'bulkbird-helper-probe',
			CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
		)
	);
	curl_setopt( $ch, CURLOPT_USERPWD, $env[ "BBH_{$target}_K_RW_CK" ] . ':' . $env[ "BBH_{$target}_K_RW_CS" ] );
	if ( null !== $json ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $json ) );
	}
	$raw    = (string) curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	$json = json_decode( $raw, true );
	return array(
		'status'     => $status,
		'code'       => is_array( $json ) ? ( $json['code'] ?? null ) : null,
		'id'         => is_array( $json ) ? ( $json['id'] ?? null ) : null,
		'name'       => is_array( $json ) ? ( $json['name'] ?? null ) : null,
		'slug'       => is_array( $json ) ? ( $json['slug'] ?? null ) : null,
		'menu_order' => is_array( $json ) && array_key_exists( 'menu_order', $json ) ? $json['menu_order'] : 'nema polja',
		'resource_id' => is_array( $json ) ? ( $json['data']['resource_id'] ?? null ) : null,
	);
};

$out = array( 'target' => $target, 'duplicates' => array(), 'children' => array() );

// 1. Jednakost naziva: prvi pojam nastaje, drugi se razlikuje samo kvačicom ili veličinom slova.
foreach ( array( 'categories', 'tags' ) as $endpoint ) {
	foreach ( array(
		array( 'bbh-test-Čaj', 'bbh-test-Caj' ),
		array( 'bbh-test-Đak', 'bbh-test-Dak' ),
		array( 'bbh-test-šal', 'bbh-test-ŠAL' ),
		array( 'bbh-test-Kava', 'bbh-test-KAVA' ), // Razlika samo u veličini običnih (ASCII) slova.
	) as $pair ) {
		if ( '1' === getenv( 'BBH_ONLY_ASCII_PAIR' ) && 'bbh-test-Kava' !== $pair[0] ) {
			continue;
		}
		$first  = $call( 'POST', $base . $endpoint, array( 'name' => $pair[0] ) );
		$second = $call( 'POST', $base . $endpoint, array( 'name' => $pair[1] ) );
		$out['duplicates'][] = array( 'endpoint' => $endpoint, 'first' => $pair[0], 'first_result' => $first, 'second' => $pair[1], 'second_result' => $second );
	}
}

// 2. Poredak na jednoj razini.
if ( '1' === getenv( 'BBH_ONLY_ASCII_PAIR' ) ) {
	echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	exit( 0 );
}
$children = array(
	array( 'bbh-test-čaj', 2 ),
	array( 'bbh-test-Ćup', 2 ),      // Isti redoslijed kao prethodni.
	array( 'bbh-test-cimet', null ), // Bez zapisa redoslijeda (zapis se uklanja u drugom koraku).
	array( 'bbh-test-đon', null ),   // Bez zapisa redoslijeda.
	array( 'bbh-test-dar', -1 ),
	array( 'bbh-test-9', 0 ),
	array( 'bbh-test-10', 5 ),
	array( 'bbh-test-Zebra', 1 ),
	array( 'bbh-test-ananas', 3 ),
	array( 'bbh-test-Breskva', 4 ),
);
foreach ( array( 'categories', 'brands' ) as $endpoint ) {
	$parent = $call( 'POST', $base . $endpoint, array( 'name' => 'bbh-test-poredak' ) );
	$set    = array( 'endpoint' => $endpoint, 'parent' => $parent, 'items' => array() );
	if ( $parent['id'] ) {
		foreach ( $children as $child ) {
			$body = array( 'name' => $child[0], 'parent' => $parent['id'] );
			if ( null !== $child[1] ) {
				$body['menu_order'] = $child[1];
			}
			$set['items'][] = array( 'sent_name' => $child[0], 'sent_menu_order' => $child[1], 'result' => $call( 'POST', $base . $endpoint, $body ) );
		}
	}
	$out['children'][] = $set;
}
echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
