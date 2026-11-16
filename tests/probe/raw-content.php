<?php
/**
 * Čita sirovi post_content jednog testnog zapisa (naslov počinje s "bbh-test-"). Samo čita.
 *
 *   BBH_POST_ID=123 wp eval-file tests/probe/raw-content.php
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	global $wpdb;
	$id  = (int) getenv( 'BBH_POST_ID' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_title, post_status, post_content FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
	if ( ! is_array( $row ) || 0 !== strpos( (string) $row['post_title'], 'bbh-test-' ) ) {
		fwrite( STDERR, "Nije testni zapis.\n" );
		exit( 1 );
	}
	echo wp_json_encode( $row ) . "\n";
} )();
