<?php
/**
 * Briše testne pojmove koje su stvorila mjerenja plugina. Trajno odobrenje vlasnika (17. 11. 2026.), usko:
 *   - samo pojmovi kojima naziv počinje s "bbh-test-";
 *   - samo taksonomije product_cat, product_tag i product_brand;
 *   - samo na testnim stranicama čiji je host u BBH_TEST_HOSTS (zarezom odvojeno; prazno = odbija); samo kroz WP-CLI;
 *   - prije brisanja ispisuje broj i ID-jeve.
 * Ništa drugo ova skripta ne dira.
 *
 *   wp eval-file tests/tools/cleanup-test-terms.php
 *   BBH_DRY_RUN=1 wp eval-file tests/tools/cleanup-test-terms.php   # samo ispis
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	global $wpdb;

	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! in_array( $host, array_filter( array_map( 'trim', explode( ',', (string) getenv( 'BBH_TEST_HOSTS' ) ) ) ), true ) ) {
		fwrite( STDERR, "Ova skripta radi samo na testnim stranicama iz BBH_TEST_HOSTS (vidi sites.env.example).\n" );
		exit( 1 );
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.term_id, t.name, tt.taxonomy, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			WHERE t.name LIKE %s AND tt.taxonomy IN ('product_cat', 'product_tag', 'product_brand') ORDER BY t.term_id",
			$wpdb->esc_like( 'bbh-test-' ) . '%'
		)
	);
	$ids  = array();
	foreach ( $rows as $row ) {
		$ids[] = $row->taxonomy . ':' . (int) $row->term_id;
	}
	echo sprintf( "[%s] testnih pojmova za brisanje: %d; %s\n", $host, count( $rows ), $ids ? implode( ', ', $ids ) : '-' );

	$deleted = 0;
	$failed  = 0;
	if ( '1' !== getenv( 'BBH_DRY_RUN' ) ) {
		foreach ( $rows as $row ) {
			$term = get_term( (int) $row->term_id, $row->taxonomy );
			// Provjera uvjeta neposredno prije brisanja; pojam s proizvodima se ne briše.
			if ( ! $term instanceof WP_Term || 0 !== strpos( $term->name, 'bbh-test-' ) || (int) $term->count > 0 ) {
				++$failed;
				continue;
			}
			true === wp_delete_term( (int) $row->term_id, $row->taxonomy ) ? ++$deleted : ++$failed;
		}
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE name LIKE %s", $wpdb->esc_like( 'bbh-test-' ) . '%' ) );
		echo sprintf( "[%s] obrisano: %d; nije uspjelo: %d; preostalo pojmova bbh-test-: %d\n", $host, $deleted, $failed, $left );
	}
} )();
