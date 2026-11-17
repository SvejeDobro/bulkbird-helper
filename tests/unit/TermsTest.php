<?php
/**
 * GET /terms/order (0.4.0; scenariji H2.90–H2.99).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

use Bulkbird\Helper\Terms_Controller;
use PHPUnit\Framework\TestCase;

final class TermsTest extends TestCase {

	protected function setUp(): void {
		BBH_State::reset();
	}

	/** @param array<string, mixed> $params */
	private function call( array $params ) {
		$request         = new WP_REST_Request( 'GET', '/bulkbird/v1/terms/order' );
		$request->params = $params;
		return ( new Terms_Controller() )->order( $request );
	}

	/** Deset pojmova u poretku kakav daje trgovina: bez zapisa, negativan, 0, isti broj, veći brojevi. */
	private function shop(): void {
		BBH_Terms_State::$terms['product_cat'] = array( 31 => 7, 32 => 7, 33 => 7, 34 => 7, 35 => 7, 36 => 7, 37 => 7, 7 => 0, 38 => 0, 39 => 38 );
		BBH_Terms_State::$meta                 = array(
			33 => array( 'order' => '-1', 'display_type' => '' ),
			34 => array( 'order' => '0' ),
			35 => array( 'order' => '2' ),
			36 => array( 'order' => '2' ),
			37 => array( 'order' => '10' ),
			31 => array( 'display_type' => '' ), // Ima druge zapise, ali ne redoslijed.
			38 => array( 'order' => '' ),         // Zapis postoji, ali nije broj.
			39 => array( 'order' => 'abc' ),
		);
	}

	public function test_h2_92_order_value_tells_no_record_from_zero(): void {
		self::assertNull( Terms_Controller::order_value( false, null ) );
		self::assertNull( Terms_Controller::order_value( false, '5' ), 'bez zapisa je null, što god bilo proslijeđeno' );
		self::assertSame( 0, Terms_Controller::order_value( true, '0' ) );
		self::assertSame( -3, Terms_Controller::order_value( true, '-3' ) );
		self::assertSame( 12, Terms_Controller::order_value( true, '12' ) );
		self::assertSame( 4, Terms_Controller::order_value( true, 4 ) );
		self::assertSame( 2, Terms_Controller::order_value( true, '2.9' ), 'cijeli broj' );
		// Zapis koji nije broj trgovina reda kao 0 (MySQL: vrijednost + 0).
		self::assertSame( 0, Terms_Controller::order_value( true, '' ) );
		self::assertSame( 0, Terms_Controller::order_value( true, 'abc' ) );
	}

	public function test_h2_91_h2_92_items_keep_the_shop_order_and_carry_only_three_fields(): void {
		$this->shop();
		$response = $this->call( array( 'taxonomy' => 'product_cat' ) );

		self::assertSame( 200, $response->status );
		self::assertSame(
			array(
				array( 'id' => 31, 'parent' => 7, 'order' => null ),
				array( 'id' => 32, 'parent' => 7, 'order' => null ),
				array( 'id' => 33, 'parent' => 7, 'order' => -1 ),
				array( 'id' => 34, 'parent' => 7, 'order' => 0 ),
				array( 'id' => 35, 'parent' => 7, 'order' => 2 ),
				array( 'id' => 36, 'parent' => 7, 'order' => 2 ),
				array( 'id' => 37, 'parent' => 7, 'order' => 10 ),
				array( 'id' => 7, 'parent' => 0, 'order' => null ),
				array( 'id' => 38, 'parent' => 0, 'order' => 0 ),
				array( 'id' => 39, 'parent' => 38, 'order' => 0 ),
			),
			$response->data,
			'redom kojim ih daje get_terms; bez zapisa null, zapis 0 je 0'
		);
		self::assertSame( '10', $response->headers['X-WP-Total'] );
		self::assertSame( '1', $response->headers['X-WP-TotalPages'] );
	}

	public function test_h2_91_the_listing_is_the_default_one_with_no_ordering_of_our_own(): void {
		$this->shop();
		$this->call( array( 'taxonomy' => 'product_cat' ) );

		self::assertCount( 1, BBH_Terms_State::$calls, 'jedan upit za popis' );
		self::assertSame(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'id=>parent',
			),
			BBH_Terms_State::$calls[0],
			'bez orderby, order, menu_order, number i offset: poredak određuje trgovina'
		);
		self::assertCount( 1, BBH_Terms_State::$primed, 'meta se učitava jednim upitom za cijelu stranicu, ne po pojmu' );
	}

	public function test_h2_93_paging(): void {
		$this->shop();
		$first = $this->call( array( 'taxonomy' => 'product_cat', 'per_page' => 4 ) );
		self::assertSame( array( 31, 32, 33, 34 ), array_column( $first->data, 'id' ) );
		self::assertSame( array( '10', '3' ), array( $first->headers['X-WP-Total'], $first->headers['X-WP-TotalPages'] ) );
		self::assertSame( array( 31, 32, 33, 34 ), BBH_Terms_State::$primed[0], 'meta samo za pojmove te stranice' );

		self::assertSame( array( 35, 36, 37, 7 ), array_column( $this->call( array( 'taxonomy' => 'product_cat', 'per_page' => 4, 'page' => 2 ) )->data, 'id' ) );
		self::assertSame( array( 38, 39 ), array_column( $this->call( array( 'taxonomy' => 'product_cat', 'per_page' => 4, 'page' => 3 ) )->data, 'id' ) );

		$beyond = $this->call( array( 'taxonomy' => 'product_cat', 'per_page' => 4, 'page' => 9 ) );
		self::assertSame( array(), $beyond->data, 'stranica iza zadnje: prazan popis' );
		self::assertSame( '10', $beyond->headers['X-WP-Total'] );

		// Bez per_page: 100; više od 1000 se ne prima.
		self::assertCount( 10, $this->call( array( 'taxonomy' => 'product_cat' ) )->data );
		self::assertSame( array( 1 => 'a', 2 => 'b' ), Terms_Controller::page( array( 1 => 'a', 2 => 'b', 3 => 'c' ), 1, 2 ) );
		self::assertSame( array( 3 => 'c' ), Terms_Controller::page( array( 1 => 'a', 2 => 'b', 3 => 'c' ), 2, 2 ), 'ključevi (ID-jevi) ostaju' );
		BBH_Terms_State::$terms['product_cat'] = array_fill( 1, 1500, 0 );
		self::assertCount( 1000, $this->call( array( 'taxonomy' => 'product_cat', 'per_page' => 5000 ) )->data );
	}

	public function test_h2_94_taxonomy_errors(): void {
		// Brendovi na trgovini bez brendova.
		$missing = $this->call( array( 'taxonomy' => 'product_brand' ) );
		self::assertSame( 'bulkbird_taxonomy_not_found', $missing->get_error_code() );
		self::assertSame( array( 'status' => 404 ), $missing->get_error_data() );
		self::assertSame( array(), BBH_Terms_State::$calls, 'ništa se ne čita' );

		// Druge taksonomije se ne otkrivaju, postojale ili ne.
		BBH_Terms_State::$taxonomies = array( 'product_cat', 'product_tag', 'category', 'pa_boja' );
		foreach ( array( 'product_tag', 'category', 'pa_boja', 'nepostojeca', '' ) as $taxonomy ) {
			$invalid = $this->call( array( 'taxonomy' => $taxonomy ) );
			self::assertSame( 'rest_invalid_param', $invalid->get_error_code(), $taxonomy );
			self::assertSame( array( 'status' => 400 ), $invalid->get_error_data() );
		}
		self::assertSame( array(), BBH_Terms_State::$calls );

		// Brendovi kad postoje.
		BBH_Terms_State::$taxonomies             = array( 'product_cat', 'product_brand' );
		BBH_Terms_State::$terms['product_brand'] = array( 5 => 0, 6 => 5 );
		BBH_Terms_State::$meta                   = array( 6 => array( 'order' => '3' ) );
		self::assertSame(
			array(
				array( 'id' => 5, 'parent' => 0, 'order' => null ),
				array( 'id' => 6, 'parent' => 5, 'order' => 3 ),
			),
			$this->call( array( 'taxonomy' => 'product_brand' ) )->data
		);
	}

	public function test_h2_95_route_is_read_only_and_needs_manage_woocommerce(): void {
		Bulkbird\Helper\register_rest_routes();
		list( , $route, $handlers ) = BBH_State::$routes[3];
		self::assertSame( '/terms/order', $route );
		self::assertCount( 1, $handlers, 'samo GET' );
		self::assertSame( 'GET', $handlers[0]['methods'] );
		self::assertSame( array( 'taxonomy', 'page', 'per_page' ), array_keys( $handlers[0]['args'] ) );
		self::assertTrue( $handlers[0]['args']['taxonomy']['required'] );
		self::assertSame( array( 'product_cat', 'product_brand' ), $handlers[0]['args']['taxonomy']['enum'] );
		self::assertSame( Terms_Controller::TAXONOMIES, $handlers[0]['args']['taxonomy']['enum'] );
		self::assertSame( array( 1, 1000, 100 ), array( $handlers[0]['args']['per_page']['minimum'], $handlers[0]['args']['per_page']['maximum'], $handlers[0]['args']['per_page']['default'] ) );

		$callback = $handlers[0]['permission_callback'];
		self::assertSame( 'bulkbird_unauthorized', call_user_func( $callback )->get_error_code() );
		BBH_State::$logged_in     = true;
		BBH_Media_State::$user_id = 7;
		BBH_State::$caps          = array( 'read', 'upload_files', 'edit_posts' );
		self::assertSame( 'bulkbird_forbidden', call_user_func( $callback )->get_error_code() );
		BBH_State::$caps = array( 'manage_woocommerce' );
		self::assertTrue( call_user_func( $callback, new WP_REST_Request( 'GET', '/bulkbird/v1/terms/order' ) ) );

		// H2.96: broji se kao zahtjev, ne kao prijenos.
		self::assertSame( 1, $GLOBALS['wpdb']->rows[7]['hits'] );
		self::assertSame( 0, $GLOBALS['wpdb']->rows[7]['upload_hits'] );
	}
}
