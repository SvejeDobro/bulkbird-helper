<?php
/**
 * Testni globalni atribut za mjerenje poretka vrijednosti atributa (docs/measurements/attribute-order.md),
 * kroz WooCommerce REST. Ključ ide samo u zaglavlju. Ništa tajno se ne ispisuje.
 *
 *   BBH_TARGET=NEW BBH_STEP=create php tests/tools/probe-attribute-order.php            # atribut, vrijednosti, skica proizvoda (K_RW)
 *   BBH_TARGET=NEW BBH_STEP=set BBH_ORDER_BY=name php tests/tools/probe-attribute-order.php   # mijenja postavku testnog atributa (K_RW)
 *   BBH_TARGET=NEW BBH_STEP=read php tests/tools/probe-attribute-order.php              # što REST kaže ključu samo za čitanje (K_READ)
 *
 * Atribut, vrijednosti i skica proizvoda zovu se "bbh-test-…"; nakon mjerenja pokrenuti
 * tests/tools/cleanup-test-attributes.php.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

const BBH_ATTRIBUTE = 'bbh-test-atribut';

$target = strtoupper( (string) ( getenv( 'BBH_TARGET' ) ?: 'NEW' ) );
$step   = (string) getenv( 'BBH_STEP' );
$env    = array();
foreach ( (array) file( (string) getenv( 'HOME' ) . '/.config/bulkbird-helper/test.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	if ( preg_match( '/^([A-Z0-9_]+)=(.*)$/', (string) $line, $m ) ) {
		$env[ $m[1] ] = $m[2];
	}
}
$base = rtrim( $env[ "BBH_{$target}_URL" ], '/' ) . '/wp-json/wc/v3/products';

$call = static function ( string $key, string $method, string $url, ?array $json = null ) use ( $env, $target ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_USERAGENT      => 'bulkbird-helper-probe',
			CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
			CURLOPT_USERPWD        => $env[ "BBH_{$target}_{$key}_CK" ] . ':' . $env[ "BBH_{$target}_{$key}_CS" ],
		)
	);
	if ( null !== $json ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $json ) );
	}
	$raw    = (string) curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	return array(
		'status' => $status,
		'json'   => json_decode( $raw, true ),
	);
};

// Testni atribut (po nazivu) među svim atributima trgovine.
$find = static function ( string $key ) use ( $call, $base ): ?array {
	foreach ( (array) $call( $key, 'GET', $base . '/attributes' )['json'] as $attribute ) {
		if ( is_array( $attribute ) && BBH_ATTRIBUTE === ( $attribute['name'] ?? '' ) ) {
			return $attribute;
		}
	}
	return null;
};
$short = static fn( array $r ): array => array(
	'status'     => $r['status'],
	'code'       => $r['json']['code'] ?? null,
	'id'         => $r['json']['id'] ?? null,
	'name'       => $r['json']['name'] ?? null,
	'slug'       => $r['json']['slug'] ?? null,
	'menu_order' => is_array( $r['json'] ) && array_key_exists( 'menu_order', $r['json'] ) ? $r['json']['menu_order'] : 'nema polja',
);

$out = array(
	'target' => $target,
	'step'   => $step,
);

if ( 'create' === $step ) {
	$attribute        = $call( 'K_RW', 'POST', $base . '/attributes', array( 'name' => BBH_ATTRIBUTE, 'slug' => 'pa_' . BBH_ATTRIBUTE, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ) );
	$out['attribute'] = array( 'status' => $attribute['status'], 'body' => $attribute['json'] );
	$id               = (int) ( $attribute['json']['id'] ?? 0 );
	if ( $id < 1 ) {
		echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		exit( 1 );
	}
	$names = array();
	foreach ( array(
		array( 'bbh-test-čaj', 2 ),
		array( 'bbh-test-Ćup', 2 ),      // Isti broj kao prethodni.
		array( 'bbh-test-cimet', null ), // menu_order se ne šalje.
		array( 'bbh-test-đon', null ),   // menu_order se ne šalje.
		array( 'bbh-test-dar', -1 ),
		array( 'bbh-test-9', 0 ),
		array( 'bbh-test-10', 5 ),
		array( 'bbh-test-Zebra', 1 ),
	) as $value ) {
		$body = array( 'name' => $value[0] );
		if ( null !== $value[1] ) {
			$body['menu_order'] = $value[1];
		}
		$out['terms'][] = array( 'sent_menu_order' => $value[1], 'result' => $short( $call( 'K_RW', 'POST', $base . "/attributes/{$id}/terms", $body ) ) );
		$names[]        = $value[0];
	}
	// Skica proizvoda koja nosi sve vrijednosti, da se vidi i poredak na proizvodu.
	$product        = $call(
		'K_RW',
		'POST',
		$base,
		array(
			'name'       => 'bbh-test-atribut-proizvod',
			'status'     => 'draft',
			'type'       => 'simple',
			'attributes' => array( array( 'id' => $id, 'visible' => true, 'options' => array_reverse( $names ) ) ),
		)
	);
	$out['product'] = array( 'status' => $product['status'], 'id' => $product['json']['id'] ?? null, 'post_status' => $product['json']['status'] ?? null );
} elseif ( 'set' === $step ) {
	$attribute  = $find( 'K_RW' );
	$updated    = $call( 'K_RW', 'PUT', $base . '/attributes/' . (int) ( $attribute['id'] ?? 0 ), array( 'order_by' => (string) getenv( 'BBH_ORDER_BY' ) ) );
	$out['set'] = array( 'status' => $updated['status'], 'body' => $updated['json'] );
} elseif ( 'read' === $step ) {
	$attribute        = $find( 'K_READ' );
	$out['list_item'] = $attribute;
	if ( $attribute ) {
		$single        = $call( 'K_READ', 'GET', $base . '/attributes/' . (int) $attribute['id'] );
		$out['single'] = array( 'status' => $single['status'], 'body' => $single['json'] );
		$terms         = $call( 'K_READ', 'GET', $base . '/attributes/' . (int) $attribute['id'] . '/terms?per_page=100' );
		$out['terms']  = array_map( static fn( $t ) => $t['name'] . ' (' . var_export( $t['menu_order'] ?? 'nema polja', true ) . ')', (array) $terms['json'] );
	}
} else {
	fwrite( STDERR, "BBH_STEP mora biti create, set ili read.\n" );
	exit( 1 );
}
echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
