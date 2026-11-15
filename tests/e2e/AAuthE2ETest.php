<?php
/**
 * Poglavlje 1 scenarija: autentikacija istim WooCommerce ključem (H0.1–H0.20, H0.91).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class AAuthE2ETest extends E2ECase {

	private const INFO = '/wp-json/bulkbird/v1/info';

	public static function setUpBeforeClass(): void {
		// Stanje logova prije svih testova (za H0.20 na kraju).
		self::remember( 'log_lines_before', self::suspicious_log_lines() );
	}

	private static function assert_error( array $response, int $status, string $code ): void {
		self::assertSame( $status, $response['status'], self::describe( $response ) );
		self::assertIsArray( $response['json'], 'odgovor greške mora biti JSON' );
		self::assertSame( $code, $response['json']['code'] ?? null, self::describe( $response ) );
		// H0.17: WordPress oblik greške i ništa više.
		self::assertSame( array( 'code', 'message', 'data' ), array_keys( $response['json'] ) );
		self::assertSame( array( 'status' => $status ), $response['json']['data'] );
		self::assertDoesNotMatchRegularExpression( '#/home/|\.php|Stack trace|wp-content#i', $response['body'], 'greška ne smije otkriti putanje ni trag stoga' );
	}

	public function test_h0_1_read_key_in_basic_header(): void {
		$response = self::http( 'GET', self::url( self::INFO ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assertSame( 200, $response['status'], self::describe( $response ) );
		self::assertIsArray( $response['json'] );
		self::assertArrayHasKey( 'plugin_version', $response['json'] );
		self::remember( 'info_h0_1', $response['json'] );
	}

	public function test_h0_2_read_write_key(): void {
		$response = self::http( 'GET', self::url( self::INFO ), array( 'basic' => self::key( 'K_RW' ) ) );
		self::assertSame( 200, $response['status'], self::describe( $response ) );
	}

	/** @depends test_h0_1_read_key_in_basic_header */
	public function test_h0_3_key_in_query_string(): void {
		list( $ck, $cs ) = self::key( 'K_READ' );
		$response        = self::http( 'GET', self::url( self::INFO ) . '?consumer_key=' . rawurlencode( $ck ) . '&consumer_secret=' . rawurlencode( $cs ) );
		self::assertSame( 200, $response['status'], self::describe( $response ) );
		self::assertSame( self::remember( 'info_h0_1' ), $response['json'], 'isti odgovor kao s ključem u zaglavlju' );
	}

	public function test_h0_4_write_only_key_cannot_read(): void {
		$response = self::http( 'GET', self::url( self::INFO ), array( 'basic' => self::key( 'K_WRITE' ) ) );
		self::assert_error( $response, 401, 'woocommerce_rest_authentication_error' );
	}

	public function test_h0_5_valid_key_wrong_secret(): void {
		list( $ck ) = self::key( 'K_READ' );
		$response   = self::http( 'GET', self::url( self::INFO ), array( 'basic' => array( $ck, 'cs_' . str_repeat( '0', 40 ) ) ) );
		self::assert_error( $response, 401, 'woocommerce_rest_authentication_error' );
	}

	public function test_h0_6_unknown_key(): void {
		$response = self::http( 'GET', self::url( self::INFO ), array( 'basic' => array( 'ck_' . str_repeat( 'a', 40 ), 'cs_' . str_repeat( 'b', 40 ) ) ) );
		self::assert_error( $response, 401, 'bulkbird_unauthorized' );
		self::assertStringNotContainsString( 'plugin_version', $response['body'] );
	}

	public function test_h0_7_no_credentials(): void {
		$response = self::http( 'GET', self::url( self::INFO ) );
		self::assert_error( $response, 401, 'bulkbird_unauthorized' );
		self::assertStringNotContainsString( 'plugin_version', $response['body'] );
	}

	public function test_h0_8_valid_key_of_user_without_capability(): void {
		$response = self::http( 'GET', self::url( self::INFO ), array( 'basic' => self::key( 'K_CUST' ) ) );
		self::assert_error( $response, 403, 'bulkbird_forbidden' );
		self::assertStringNotContainsString( 'plugin_version', $response['body'] );
	}

	public function test_h0_9_post_is_never_executed(): void {
		// Ključ samo za čitanje: WooCommerce odbija metodu POST prije nego što WordPress traži putanju.
		$read_only = self::http( 'POST', self::url( self::INFO ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assert_error( $read_only, 401, 'woocommerce_rest_authentication_error' );

		// Ključ za pisanje: putanja nema metodu POST.
		$read_write = self::http( 'POST', self::url( self::INFO ), array( 'basic' => self::key( 'K_RW' ) ) );
		self::assertContains( $read_write['status'], array( 404, 405 ), self::describe( $read_write ) );
		self::assertStringNotContainsString( 'plugin_version', $read_write['body'] );
	}

	public function test_h0_10_unknown_route_in_own_namespace(): void {
		$response = self::http( 'GET', self::url( '/wp-json/bulkbird/v1/nepostoji' ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assert_error( $response, 404, 'rest_no_route' );
	}

	public function test_h0_11_cookie_and_nonce_shop_manager(): void {
		$session  = self::session( array( 'BBH_USER_ID' => self::cfg( 'SHOPMGR_ID' ) ) );
		$response = self::http( 'GET', self::url( self::INFO ), array( 'headers' => array( 'Cookie: ' . $session['cookie'], 'X-WP-Nonce: ' . $session['nonce'] ) ) );
		self::assertSame( 200, $response['status'], self::describe( $response ) );
		self::assertArrayHasKey( 'plugin_version', (array) $response['json'] );
	}

	public function test_h0_11_cookie_and_nonce_administrator_additional_check(): void {
		$session  = self::session( array( 'BBH_ROLE' => 'administrator' ) );
		$response = self::http( 'GET', self::url( self::INFO ), array( 'headers' => array( 'Cookie: ' . $session['cookie'], 'X-WP-Nonce: ' . $session['nonce'] ) ) );
		self::assertSame( 200, $response['status'], self::describe( $response ) );
	}

	public function test_h0_12_cookie_without_nonce(): void {
		$session  = self::session( array( 'BBH_USER_ID' => self::cfg( 'SHOPMGR_ID' ) ) );
		$response = self::http( 'GET', self::url( self::INFO ), array( 'headers' => array( 'Cookie: ' . $session['cookie'] ) ) );
		self::assert_error( $response, 401, 'bulkbird_unauthorized' );
	}

	public function test_h0_13_customer_cookie_and_nonce(): void {
		$session  = self::session( array( 'BBH_USER_ID' => self::cfg( 'CUSTOMER_ID' ) ) );
		$response = self::http( 'GET', self::url( self::INFO ), array( 'headers' => array( 'Cookie: ' . $session['cookie'], 'X-WP-Nonce: ' . $session['nonce'] ) ) );
		self::assert_error( $response, 403, 'bulkbird_forbidden' );
	}

	public function test_h0_14_key_is_not_extended_to_foreign_routes(): void {
		// Bez plugina: WooCommerce ključ ne vrijedi na /wp/v2/users/me → 401 rest_not_logged_in.
		$direct = self::http( 'GET', self::url( '/wp-json/wp/v2/users/me' ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assert_error( $direct, 401, 'rest_not_logged_in' );

		// Adresa imenuje našu putanju, ali WordPress izvršava onu iz rest_route.
		$mixed = self::http( 'GET', self::url( self::INFO . '?rest_route=/wp/v2/users/me' ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assertNotSame( 200, $mixed['status'], 'ključ se ne smije proširiti na /wp/v2/users/me' );
		self::assertStringNotContainsString( '"capabilities"', $mixed['body'] );
		self::assertStringNotContainsString( '"slug"', $mixed['body'] );

		// Isto, s rest_route u tijelu POST zahtjeva (ključ s pravom pisanja).
		$posted = self::http(
			'POST',
			self::url( self::INFO ),
			array(
				'basic' => self::key( 'K_RW' ),
				'body'  => array( 'rest_route' => '/wp/v2/users/me' ),
			)
		);
		self::assertNotSame( 200, $posted['status'], 'ključ se ne smije proširiti kroz rest_route u tijelu' );
		self::assertStringNotContainsString( '"capabilities"', $posted['body'] );
	}

	public function test_h0_15_urls_that_only_contain_the_word(): void {
		// context=edit traži prijavu: ako se ključ ne primijeni, WordPress odbija.
		$search = self::http( 'GET', self::url( '/wp-json/wp/v2/posts?context=edit&search=bulkbird/v1' ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assertSame( 401, $search['status'], self::describe( $search ) );

		$prefixed = self::http( 'GET', self::url( '/wp-json/xbulkbird/v1/info' ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assert_error( $prefixed, 404, 'rest_no_route' );

		$nested = self::http( 'GET', self::url( '/wp-json/wp/v2/bulkbird/v1/info' ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assert_error( $nested, 404, 'rest_no_route' );
	}

	/** @depends test_h0_1_read_key_in_basic_header */
	public function test_h0_16_rest_route_form_of_the_url(): void {
		$response = self::http( 'GET', self::url( '/?rest_route=/bulkbird/v1/info' ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assertSame( 200, $response['status'], self::describe( $response ) . ' — ako ovo padne samo na jednoj verziji WooCommercea: dokumentirano ograničenje (odluka P1)' );
		self::assertSame( self::remember( 'info_h0_1' ), $response['json'] );
	}

	public function test_h0_18_success_response_is_not_cacheable_and_sets_no_cookie(): void {
		$first  = self::http( 'GET', self::url( self::INFO ), array( 'basic' => self::key( 'K_READ' ) ) );
		$second = self::http( 'GET', self::url( self::INFO ), array( 'basic' => self::key( 'K_READ' ) ) );
		foreach ( array( $first, $second ) as $response ) {
			self::assertSame( 200, $response['status'] );
			self::assertMatchesRegularExpression( '/no-store|no-cache/', $response['headers']['cache-control'] ?? '', 'nedostaje Cache-Control koji zabranjuje cache' );
			self::assertArrayNotHasKey( 'set-cookie', $response['headers'] );
			self::assertNotSame( 'HIT', strtoupper( $response['headers']['cf-cache-status'] ?? '' ) );
			self::assertSame( '0', $response['headers']['x-cache-age'] ?? '0', 'odgovor ne smije doći iz Varnish cachea' );
			self::assertSame( '0', $response['headers']['age'] ?? '0' );
		}
	}

	public function test_h0_18_error_responses_are_not_cacheable(): void {
		$response = self::http( 'GET', self::url( self::INFO ) );
		self::assertSame( 401, $response['status'] );
		self::assertMatchesRegularExpression( '/no-store|no-cache/', $response['headers']['cache-control'] ?? '' );
	}

	public function test_h0_91_shop_manager_read_key_is_the_real_bulkbird_case(): void {
		$caps = self::internal()['shopmgr_caps'] ?? array();
		self::assertTrue( $caps['manage_woocommerce'] ?? false, 'testni korisnik mora imati manage_woocommerce' );
		self::assertFalse( $caps['manage_options'] ?? true, 'testni korisnik ne smije biti administrator' );

		$response = self::http( 'GET', self::url( self::INFO ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assertSame( 200, $response['status'], self::describe( $response ) );
		self::assertCount( 14, (array) $response['json'], 'potpun odgovor: svih 14 polja' );
	}
}
