<?php
/**
 * Stvara kratkotrajnu (5 min) prijavu kolačićem za testnog korisnika i REST nonce,
 * za scenarije H0.11–H0.13. Ispis je JSON koji čita samo test (ne ispisivati u razgovor).
 *
 *   BBH_USER_ID=123 wp eval-file tests/tools/session.php
 *   BBH_ROLE=administrator wp eval-file tests/tools/session.php   # prvi korisnik s tom ulogom
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	$user_id = (int) getenv( 'BBH_USER_ID' );
	$role    = (string) getenv( 'BBH_ROLE' );
	if ( $user_id <= 0 && '' !== $role ) {
		$ids     = get_users(
			array(
				'role'    => $role,
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);
		$user_id = $ids ? (int) $ids[0] : 0;
	}
	if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
		fwrite( STDERR, "Nepoznat korisnik.\n" );
		exit( 1 );
	}

	$expiration = time() + 300;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	$cookie     = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );

	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	wp_set_current_user( $user_id );

	echo wp_json_encode(
		array(
			'cookie_name'  => LOGGED_IN_COOKIE,
			'cookie_value' => $cookie,
			'nonce'        => wp_create_nonce( 'wp_rest' ),
		)
	) . "\n";
} )();
