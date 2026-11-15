<?php
/**
 * Stvara testne korisnike i WooCommerce REST ključeve za Bulkbird Helper testove
 * i upisuje ključeve u env datoteku. NIŠTA TAJNO SE NE ISPISUJE.
 *
 * Samo za demo trgovine. Nikad na produkciji i nikad za stvarne korisnike.
 *
 * Pokretanje (primjer za teststore1):
 *   BBH_PREFIX=NEW ~/bin/wp83 eval-file tests/tools/provision-test-credentials.php
 *
 * Varijable okoline:
 *   BBH_PREFIX    obavezno, npr. NEW ili OLD (prefiks naziva u env datoteci)
 *   BBH_ENV_FILE  neobavezno; zadano ~/.config/bulkbird-helper/test.env.
 *                 Vrijednost "-" šalje env retke na standardni izlaz (za preusmjeravanje
 *                 u datoteku preko SSH-a); poruke o stanju tada idu na standardnu grešku.
 *
 * Skripta odbija rad ako korisnici ili ključevi s tim nazivima već postoje
 * (ništa ne briše i ništa ne prepisuje).
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	global $wpdb;

	$say = static function ( string $message ): void {
		fwrite( STDERR, $message . PHP_EOL );
	};
	$fail = static function ( string $message ) use ( $say ): void {
		$say( 'GREŠKA: ' . $message );
		exit( 1 );
	};

	$prefix = (string) getenv( 'BBH_PREFIX' );
	if ( 1 !== preg_match( '/^[A-Z][A-Z0-9]{1,15}$/', $prefix ) ) {
		$fail( 'BBH_PREFIX mora biti npr. NEW ili OLD.' );
	}
	if ( ! function_exists( 'wc_rand_hash' ) || ! function_exists( 'wc_api_hash' ) ) {
		$fail( 'WooCommerce nije učitan.' );
	}

	$env_file = (string) getenv( 'BBH_ENV_FILE' );
	if ( '' === $env_file ) {
		$env_file = rtrim( (string) getenv( 'HOME' ), '/' ) . '/.config/bulkbird-helper/test.env';
	}
	$to_stdout = ( '-' === $env_file );

	if ( ! $to_stdout && is_readable( $env_file ) ) {
		$existing = (string) file_get_contents( $env_file );
		if ( false !== strpos( $existing, 'BBH_' . $prefix . '_K_READ_CK=' ) ) {
			$fail( 'env datoteka već sadrži ključeve za prefiks ' . $prefix . '.' );
		}
	}

	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$users = array(
		'bulkbird-test-shopmgr'  => 'shop_manager',
		'bulkbird-test-customer' => 'customer',
	);
	$keys  = array(
		'K_READ'  => array( 'bulkbird-test-shopmgr', 'read' ),
		'K_RW'    => array( 'bulkbird-test-shopmgr', 'read_write' ),
		'K_WRITE' => array( 'bulkbird-test-shopmgr', 'write' ),
		'K_CUST'  => array( 'bulkbird-test-customer', 'read_write' ),
	);

	foreach ( array_keys( $users ) as $login ) {
		if ( username_exists( $login ) ) {
			$fail( 'korisnik ' . $login . ' već postoji.' );
		}
	}
	$table = $wpdb->prefix . 'woocommerce_api_keys';
	foreach ( array_keys( $keys ) as $name ) {
		$found = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE description = %s", 'bulkbird-test ' . $name ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $found > 0 ) {
			$fail( 'ključ "bulkbird-test ' . $name . '" već postoji.' );
		}
	}

	$user_ids = array();
	foreach ( $users as $login => $role ) {
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 40, true, true ),
				'user_email'   => $login . '@' . $host,
				'display_name' => $login,
				'role'         => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			$fail( 'stvaranje korisnika ' . $login . ' nije uspjelo: ' . $user_id->get_error_code() );
		}
		$user_ids[ $login ] = (int) $user_id;
	}

	$lines = array(
		'',
		'# ' . $prefix . ': ' . home_url() . ' (testni korisnici i ključevi; stvoreno ' . gmdate( 'Y-m-d H:i' ) . ' UTC)',
		'BBH_' . $prefix . '_URL=' . untrailingslashit( home_url() ),
		'BBH_' . $prefix . '_SHOPMGR_ID=' . $user_ids['bulkbird-test-shopmgr'],
		'BBH_' . $prefix . '_CUSTOMER_ID=' . $user_ids['bulkbird-test-customer'],
	);

	foreach ( $keys as $name => $spec ) {
		list( $login, $permissions ) = $spec;
		$consumer_key                = 'ck_' . wc_rand_hash();
		$consumer_secret             = 'cs_' . wc_rand_hash();
		$inserted                    = $wpdb->insert(
			$table,
			array(
				'user_id'         => $user_ids[ $login ],
				'description'     => 'bulkbird-test ' . $name,
				'permissions'     => $permissions,
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted ) {
			$fail( 'upis ključa ' . $name . ' nije uspio.' );
		}
		$lines[] = 'BBH_' . $prefix . '_' . $name . '_CK=' . $consumer_key;
		$lines[] = 'BBH_' . $prefix . '_' . $name . '_CS=' . $consumer_secret;
	}

	$payload = implode( PHP_EOL, $lines ) . PHP_EOL;

	if ( $to_stdout ) {
		fwrite( STDOUT, $payload );
	} else {
		$dir = dirname( $env_file );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		$is_new = ! file_exists( $env_file );
		if ( false === file_put_contents( $env_file, $payload, FILE_APPEND | LOCK_EX ) ) {
			$fail( 'upis u env datoteku nije uspio (ključevi su stvoreni u bazi — treba ih opozvati).' );
		}
		if ( $is_new ) {
			chmod( $env_file, 0600 );
		}
	}

	$say( 'Korisnici stvoreni: ' . implode( ', ', array_keys( $user_ids ) ) . ' (ID: ' . implode( ', ', $user_ids ) . ').' );
	$say( 'Ključevi stvoreni: ' . implode( ', ', array_keys( $keys ) ) . '.' );
	$say( $to_stdout ? 'Env retci poslani na standardni izlaz.' : 'Ključevi upisani u env datoteku (vrijednosti nisu ispisane).' );
} )();
