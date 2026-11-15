<?php
/**
 * Šalje zahtjeve za mjerenje (30 po stranici) s tajnim zaglavljem. Poziva ga bin/measure.sh run.
 *
 * Stranice za kupce se mjere dvaput: kao neprijavljen posjetitelj i (ako je BBH_MEASURE_AS_SHOPMGR=1)
 * s kolačićem testnog korisnika Shop manager, koji vidi stvarne stranice i kad je trgovina u
 * načinu "Coming soon".
 *
 * Ništa tajno se ne ispisuje.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

$target = strtoupper( (string) getenv( 'BBH_TARGET' ) );
$run    = (string) getenv( 'BBH_RUN' );
$count  = (int) ( getenv( 'BBH_MEASURE_COUNT' ) ?: 30 );
$env    = array();
foreach ( (array) file( (string) ( getenv( 'BBH_ENV_FILE' ) ?: getenv( 'HOME' ) . '/.config/bulkbird-helper/test.env' ), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	if ( preg_match( '/^([A-Z0-9_]+)=(.*)$/', (string) $line, $m ) ) {
		$env[ $m[1] ] = $m[2];
	}
}
$base   = rtrim( $env[ "BBH_{$target}_URL" ] ?? '', '/' );
$secret = $env['BBH_MEASURE_SECRET'] ?? '';
if ( '' === $base || '' === $secret || '' === $run ) {
	fwrite( STDERR, "Nedostaje URL stranice, tajna zaglavlja ili oznaka mjerenja.\n" );
	exit( 1 );
}

$product = (string) ( getenv( 'BBH_MEASURE_PRODUCT_PATH' ) ?: ( $env[ "BBH_{$target}_PRODUCT_PATH" ] ?? '' ) );
$pages   = array(
	'front'   => array( '/', false ),
	'shop'    => array( '/shop/', false ),
	'cart'    => array( '/cart/', false ),
	'wc-rest' => array( '/wp-json/wc/v3/products?per_page=1', true ),
);
if ( '' !== $product ) {
	$pages['product'] = array( $product, false );
}
if ( '1' === getenv( 'BBH_MEASURE_INFO' ) ) {
	$pages['bulkbird-info'] = array( '/wp-json/bulkbird/v1/info', true );
}
if ( '1' === getenv( 'BBH_MEASURE_MEDIA' ) ) {
	// H1.44, H1.45: najveća stranica popisa i pretraga po dijelu teksta (90 zahtjeva s ključem, ispod ograničenja od 120 u minuti).
	$pages['bulkbird-media-100']    = array( '/wp-json/bulkbird/v1/media?per_page=100', true );
	$pages['bulkbird-media-search'] = array( '/wp-json/bulkbird/v1/media?per_page=100&search=a', true );
}

$cookie = (string) getenv( 'BBH_MEASURE_COOKIE' );

foreach ( $pages as $name => $spec ) {
	list( $path, $with_key ) = $spec;
	$variants                = array( 'anon' => array() );
	if ( $with_key ) {
		$variants = array( 'key' => array() );
	} elseif ( '' !== $cookie ) {
		$variants['shopmgr'] = array( 'Cookie: ' . $cookie );
	}
	foreach ( $variants as $variant => $extra ) {
		$times = array();
		$codes = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$url = $base . $path . ( str_contains( $path, '?' ) ? '&' : '?' ) . 'bbhm=' . bin2hex( random_bytes( 4 ) );
			$ch  = curl_init( $url );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 60,
					CURLOPT_USERAGENT      => 'bulkbird-helper-measure',
					CURLOPT_HTTPHEADER     => array_merge( array( 'X-Bbh-Measure: ' . $secret, 'X-Bbh-Run: ' . $run . '.' . $name . '.' . $variant ), $extra ),
				)
			);
			if ( $with_key ) {
				curl_setopt( $ch, CURLOPT_USERPWD, ( $env[ "BBH_{$target}_K_READ_CK" ] ?? '' ) . ':' . ( $env[ "BBH_{$target}_K_READ_CS" ] ?? '' ) );
			}
			curl_exec( $ch );
			$codes[] = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$times[] = (float) curl_getinfo( $ch, CURLINFO_TOTAL_TIME ) * 1000;
			curl_close( $ch );
		}
		sort( $times );
		$median = $times[ intdiv( count( $times ), 2 ) ];
		printf( "%-28s %3d zahtjeva, statusi %s, kroz Cloudflare medijan %.0f ms\n", "$run.$name.$variant", $count, implode( ',', array_unique( $codes ) ), $median );
	}
}
