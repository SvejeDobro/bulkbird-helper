<?php
/**
 * Opseg filtera za WooCommerce autentikaciju (H0.14, H0.15, H0.16, kvar H0.83).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use function Bulkbird\Helper\filter_is_request_to_rest_api;
use function Bulkbird\Helper\is_own_route;
use function Bulkbird\Helper\request_targets_own_route;

final class RoutingTest extends TestCase {

	protected function setUp(): void {
		BBH_State::reset();
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function uris(): array {
		return array(
			'H0.1 own route'                         => array( '/wp-json/bulkbird/v1/info', true ),
			'own route with query'                   => array( '/wp-json/bulkbird/v1/info?per_page=5', true ),
			'own namespace index'                    => array( '/wp-json/bulkbird/v1', true ),
			'own route via index.php'                => array( '/index.php/wp-json/bulkbird/v1/info', true ),
			'H0.14 foreign core route'               => array( '/wp-json/wp/v2/users/me', false ),
			'H0.14 woocommerce route (not ours)'     => array( '/wp-json/wc/v3/products', false ),
			'H0.15 word in query only'               => array( '/wp-json/wp/v2/posts?search=bulkbird/v1', false ),
			'H0.15 word in query with slashes'       => array( '/wp-json/wp/v2/posts?search=/bulkbird/v1/info', false ),
			'H0.15 prefixed namespace'               => array( '/wp-json/xbulkbird/v1/info', false ),
			'H0.15 suffixed namespace'               => array( '/wp-json/bulkbirdx/v1/info', false ),
			'H0.15 nested under foreign namespace'   => array( '/wp-json/wp/v2/bulkbird/v1/info', false ),
			'H0.15 not under the REST prefix'        => array( '/bulkbird/v1/info', false ),
			'H0.15 front-end page with the word'     => array( '/product/bulkbird/', false ),
			'front page'                             => array( '/', false ),
			'encoded namespace is not ours'          => array( '/wp-json/%62ulkbird/v1/info', false ),
			'double slash after prefix'              => array( '/wp-json//bulkbird/v1/info', true ),
		);
	}

	/** @dataProvider uris */
	public function test_request_uri_is_matched_strictly( string $uri, bool $expected ): void {
		$_SERVER['REQUEST_URI'] = $uri;
		self::assertSame( $expected, request_targets_own_route() );
	}

	public function test_h0_16_rest_route_query_parameter(): void {
		$_SERVER['REQUEST_URI'] = '/?rest_route=/bulkbird/v1/info';
		$_GET['rest_route']     = '/bulkbird/v1/info';
		self::assertTrue( request_targets_own_route() );
	}

	public function test_rest_route_parameter_wins_over_path_like_wordpress_does(): void {
		// WordPress izvršava putanju iz rest_route, ne iz adrese: ključ se ne smije proširiti na tuđu putanju.
		$_SERVER['REQUEST_URI'] = '/wp-json/bulkbird/v1/info?rest_route=/wp/v2/users';
		$_GET['rest_route']     = '/wp/v2/users';
		self::assertFalse( request_targets_own_route() );
	}

	public function test_post_rest_route_wins_over_query_and_path(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/bulkbird/v1/info?rest_route=/bulkbird/v1/info';
		$_GET['rest_route']     = '/bulkbird/v1/info';
		$_POST['rest_route']    = '/wp/v2/users';
		self::assertFalse( request_targets_own_route() );
	}

	public function test_rest_route_array_is_rejected(): void {
		$_SERVER['REQUEST_URI'] = '/?rest_route[]=/bulkbird/v1/info';
		$_GET['rest_route']     = array( '/bulkbird/v1/info' );
		self::assertFalse( request_targets_own_route() );
	}

	public function test_site_in_subdirectory(): void {
		BBH_State::$home        = 'https://shop.example/store';
		$_SERVER['REQUEST_URI'] = '/store/wp-json/bulkbird/v1/info';
		self::assertTrue( request_targets_own_route() );

		$_SERVER['REQUEST_URI'] = '/other/wp-json/bulkbird/v1/info';
		self::assertFalse( request_targets_own_route() );

		$_SERVER['REQUEST_URI'] = '/storefront/wp-json/bulkbird/v1/info';
		self::assertFalse( request_targets_own_route() );
	}

	public function test_missing_request_uri(): void {
		self::assertFalse( request_targets_own_route() );
	}

	public function test_is_own_route(): void {
		self::assertTrue( is_own_route( '/bulkbird/v1/info' ) );
		self::assertTrue( is_own_route( 'bulkbird/v1' ) );
		self::assertFalse( is_own_route( '/bulkbirdx/v1' ) );
		self::assertFalse( is_own_route( '/wc/v3/bulkbird/' ) );
		self::assertFalse( is_own_route( '' ) );
	}

	public function test_filter_keeps_woocommerce_decision_and_adds_only_own_routes(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products';
		self::assertTrue( filter_is_request_to_rest_api( true ), 'WooCommerceova odluka se ne dira' );
		self::assertFalse( filter_is_request_to_rest_api( false ) );

		$_SERVER['REQUEST_URI'] = '/wp-json/bulkbird/v1/info';
		self::assertTrue( filter_is_request_to_rest_api( false ) );
	}

	public function test_h0_51_filter_is_inert_on_multisite(): void {
		BBH_State::$multisite   = true;
		$_SERVER['REQUEST_URI'] = '/wp-json/bulkbird/v1/info';
		self::assertFalse( filter_is_request_to_rest_api( false ) );
	}
}
