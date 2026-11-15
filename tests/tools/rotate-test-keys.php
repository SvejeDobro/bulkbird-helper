<?php
/**
 * Opoziva i ponovno stvara testne WooCommerce REST ključeve (odluka P11: ključ koji je dospio u log,
 * razgovor ili repozitorij se odmah zamjenjuje). Poziva ga tests/tools/rotate-test-keys.py.
 *
 *   BBH_ROTATE=K_READ,K_RW wp eval-file tests/tools/rotate-test-keys.php
 *
 * Dira samo ključeve s opisom "bulkbird-test <NAZIV>". Novi ključ dobiva istog korisnika, isti opis i
 * ista prava. Novi ključevi izlaze kao JSON na standardni izlaz, koji čita samo pozivna skripta;
 * ništa tajno ne ide na standardnu grešku ni u log.
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	global $wpdb;

	$fail = static function ( string $message ): void {
		fwrite( STDERR, 'GREŠKA: ' . $message . PHP_EOL );
		exit( 1 );
	};

	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! in_array( $host, array( 'teststore1.bulkbird.app', 'teststoreold.bulkbird.app' ), true ) ) {
		$fail( 'ova skripta radi samo na testnim stranicama.' );
	}
	if ( ! function_exists( 'wc_rand_hash' ) || ! function_exists( 'wc_api_hash' ) ) {
		$fail( 'WooCommerce nije učitan.' );
	}

	$names = array_filter( array_map( 'trim', explode( ',', (string) getenv( 'BBH_ROTATE' ) ) ) );
	foreach ( $names as $name ) {
		if ( ! in_array( $name, array( 'K_READ', 'K_RW', 'K_WRITE', 'K_CUST' ), true ) ) {
			$fail( 'nepoznat naziv ključa.' );
		}
	}
	if ( array() === $names ) {
		$fail( 'BBH_ROTATE je prazan.' );
	}

	$table = $wpdb->prefix . 'woocommerce_api_keys';
	$rows  = array();
	foreach ( $names as $name ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT key_id, user_id, permissions FROM {$table} WHERE description = %s", 'bulkbird-test ' . $name ), ARRAY_A );
		if ( 1 !== count( $found ) ) {
			$fail( 'očekivan je točno jedan ključ "bulkbird-test ' . $name . '", nađeno: ' . count( $found ) . '. Ništa nije mijenjano.' );
		}
		$rows[ $name ] = $found[0];
	}

	$out = array();
	foreach ( $rows as $name => $row ) {
		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();
		$inserted        = $wpdb->insert(
			$table,
			array(
				'user_id'         => (int) $row['user_id'],
				'description'     => 'bulkbird-test ' . $name,
				'permissions'     => (string) $row['permissions'],
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted ) {
			$fail( 'upis novog ključa ' . $name . ' nije uspio; stari nije opozvan.' );
		}
		$deleted = $wpdb->delete( $table, array( 'key_id' => (int) $row['key_id'] ), array( '%d' ) );
		if ( 1 !== $deleted ) {
			$fail( 'opoziv starog ključa ' . $name . ' nije uspio (novi je stvoren).' );
		}
		$out[ $name ] = array(
			'ck'          => $consumer_key,
			'cs'          => $consumer_secret,
			'permissions' => (string) $row['permissions'],
			'old_key_id'  => (int) $row['key_id'],
			'new_key_id'  => (int) $wpdb->insert_id,
		);
	}

	fwrite( STDOUT, 'BBHJSON' . wp_json_encode( $out ) . PHP_EOL );
} )();
