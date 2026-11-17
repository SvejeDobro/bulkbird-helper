<?php
/**
 * Mjeri kolaciju naziva pojmova i poredak pojmova na jednoj razini (docs/measurements/term-order.md).
 * Radi nad testnim pojmovima "bbh-test-…" koje je stvorio tests/tools/probe-term-order.php.
 *
 *   wp eval-file tests/probe/term-order.php                  # samo čita
 *   BBH_PREPARE=1 wp eval-file tests/probe/term-order.php    # prije čitanja uklanja zapis redoslijeda
 *                                                            # s dva testna pojma (bbh-test-cimet, bbh-test-đon)
 *
 * Ispis: JSON, bez tajni i bez osobnih podataka.
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	global $wpdb;
	$out = array(
		'host'  => wp_parse_url( home_url(), PHP_URL_HOST ),
		'wp'    => get_bloginfo( 'version' ),
		'wc'    => WC_VERSION,
		'mysql' => $wpdb->get_var( 'SELECT VERSION()' ),
		'theme' => wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ),
	);

	// --- 1. Kolacija ---------------------------------------------------------------------------
	foreach ( (array) $wpdb->get_results( "SHOW FULL COLUMNS FROM {$wpdb->terms} WHERE Field IN ('name', 'slug')", ARRAY_A ) as $col ) {
		$out['columns'][ $col['Field'] ] = array( 'type' => $col['Type'], 'collation' => $col['Collation'] );
	}
	$out['connection'] = array( 'charset' => $wpdb->charset, 'collate' => $wpdb->collate, 'collation_connection' => $wpdb->get_var( 'SELECT @@collation_connection' ) );
	foreach ( array(
		array( 'bbh-test-Čaj', 'bbh-test-Caj' ),
		array( 'bbh-test-Đak', 'bbh-test-Dak' ),
		array( 'bbh-test-šal', 'bbh-test-ŠAL' ),
		array( 'bbh-test-šal', 'bbh-test-sal' ),
		array( 'bbh-test-Čaj', 'bbh-test-Ćaj' ),
	) as $pair ) {
		$out['equality'][] = array(
			'stored' => $pair[0],
			'asked'  => $pair[1],
			// Postoji li pojam spremljen pod prvim nazivom kad se traži drugi (usporedba kolacijom stupca).
			'rows_where_name_equals' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE name = %s", $pair[1] ) ),
			'stored_rows'            => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE BINARY name = %s", $pair[0] ) ),
		);
	}

	// --- 2. Poredak ----------------------------------------------------------------------------
	$sortable        = (array) apply_filters( 'woocommerce_sortable_taxonomies', array( 'product_cat' ) );
	$out['sortable'] = $sortable;

	foreach ( array( 'product_cat', 'product_brand' ) as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$out['taxonomies'][ $taxonomy ] = 'taksonomija ne postoji';
			continue;
		}
		$parent = get_term_by( 'name', 'bbh-test-poredak', $taxonomy );
		if ( ! $parent ) {
			$out['taxonomies'][ $taxonomy ] = 'nema roditelja bbh-test-poredak';
			continue;
		}
		$pid   = (int) $parent->term_id;
		$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND parent = %d ORDER BY term_id", $taxonomy, $pid ) ) );
		$names = array();
		foreach ( $ids as $id ) {
			$names[ $id ] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->terms} WHERE term_id = %d", $id ) );
		}
		$t = array( 'parent_id' => $pid, 'children_by_id' => array_values( $names ) );

		if ( '1' === getenv( 'BBH_PREPARE' ) ) {
			foreach ( $names as $id => $name ) {
				if ( in_array( $name, array( 'bbh-test-cimet', 'bbh-test-đon' ), true ) ) {
					$t['prepared'][ $name ] = array( 'order_before' => get_term_meta( $id, 'order' ), 'removed' => delete_term_meta( $id, 'order' ) );
				}
			}
		}

		// Što je zapisano uz svaki pojam.
		foreach ( $names as $id => $name ) {
			$meta = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_key", $id ), ARRAY_A ) as $row ) {
				$meta[ $row['meta_key'] ] = $row['meta_value'];
			}
			$t['meta'][ $name ] = $meta;
		}
		$t['term_order_column'] = $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->terms} LIKE 'term_order'" ) ? 'postoji' : 'ne postoji';

		// get_terms s raznim argumentima; uz svaki i ORDER BY koji je WordPress stvarno poslao bazi.
		$clauses = array();
		$spy     = static function ( array $c ) use ( &$clauses ): array {
			$clauses = $c;
			return $c;
		};
		add_filter( 'terms_clauses', $spy, PHP_INT_MAX );
		foreach ( array(
			'zadano'                         => array(),
			'menu_order=true'                => array( 'menu_order' => true ),
			'menu_order=false'               => array( 'menu_order' => false ),
			'orderby=name'                   => array( 'orderby' => 'name' ),
			'orderby=name, menu_order=false' => array( 'orderby' => 'name', 'menu_order' => false ),
			'orderby=name, order=DESC'       => array( 'orderby' => 'name', 'order' => 'DESC' ),
			'orderby=id'                     => array( 'orderby' => 'id' ),
			'menu_order=DESC'                => array( 'menu_order' => 'DESC' ),
		) as $label => $args ) {
			$clauses = array();
			$terms   = get_terms( array_merge( array( 'taxonomy' => $taxonomy, 'parent' => $pid, 'hide_empty' => false ), $args ) );
			$t['get_terms'][ $label ] = array(
				'names'   => is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : 'greška',
				'orderby' => trim( preg_replace( '/\s+/', ' ', ( $clauses['orderby'] ?? '' ) . ' ' . ( $clauses['order'] ?? '' ) ) ),
				'join'    => false !== strpos( (string) ( $clauses['join'] ?? '' ), 'termmeta' ) ? 'LEFT/INNER JOIN termmeta' : 'bez termmeta',
			);
		}
		$t['join_sql'] = trim( preg_replace( '/\s+/', ' ', (string) ( $clauses['join'] ?? '' ) ) );
		remove_filter( 'terms_clauses', $spy, PHP_INT_MAX );

		// REST popis (kako ga vidi klijent).
		$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admin[0] );
		$route = 'product_cat' === $taxonomy ? '/wc/v3/products/categories' : '/wc/v3/products/brands';
		foreach ( array( 'zadano' => array(), 'orderby=name' => array( 'orderby' => 'name' ), 'orderby=id' => array( 'orderby' => 'id' ) ) as $label => $query ) {
			$request = new WP_REST_Request( 'GET', $route );
			$request->set_query_params( array_merge( array( 'parent' => $pid, 'per_page' => 100, 'hide_empty' => false ), $query ) );
			$data = rest_do_request( $request )->get_data();
			$t['rest'][ $label ] = is_array( $data ) && isset( $data[0]['name'] ) ? array_map( static fn( $i ) => $i['name'] . ' (' . ( array_key_exists( 'menu_order', $i ) ? var_export( $i['menu_order'], true ) : 'nema polja' ) . ')', $data ) : $data;
		}
		wp_set_current_user( 0 );

		// Prikaz kupcu (kod koji trgovina koristi; kategorije su prazne, pa je skrivanje praznih isključeno).
		$found = static function ( string $html ): array {
			preg_match_all( '/>\s*(bbh-test-[^<]+?)\s*</u', $html, $m );
			return array_values( array_filter( $m[1], static fn( $n ) => 'bbh-test-poredak' !== $n ) );
		};
		if ( 'product_cat' === $taxonomy ) {
			$t['display']['[product_categories parent=X hide_empty=0]']              = $found( do_shortcode( '[product_categories parent="' . $pid . '" hide_empty="0" number="0"]' ) );
			$t['display']['[product_categories parent=X hide_empty=0 orderby=name]'] = $found( do_shortcode( '[product_categories parent="' . $pid . '" hide_empty="0" number="0" orderby="name"]' ) );
			$t['display']['[product_categories parent=X hide_empty=0 orderby=menu_order]'] = $found( do_shortcode( '[product_categories parent="' . $pid . '" hide_empty="0" number="0" orderby="menu_order"]' ) );
			foreach ( array( 'order' => 'widget kategorija (zadano: orderby=order)', 'name' => 'widget kategorija (orderby=name)' ) as $orderby => $label ) {
				ob_start();
				the_widget( 'WC_Widget_Product_Categories', array( 'hide_empty' => 0, 'orderby' => $orderby, 'hierarchical' => 1, 'count' => 0, 'dropdown' => 0, 'show_children_only' => 0, 'max_depth' => '' ) );
				$t['display'][ $label ] = $found( (string) ob_get_clean() );
			}
			add_filter( 'woocommerce_product_subcategories_hide_empty', '__return_false' );
			$t['display']['podkategorije na stranici roditelja (woocommerce_get_product_subcategories)'] = wp_list_pluck( woocommerce_get_product_subcategories( $pid ), 'name' );
			remove_filter( 'woocommerce_product_subcategories_hide_empty', '__return_false' );
		}
		$out['taxonomies'][ $taxonomy ] = $t;
	}
	echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
} )();
