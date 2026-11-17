<?php
/**
 * GET /bulkbird/v1/terms/order stvarnim HTTP-om (0.4.0; H2.90–H2.99).
 * Čita postojeće kategorije trgovine; ništa ne stvara i ne mijenja.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class FTermsE2ETest extends E2ECase {

	private const ORDER = '/wp-json/bulkbird/v1/terms/order';

	public static function setUpBeforeClass(): void {
		if ( version_compare( (string) self::site()['plugin_version'], '0.4.0', '<' ) ) {
			self::markTestSkipped( 'Na stranici je plugin stariji od 0.4.0.' );
		}
	}

	/** @return array{status: int, headers: array<string, string>, body: string, json: mixed, seconds: float} */
	private static function order( string $query, string $key = 'K_READ' ): array {
		return self::http( 'GET', self::url( self::ORDER . $query ), array( 'basic' => self::key( $key ) ) );
	}

	public function test_h2_91_h2_92_categories_in_the_shops_order(): void {
		$probe    = self::wpcli( 'tests/probe/terms.php', array( 'BBH_TAXONOMY' => 'product_cat' ) );
		$response = self::order( '?taxonomy=product_cat&per_page=1000' );
		self::assertSame( 200, $response['status'], self::describe( $response ) );
		self::assertSame( $probe['items'], $response['json'], 'isti pojmovi, isti redoslijed i isti zapisi kao zadani get_terms() trgovine' );
		self::assertSame( (string) count( $probe['items'] ), $response['headers']['x-wp-total'] ?? '' );
		self::assertMatchesRegularExpression( '/no-store|no-cache/', $response['headers']['cache-control'] ?? '' );
		foreach ( $response['json'] as $item ) {
			self::assertSame( array( 'id', 'parent', 'order' ), array_keys( $item ) );
		}
	}

	public function test_h2_93_paging(): void {
		$all   = array_column( self::order( '?taxonomy=product_cat&per_page=1000' )['json'], 'id' );
		$first = self::order( '?taxonomy=product_cat&per_page=7' );
		self::assertSame( array_slice( $all, 0, 7 ), array_column( $first['json'], 'id' ) );
		self::assertSame( (string) (int) ceil( count( $all ) / 7 ), $first['headers']['x-wp-totalpages'] ?? '' );
		self::assertSame( array_slice( $all, 7, 7 ), array_column( self::order( '?taxonomy=product_cat&per_page=7&page=2' )['json'], 'id' ) );
		self::assertSame( array(), self::order( '?taxonomy=product_cat&per_page=7&page=9999' )['json'] );
		self::assertSame( 'rest_invalid_param', self::order( '?taxonomy=product_cat&per_page=1001' )['json']['code'] );
	}

	public function test_h2_94_brands_and_other_taxonomies(): void {
		$probe  = self::wpcli( 'tests/probe/terms.php', array( 'BBH_TAXONOMY' => 'product_brand' ) );
		$brands = self::order( '?taxonomy=product_brand&per_page=1000' );
		if ( $probe['exists'] ) {
			self::assertSame( 200, $brands['status'], self::describe( $brands ) );
			self::assertSame( $probe['items'], $brands['json'] );
		} else {
			self::assertSame( 404, $brands['status'], self::describe( $brands ) );
			self::assertSame( 'bulkbird_taxonomy_not_found', $brands['json']['code'] );
		}
		foreach ( array( 'product_tag', 'category', 'nepostojeca' ) as $taxonomy ) {
			$response = self::order( '?taxonomy=' . $taxonomy );
			self::assertSame( 400, $response['status'], $taxonomy );
			self::assertSame( 'rest_invalid_param', $response['json']['code'] );
		}
		self::assertSame( 400, self::order( '' )['status'], 'bez parametra taxonomy' );
	}

	public function test_h2_95_permissions(): void {
		$anonymous = self::http( 'GET', self::url( self::ORDER . '?taxonomy=product_cat' ) );
		self::assertSame( 401, $anonymous['status'] );
		self::assertSame( 'bulkbird_unauthorized', $anonymous['json']['code'] );
		$customer = self::order( '?taxonomy=product_cat', 'K_CUST' );
		self::assertSame( 403, $customer['status'] );
		self::assertSame( 'bulkbird_forbidden', $customer['json']['code'] );
		self::assertSame( 401, self::http( 'GET', self::url( self::ORDER . '?taxonomy=nepostojeca' ) )['status'], '401 prije 400' );
	}
}
