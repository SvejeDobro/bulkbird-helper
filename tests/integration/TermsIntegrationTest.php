<?php
/**
 * GET /terms/order unutar stvarnog WordPressa i WooCommercea (0.4.0; H2.90–H2.99).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class TermsIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore
		parent::tear_down();
	}

	private function supported(): bool {
		return defined( 'WC_VERSION' ) && ! is_multisite();
	}

	private function skip_unless_supported(): void {
		if ( ! $this->supported() ) {
			$this->markTestSkipped( 'Putanje ne postoje u ovoj okolini.' );
		}
	}

	private function as_role( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/** @param array<string, mixed> $params */
	private function order( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/bulkbird/v1/terms/order' );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Roditelj s osam podkategorija: dvije bez zapisa, negativan broj, 0, dvije s istim brojem,
	 * nazivi s č/ć/c, đ, znamenkama i velikim slovima.
	 *
	 * @return array{parent: int, children: array<string, int>}
	 */
	private function categories( string $taxonomy = 'product_cat' ): array {
		$parent   = wp_insert_term( 'Poredak ' . $taxonomy, $taxonomy );
		$children = array();
		foreach ( array(
			'čaj'   => 2,
			'Ćup'   => 2,
			'cimet' => null,
			'đon'   => null,
			'dar'   => -1,
			'9'     => 0,
			'10'    => 5,
			'Zebra' => 1,
		) as $name => $order ) {
			$term              = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent['term_id'] ) );
			$children[ $name ] = (int) $term['term_id'];
			delete_term_meta( $children[ $name ], 'order' );
			if ( null !== $order ) {
				update_term_meta( $children[ $name ], 'order', $order );
			}
		}
		return array(
			'parent'   => (int) $parent['term_id'],
			'children' => $children,
		);
	}

	public function test_h2_90_h2_99_discovery_and_absence_elsewhere(): void {
		$this->assertSame( $this->supported(), isset( rest_get_server()->get_routes()['/bulkbird/v1/terms/order'] ) );
		if ( $this->supported() ) {
			$this->as_role( 'shop_manager' );
			$info = rest_do_request( new WP_REST_Request( 'GET', '/bulkbird/v1/info' ) )->get_data();
			$this->assertContains( 'terms.order', $info['features'] );
			$this->assertSame( 1, $info['api_version'] );
		}
	}

	public function test_h2_91_h2_92_order_equals_the_shops_own_listing(): void {
		$this->skip_unless_supported();
		$set = $this->categories();
		$this->as_role( 'shop_manager' );

		$response = $this->order( array( 'taxonomy' => 'product_cat', 'per_page' => 1000 ) );
		$this->assertSame( 200, $response->get_status() );
		$items = $response->get_data();

		// Poredak je točno onaj koji daje zadani get_terms() trgovine (bez ikakvog argumenta poretka).
		$expected = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$this->assertSame( wp_list_pluck( $expected, 'term_id' ), wp_list_pluck( $items, 'id' ) );
		$this->assertSame( wp_list_pluck( $expected, 'parent' ), wp_list_pluck( $items, 'parent' ) );
		$this->assertSame( (string) count( $expected ), $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-TotalPages'] );

		// Podkategorije našeg roditelja, redom iz odgovora.
		$by_id    = array_flip( $set['children'] );
		$children = array_values( array_filter( $items, static fn( array $item ): bool => $item['parent'] === $set['parent'] ) );
		$names    = array_map( static fn( array $item ): string => (string) $by_id[ $item['id'] ], $children );
		$this->assertSame( array( null, null, -1, 0, 1, 2, 2, 5 ), wp_list_pluck( $children, 'order' ), 'bez zapisa prvi, zatim brojčano; redom: ' . implode( ', ', $names ) );
		$this->assertEqualsCanonicalizing( array( 'cimet', 'đon' ), array_slice( $names, 0, 2 ), 'bez zapisa: null, ne 0' );
		$this->assertSame( array( 'dar', '9', 'Zebra' ), array_slice( $names, 2, 3 ), 'negativan broj, zatim zapis 0, zatim 1' );
		$this->assertEqualsCanonicalizing( array( 'čaj', 'Ćup' ), array_slice( $names, 5, 2 ), 'isti broj' );
		$this->assertSame( '10', $names[7] );

		// Zapis 0 i "nema zapisa" se razlikuju, iako ih WooCommerce REST oba prikazuje kao 0.
		$orders = array_column( $items, 'order', 'id' );
		$this->assertSame( 0, $orders[ $set['children']['9'] ] );
		$this->assertNull( $orders[ $set['children']['cimet'] ] );
		$this->assertNull( $orders[ $set['parent'] ] );
		$request = new WP_REST_Request( 'GET', '/wc/v3/products/categories/' . $set['children']['cimet'] );
		$this->assertSame( 0, rest_do_request( $request )->get_data()['menu_order'], 'WooCommerce REST: 0 i kad zapisa nema' );
	}

	public function test_h2_97_items_carry_exactly_three_fields_and_match_the_fixture(): void {
		$this->skip_unless_supported();
		$this->categories();
		$this->as_role( 'shop_manager' );
		$response = $this->order( array( 'taxonomy' => 'product_cat' ) );
		$fixture  = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/v1/terms-order.json' ), true ); // phpcs:ignore

		$this->assertSame( $fixture['status'], $response->get_status() );
		$this->assertSame( array_keys( $fixture['headers'] ), array_values( array_intersect( array_keys( $fixture['headers'] ), array_keys( $response->get_headers() ) ) ) );
		foreach ( $response->get_data() as $item ) {
			$this->assertSame( array_keys( $fixture['body'][0] ), array_keys( $item ) );
			$this->assertIsInt( $item['id'] );
			$this->assertIsInt( $item['parent'] );
			$this->assertTrue( null === $item['order'] || is_int( $item['order'] ) );
		}
		$json = (string) wp_json_encode( $response->get_data() );
		foreach ( array( 'Poredak', 'cimet', 'Zebra', '"name"', '"slug"', '"description"', '"count"' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $json, 'odgovor ne sadrži nazive, opise ni broj proizvoda' );
		}
	}

	public function test_h2_93_paging(): void {
		$this->skip_unless_supported();
		$this->categories();
		$this->as_role( 'shop_manager' );
		$all = wp_list_pluck( $this->order( array( 'taxonomy' => 'product_cat', 'per_page' => 1000 ) )->get_data(), 'id' );
		$this->assertGreaterThanOrEqual( 9, count( $all ) );

		$paged = array();
		$pages = (int) ceil( count( $all ) / 4 );
		for ( $page = 1; $page <= $pages; $page++ ) {
			$response = $this->order( array( 'taxonomy' => 'product_cat', 'per_page' => 4, 'page' => $page ) );
			$this->assertLessThanOrEqual( 4, count( $response->get_data() ) );
			$this->assertSame( (string) count( $all ), $response->get_headers()['X-WP-Total'] );
			$this->assertSame( (string) $pages, $response->get_headers()['X-WP-TotalPages'] );
			$paged = array_merge( $paged, wp_list_pluck( $response->get_data(), 'id' ) );
		}
		$this->assertSame( $all, $paged, 'stranice zajedno daju isti popis istim redom, bez ponavljanja' );
		$this->assertSame( array(), $this->order( array( 'taxonomy' => 'product_cat', 'per_page' => 4, 'page' => $pages + 1 ) )->get_data() );

		foreach ( array( array( 'per_page' => 0 ), array( 'per_page' => 1001 ), array( 'per_page' => 'abc' ), array( 'page' => 0 ) ) as $bad ) {
			$this->assertSame( 400, $this->order( array( 'taxonomy' => 'product_cat' ) + $bad )->get_status(), (string) wp_json_encode( $bad ) );
		}
	}

	public function test_h2_94_taxonomies(): void {
		$this->skip_unless_supported();
		$this->as_role( 'shop_manager' );

		foreach ( array( 'product_tag', 'category', 'post_tag', 'pa_boja', 'nepostojeca', '' ) as $taxonomy ) {
			$response = $this->order( array( 'taxonomy' => $taxonomy ) );
			$this->assertSame( 400, $response->get_status(), $taxonomy );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'], $taxonomy );
		}
		$this->assertSame( 400, $this->order( array() )->get_status(), 'bez parametra taxonomy' );

		$response = $this->order( array( 'taxonomy' => 'product_brand' ) );
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			// WooCommerce bez brendova.
			$fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/v1/terms-order-taxonomy-not-found.json' ), true ); // phpcs:ignore
			$this->assertSame( $fixture['status'], $response->get_status() );
			$this->assertSame( $fixture['body'], json_decode( (string) wp_json_encode( rest_get_server()->response_to_data( $response, false ) ), true ) );
			return;
		}

		// WooCommerce s brendovima: zapis je isti, a poredak je onaj koji trgovina daje za brendove.
		$set      = $this->categories( 'product_brand' );
		$response = $this->order( array( 'taxonomy' => 'product_brand', 'per_page' => 1000 ) );
		$this->assertSame( 200, $response->get_status() );
		$expected = get_terms(
			array(
				'taxonomy'   => 'product_brand',
				'hide_empty' => false,
			)
		);
		$this->assertSame( wp_list_pluck( $expected, 'term_id' ), wp_list_pluck( $response->get_data(), 'id' ) );
		$orders = array_column( $response->get_data(), 'order', 'id' );
		$this->assertSame( -1, $orders[ $set['children']['dar'] ] );
		$this->assertSame( 0, $orders[ $set['children']['9'] ] );
		$this->assertNull( $orders[ $set['children']['đon'] ] );
	}

	public function test_h2_95_permissions(): void {
		$this->skip_unless_supported();
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->order( array( 'taxonomy' => 'product_cat' ) )->get_status() );
		$this->assertSame( 401, $this->order( array( 'taxonomy' => 'nepostojeca' ) )->get_status(), '401 prije 400' );
		foreach ( array( 'author', 'editor', 'customer', 'subscriber' ) as $role ) {
			$this->as_role( $role );
			$this->assertSame( 403, $this->order( array( 'taxonomy' => 'product_cat' ) )->get_status(), $role );
			$this->assertSame( 403, $this->order( array( 'taxonomy' => 'nepostojeca' ) )->get_status(), $role );
		}
		foreach ( array( 'shop_manager', 'administrator' ) as $role ) {
			$this->as_role( $role );
			$this->assertSame( 200, $this->order( array( 'taxonomy' => 'product_cat' ) )->get_status(), $role );
		}
		foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
			$request = new WP_REST_Request( $method, '/bulkbird/v1/terms/order' );
			$request->set_query_params( array( 'taxonomy' => 'product_cat' ) );
			$this->assertContains( rest_do_request( $request )->get_status(), array( 404, 405 ), $method );
		}
	}

	public function test_h2_96_h2_98_reads_only_and_counts_as_a_request(): void {
		$this->skip_unless_supported();
		global $wpdb;
		$set    = $this->categories();
		$user   = $this->as_role( 'shop_manager' );
		$before = array( wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ), get_term_meta( $set['children']['čaj'] ), metadata_exists( 'term', $set['children']['cimet'], 'order' ) );

		$this->order( array( 'taxonomy' => 'product_cat' ) ); // Prvi zahtjev smije stvoriti tablicu brojača.
		$queries = array();
		$record  = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 200, $this->order( array( 'taxonomy' => 'product_cat', 'per_page' => 1000 ) )->get_status() );
		}
		remove_filter( 'query', $record );

		$writes = preg_grep( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE)\b/i', $queries );
		$this->assertSame( array(), array_values( preg_grep( '/bulkbird_rate/', $writes, PREG_GREP_INVERT ) ), 'jedini upis je brojač učestalosti' );
		$this->assertSame( $before, array( wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ), get_term_meta( $set['children']['čaj'] ), metadata_exists( 'term', $set['children']['cimet'], 'order' ) ), 'pojam bez zapisa i dalje nema zapis' );

		// Broj upita ne ovisi o broju pojmova (nema upita po pojmu).
		$reads = count( preg_grep( '/^\s*SELECT\b/i', $queries ) ) / 3;
		$this->assertLessThanOrEqual( 12, $reads, 'upita po zahtjevu' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT hits, upload_hits FROM {$wpdb->prefix}bulkbird_rate WHERE user_id = %d", $user ), ARRAY_A ); // phpcs:ignore
		$this->assertSame( array( '4', '0' ), array( (string) $row['hits'], (string) $row['upload_hits'] ), 'broji se u zahtjeve, ne u prijenose' );
	}
}
