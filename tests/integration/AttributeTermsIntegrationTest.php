<?php
/**
 * GET /terms/order za vrijednosti globalnih atributa (pa_*) unutar stvarnog WordPressa i WooCommercea
 * (0.5.0; H2.100–H2.105).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class AttributeTermsIntegrationTest extends WP_UnitTestCase {

	/** @var string[] Taksonomije koje je test registrirao. */
	private array $registered = array();

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server, $wc_product_attributes;
		foreach ( $this->registered as $taxonomy ) {
			unregister_taxonomy( $taxonomy );
			unset( $wc_product_attributes[ $taxonomy ] );
		}
		$this->registered = array();
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			delete_transient( 'wc_attribute_taxonomies' );
			WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		}
		$wp_rest_server = null; // phpcs:ignore
		parent::tear_down();
	}

	private function skip_unless_supported(): void {
		if ( ! defined( 'WC_VERSION' ) || is_multisite() ) {
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
	 * Globalni atribut sa zadanom postavkom poretka, registriran onako kako ga WooCommerce registrira
	 * pri pokretanju, s vrijednostima (naziv => zapis redoslijeda ili null).
	 *
	 * @param array<int|string, int|null> $values
	 * @return array{id: int, taxonomy: string, terms: array<string, int>}
	 */
	private function attribute( string $slug, string $order_by, array $values ): array {
		global $wc_product_attributes;
		$id = wc_create_attribute(
			array(
				'name'     => 'Atribut ' . $slug,
				'slug'     => $slug,
				'type'     => 'select',
				'order_by' => $order_by,
			)
		);
		$this->assertIsInt( $id, 'atribut je stvoren' );
		$taxonomy = wc_attribute_taxonomy_name( $slug );
		foreach ( wc_get_attribute_taxonomies() as $row ) {
			if ( $slug === $row->attribute_name ) {
				$wc_product_attributes[ $taxonomy ] = $row; // phpcs:ignore
			}
		}
		register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false ) );
		$this->registered[] = $taxonomy;
		$this->assertTrue( taxonomy_is_product_attribute( $taxonomy ) );
		$this->assertSame( $order_by, wc_attribute_orderby( $taxonomy ) );

		$terms = array();
		foreach ( $values as $name => $order ) {
			$term                   = wp_insert_term( (string) $name, $taxonomy );
			$terms[ (string) $name ] = (int) $term['term_id'];
			delete_term_meta( $terms[ (string) $name ], 'order' );
			if ( null !== $order ) {
				update_term_meta( $terms[ (string) $name ], 'order', $order );
			}
		}
		return array(
			'id'       => $id,
			'taxonomy' => $taxonomy,
			'terms'    => $terms,
		);
	}

	/** Vrijednosti kao u mjerenju: sa zapisom, bez njega, s 0, s negativnim brojem, s hrvatskim slovima. */
	private const VALUES = array(
		'čaj'   => 2,
		'Ćup'   => 2,
		'cimet' => null,
		'đon'   => null,
		'dar'   => -1,
		'9'     => 0,
		'10'    => 5,
		'Zebra' => 1,
	);

	/**
	 * Nazivi iz odgovora, redom, i provjera da je odgovor jednak zadanom popisu trgovine.
	 *
	 * @param array{id: int, taxonomy: string, terms: array<string, int>} $set
	 * @return string[]
	 */
	private function names_in_response_order( array $set ): array {
		$response = $this->order( array( 'taxonomy' => $set['taxonomy'], 'per_page' => 1000 ) );
		$this->assertSame( 200, $response->get_status() );
		$expected = get_terms(
			array(
				'taxonomy'   => $set['taxonomy'],
				'hide_empty' => false,
			)
		);
		$this->assertSame( wp_list_pluck( $expected, 'term_id' ), wp_list_pluck( $response->get_data(), 'id' ), 'isti redoslijed kao zadani get_terms() trgovine' );
		$this->assertSame( (string) count( $set['terms'] ), $response->get_headers()['X-WP-Total'] );
		$by_id = array_flip( $set['terms'] );
		foreach ( $response->get_data() as $item ) {
			$this->assertSame( array( 'id', 'parent', 'order' ), array_keys( $item ) );
			$this->assertSame( 0, $item['parent'] );
			$name = (string) $by_id[ $item['id'] ];
			$this->assertSame( self::VALUES[ $name ] ?? null, $item['order'], 'zapis redoslijeda se vraća uz svaku postavku: ' . $name );
		}
		return array_map( static fn( array $item ): string => (string) $by_id[ $item['id'] ], $response->get_data() );
	}

	public function test_h2_100_info_names_the_feature(): void {
		$this->skip_unless_supported();
		$this->as_role( 'shop_manager' );
		$info = rest_do_request( new WP_REST_Request( 'GET', '/bulkbird/v1/info' ) )->get_data();
		$this->assertContains( 'terms.order', $info['features'] );
		$this->assertContains( 'terms.order.attributes', $info['features'] );
		$this->assertSame( 1, $info['api_version'] );
	}

	public function test_h2_102_h2_103_custom_ordering(): void {
		$this->skip_unless_supported();
		$set = $this->attribute( 'bbh-mo', 'menu_order', self::VALUES );
		$this->as_role( 'shop_manager' );
		$names = $this->names_in_response_order( $set );

		$this->assertEqualsCanonicalizing( array( 'cimet', 'đon' ), array_slice( $names, 0, 2 ), 'bez zapisa prvi' );
		$this->assertSame( array( 'dar', '9', 'Zebra' ), array_slice( $names, 2, 3 ), 'negativan broj, zapis 0, zatim 1' );
		$this->assertEqualsCanonicalizing( array( 'čaj', 'Ćup' ), array_slice( $names, 5, 2 ), 'isti broj' );
		$this->assertSame( '10', $names[7] );

		$orders = array_column( $this->order( array( 'taxonomy' => $set['taxonomy'] ) )->get_data(), 'order', 'id' );
		$this->assertNull( $orders[ $set['terms']['cimet'] ], 'bez zapisa: null' );
		$this->assertSame( 0, $orders[ $set['terms']['9'] ], 'zapis 0: 0' );
		$this->assertSame( -1, $orders[ $set['terms']['dar'] ] );
	}

	public function test_h2_102_name(): void {
		$this->skip_unless_supported();
		$set = $this->attribute( 'bbh-name', 'name', self::VALUES );
		$this->as_role( 'shop_manager' );
		$names = $this->names_in_response_order( $set );

		$by_name = get_terms(
			array(
				'taxonomy'   => $set['taxonomy'],
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$this->assertSame( wp_list_pluck( $by_name, 'name' ), $names, 'po nazivu, kolacijom baze; zapis redoslijeda se ne koristi' );
		$this->assertSame( array( '10', '9' ), array_slice( $names, 0, 2 ), 'znamenke kao tekst' );
		$this->assertSame( 'Zebra', $names[7] );
	}

	public function test_h2_102_name_numeric(): void {
		$this->skip_unless_supported();
		$set = $this->attribute(
			'bbh-num',
			'name_num',
			array(
				'9'    => null,
				'10'   => null,
				'2.5'  => null,
				'1 kg' => null,
				'-3'   => null,
			)
		);
		$this->as_role( 'shop_manager' );
		$response = $this->order( array( 'taxonomy' => $set['taxonomy'] ) );
		$this->assertSame( 200, $response->get_status() );
		$by_id = array_flip( $set['terms'] );
		$this->assertSame(
			array( '-3', '1 kg', '2.5', '9', '10' ),
			array_map( static fn( array $item ): string => (string) $by_id[ $item['id'] ], $response->get_data() ),
			'brojčana vrijednost početka naziva'
		);
		$expected = get_terms(
			array(
				'taxonomy'   => $set['taxonomy'],
				'hide_empty' => false,
			)
		);
		$this->assertSame( wp_list_pluck( $expected, 'term_id' ), wp_list_pluck( $response->get_data(), 'id' ) );
	}

	public function test_h2_102_term_id(): void {
		$this->skip_unless_supported();
		$set = $this->attribute( 'bbh-id', 'id', self::VALUES );
		$this->as_role( 'shop_manager' );
		$names = $this->names_in_response_order( $set );

		if ( version_compare( WC_VERSION, '8.1', '<' ) ) {
			// WooCommerce 8.0 postavku "id" ne provodi: zadani popis je po nazivu (izmjereno na 8.0.3).
			$by_name = get_terms(
				array(
					'taxonomy'   => $set['taxonomy'],
					'hide_empty' => false,
					'orderby'    => 'name',
				)
			);
			$this->assertSame( wp_list_pluck( $by_name, 'name' ), $names );
		} else {
			$this->assertSame( array_keys( self::VALUES ), $names, 'redom ID-ja, tj. redom stvaranja' );
		}
	}

	public function test_h2_103_menu_order_written_through_woocommerce_rest(): void {
		$this->skip_unless_supported();
		$set = $this->attribute( 'bbh-rest', 'menu_order', array() );
		$this->as_role( 'shop_manager' );

		$ids = array();
		foreach ( array( 'treci' => 3, 'prvi' => 1, 'bez' => null ) as $name => $menu_order ) {
			$request = new WP_REST_Request( 'POST', '/wc/v3/products/attributes/' . $set['id'] . '/terms' );
			$body    = array( 'name' => $name );
			if ( null !== $menu_order ) {
				$body['menu_order'] = $menu_order;
			}
			$request->set_body_params( $body );
			$created = rest_do_request( $request );
			$this->assertSame( 201, $created->get_status(), $name );
			$ids[ $name ] = (int) $created->get_data()['id'];
		}

		$response = $this->order( array( 'taxonomy' => $set['taxonomy'] ) );
		$orders   = array_column( $response->get_data(), 'order', 'id' );
		foreach ( $ids as $name => $id ) {
			// "order" je zapis koji poredak trgovine stvarno čita (meta ključ "order").
			$expected = metadata_exists( 'term', $id, 'order' ) ? (int) get_term_meta( $id, 'order', true ) : null;
			$this->assertSame( $expected, $orders[ $id ], $name );
		}
		$expected = get_terms(
			array(
				'taxonomy'   => $set['taxonomy'],
				'hide_empty' => false,
			)
		);
		$this->assertSame( wp_list_pluck( $expected, 'term_id' ), wp_list_pluck( $response->get_data(), 'id' ) );

		if ( version_compare( WC_VERSION, '8.1', '<' ) ) {
			// WooCommerce 8.0 REST piše pod ključ "order_<taksonomija>", koji poredak ne čita (izmjereno na 8.0.3).
			$this->assertSame( array( null, null, null ), array_values( $orders ) );
			$this->assertSame( '3', get_term_meta( $ids['treci'], 'order_' . $set['taxonomy'], true ) );
			$this->assertSame( array( $ids['bez'], $ids['prvi'], $ids['treci'] ), array_keys( $orders ), 'po nazivu' );
		} else {
			$this->assertSame( array( $ids['bez'], $ids['prvi'], $ids['treci'] ), array_keys( $orders ), 'bez zapisa, 1, 3' );
			$this->assertSame( array( null, 1, 3 ), array_values( $orders ) );
		}
	}

	public function test_h2_101_only_taxonomies_woocommerce_lists_as_attributes(): void {
		$this->skip_unless_supported();
		$set = $this->attribute( 'bbh-boja', 'menu_order', array( 'plava' => 1 ) );
		// Taksonomija s prefiksom pa_ koju WooCommerce ne navodi među atributima.
		register_taxonomy( 'pa_bbh-lazni', array( 'product' ) );
		$this->registered[] = 'pa_bbh-lazni';
		wp_insert_term( 'x', 'pa_bbh-lazni' );
		$this->as_role( 'shop_manager' );

		$this->assertContains( $set['taxonomy'], wc_get_attribute_taxonomy_names() );
		$this->assertSame( 200, $this->order( array( 'taxonomy' => $set['taxonomy'] ) )->get_status() );
		foreach ( array( 'pa_bbh-lazni', 'pa_nepostojeci', 'pa_', 'bbh-boja', 'PA_BBH-BOJA', 'product_tag', 'category', 'nepostojeca', '' ) as $taxonomy ) {
			$response = $this->order( array( 'taxonomy' => $taxonomy ) );
			$this->assertSame( 400, $response->get_status(), $taxonomy );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'], $taxonomy );
		}

		// Tijelo greške 400: isti oblik kao prije; poruka ne nabraja atribute trgovine.
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/v1/terms-order-invalid-taxonomy-0.5.0.json' ), true ); // phpcs:ignore
		$body    = json_decode( (string) wp_json_encode( rest_get_server()->response_to_data( $this->order( array( 'taxonomy' => 'category' ) ), false ) ), true );
		$this->assertSame( $fixture['body'], $body );

		// Opis putanje (javni indeks) ne otkriva nazive atributa.
		$index = (string) wp_json_encode( rest_get_server()->response_to_data( rest_do_request( new WP_REST_Request( 'GET', '/bulkbird/v1' ) ), false ) );
		$this->assertStringContainsString( 'terms\/order', $index );
		$this->assertStringNotContainsString( 'bbh-boja', $index );
		$this->assertStringNotContainsString( '"enum":["product_cat"', $index );
	}

	public function test_h2_104_permissions(): void {
		$this->skip_unless_supported();
		$set = $this->attribute( 'bbh-prava', 'menu_order', array( 'a' => 1 ) );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->order( array( 'taxonomy' => $set['taxonomy'] ) )->get_status() );
		$this->assertSame( 401, $this->order( array( 'taxonomy' => 'pa_nepostojeci' ) )->get_status(), '401 prije 400' );
		foreach ( array( 'editor', 'customer' ) as $role ) {
			$this->as_role( $role );
			$this->assertSame( 403, $this->order( array( 'taxonomy' => $set['taxonomy'] ) )->get_status(), $role );
			$this->assertSame( 403, $this->order( array( 'taxonomy' => 'pa_nepostojeci' ) )->get_status(), $role . ': 403 prije 400' );
		}
		foreach ( array( 'shop_manager', 'administrator' ) as $role ) {
			$this->as_role( $role );
			$this->assertSame( 200, $this->order( array( 'taxonomy' => $set['taxonomy'] ) )->get_status(), $role );
		}
	}

	public function test_h2_105_reads_only(): void {
		$this->skip_unless_supported();
		$set = $this->attribute( 'bbh-cita', 'menu_order', self::VALUES );
		$this->as_role( 'shop_manager' );
		$state = static fn(): array => array(
			wp_count_terms( array( 'taxonomy' => $set['taxonomy'], 'hide_empty' => false ) ),
			get_term_meta( $set['terms']['čaj'] ),
			metadata_exists( 'term', $set['terms']['cimet'], 'order' ),
			wc_attribute_orderby( $set['taxonomy'] ),
		);
		$before = $state();

		$this->order( array( 'taxonomy' => $set['taxonomy'] ) ); // Prvi zahtjev smije stvoriti tablicu brojača.
		$queries = array();
		$record  = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 200, $this->order( array( 'taxonomy' => $set['taxonomy'], 'per_page' => 1000 ) )->get_status() );
		}
		remove_filter( 'query', $record );

		$writes = preg_grep( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE)\b/i', $queries );
		$this->assertSame( array(), array_values( preg_grep( '/bulkbird_rate/', $writes, PREG_GREP_INVERT ) ), 'jedini upis je brojač učestalosti' );
		$this->assertSame( $before, $state(), 'vrijednost bez zapisa i dalje nema zapis; postavka atributa nije mijenjana' );
		$this->assertLessThanOrEqual( 12, count( preg_grep( '/^\s*SELECT\b/i', $queries ) ) / 3, 'upita po zahtjevu' );
	}
}
