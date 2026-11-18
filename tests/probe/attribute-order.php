<?php
/**
 * Mjeri poredak vrijednosti testnog globalnog atributa "bbh-test-atribut"
 * (docs/measurements/attribute-order.md). Bez BBH_PREPARE samo čita.
 *
 *   wp eval-file tests/probe/attribute-order.php
 *   BBH_PREPARE=1 wp eval-file tests/probe/attribute-order.php   # prije čitanja: na testnim vrijednostima
 *                                                                # prepisuje broj iz "order_pa_…" u "order"
 *                                                                # (ono što radi povlačenje u administraciji)
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
	$taxonomy = 'pa_bbh-test-atribut';
	$out      = array(
		'host'           => wp_parse_url( home_url(), PHP_URL_HOST ),
		'wc'             => WC_VERSION,
		'taxonomy_names' => wc_get_attribute_taxonomy_names(),
		'exists'         => taxonomy_exists( $taxonomy ),
	);
	if ( ! taxonomy_exists( $taxonomy ) ) {
		echo wp_json_encode( $out ) . "\n";
		return;
	}
	$out['row']                  = $wpdb->get_row( $wpdb->prepare( "SELECT attribute_id, attribute_name, attribute_label, attribute_type, attribute_orderby, attribute_public FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s", 'bbh-test-atribut' ), ARRAY_A );
	$out['wc_attribute_orderby'] = wc_attribute_orderby( $taxonomy );
	$out['hierarchical']         = is_taxonomy_hierarchical( $taxonomy );
	$out['sortable_taxonomies']  = (array) apply_filters( 'woocommerce_sortable_taxonomies', array( 'product_cat' ) );

	if ( '1' === getenv( 'BBH_PREPARE' ) ) {
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s", $taxonomy ) ) as $term ) {
			$legacy = get_term_meta( (int) $term->term_id, 'order_' . $taxonomy, true );
			if ( 0 === strpos( $term->name, 'bbh-test-' ) && is_numeric( $legacy ) ) {
				update_term_meta( (int) $term->term_id, 'order', (int) $legacy );
				$out['prepared'][] = (int) $term->term_id;
			}
		}
	}

	// Što je zapisano uz svaku vrijednost (svi ključevi koji počinju s "order"), redom ID-ja.
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s ORDER BY term_id", $taxonomy ) ) );
	foreach ( $ids as $id ) {
		$name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->terms} WHERE term_id = %d", $id ) );
		$meta = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_key", $id ), ARRAY_A ) as $row ) {
			$meta[ $row['meta_key'] ] = $row['meta_value'];
		}
		$out['by_id'][] = array( 'id' => $id, 'name' => $name, 'meta' => $meta );
	}

	// Zadani get_terms i ORDER BY koji je stvarno poslan bazi.
	$clauses = array();
	$spy     = static function ( array $c ) use ( &$clauses ): array {
		$clauses = $c;
		return $c;
	};
	add_filter( 'terms_clauses', $spy, PHP_INT_MAX );
	$terms            = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
	$out['get_terms'] = array(
		'names'   => wp_list_pluck( $terms, 'name' ),
		'ids'     => wp_list_pluck( $terms, 'term_id' ),
		'orderby' => trim( (string) preg_replace( '/\s+/', ' ', ( $clauses['orderby'] ?? '' ) . ' ' . ( $clauses['order'] ?? '' ) ) ),
		'join'    => trim( (string) preg_replace( '/\s+/', ' ', (string) ( $clauses['join'] ?? '' ) ) ),
	);
	remove_filter( 'terms_clauses', $spy, PHP_INT_MAX );

	// Na proizvodu (skica bbh-test-atribut-proizvod nosi sve vrijednosti).
	$product_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'draft' AND post_title = %s ORDER BY ID DESC LIMIT 1", 'bbh-test-atribut-proizvod' ) );
	if ( $product_id ) {
		$out['product_id']             = $product_id;
		$out['wc_get_product_terms']   = wc_get_product_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
		$product                       = wc_get_product( $product_id );
		ob_start();
		wc_display_product_attributes( $product );
		preg_match_all( '/(bbh-test-(?!atribut)[^<,\s]+)/u', wp_strip_all_tags( (string) ob_get_clean() ), $m );
		$out['product_page_attribute_table'] = $m[1];
		$out['rest_product_options']         = array();
		$admin                               = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admin[0] );
		$data = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/' . $product_id ) )->get_data();
		wp_set_current_user( 0 );
		$out['rest_product_options'] = $data['attributes'][0]['options'] ?? null;
	}
	echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
} )();
