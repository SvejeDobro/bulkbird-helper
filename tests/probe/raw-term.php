<?php
/**
 * Čita sirovi opis jednog testnog pojma (naziv počinje s "bbh-test-") ravno iz baze. Samo čita.
 *
 *   BBH_TERM_ID=123 wp eval-file tests/probe/raw-term.php
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	global $wpdb;
	$id  = (int) getenv( 'BBH_TERM_ID' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT t.name, tt.taxonomy, tt.description FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d", $id ), ARRAY_A );
	if ( ! is_array( $row ) || 0 !== strpos( (string) $row['name'], 'bbh-test-' ) ) {
		fwrite( STDERR, "Nije testni pojam.\n" );
		exit( 1 );
	}
	echo wp_json_encode( $row ) . "\n";
} )();
