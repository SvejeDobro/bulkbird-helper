<?php
/**
 * Proba za GET /terms/order (H2.91, H2.92): zadani poredak pojmova trgovine i zapisi redoslijeda. Samo čita.
 *
 *   BBH_TAXONOMY=product_cat wp eval-file tests/probe/terms.php
 *
 * Ispis: JSON s ID-jevima (bez naziva).
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	$taxonomy = (string) getenv( 'BBH_TAXONOMY' );
	if ( ! in_array( $taxonomy, array( 'product_cat', 'product_brand' ), true ) ) {
		exit( 1 );
	}
	if ( ! taxonomy_exists( $taxonomy ) ) {
		echo wp_json_encode( array( 'exists' => false ) ) . "\n";
		return;
	}
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		)
	);
	$items = array();
	foreach ( $terms as $term ) {
		$exists  = metadata_exists( 'term', $term->term_id, 'order' );
		$raw     = $exists ? get_term_meta( $term->term_id, 'order', true ) : null;
		$items[] = array(
			'id'     => (int) $term->term_id,
			'parent' => (int) $term->parent,
			'order'  => $exists ? ( is_numeric( $raw ) ? (int) $raw : 0 ) : null,
		);
	}
	echo wp_json_encode(
		array(
			'exists' => true,
			'items'  => $items,
		)
	) . "\n";
} )();
