<?php
/**
 * Premješta JEDNU testnu sliku u smeće, za snimanje odgovora "trashed" (odobrio vlasnik 15. 11. 2026.).
 * Uvjeti su isti kao za brisanje testnih slika (odluka P17): naslov počinje s "bbh-test-" I slika ima meta
 * "_bulkbird_upload_id"; samo teststore1 i teststoreold; samo kroz WP-CLI. Ništa drugo se ne dira.
 *
 *   BBH_ATTACHMENT_ID=123 wp eval-file tests/tools/trash-test-media.php
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	global $wpdb;

	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$id   = (int) getenv( 'BBH_ATTACHMENT_ID' );
	$post = get_post( $id );
	if ( ! in_array( $host, array( 'teststore1.bulkbird.app', 'teststoreold.bulkbird.app' ), true )
		|| ! $post || 'attachment' !== $post->post_type
		|| 0 !== strpos( $post->post_title, 'bbh-test-' )
		|| '' === (string) get_post_meta( $id, '_bulkbird_upload_id', true ) ) {
		fwrite( STDERR, "Odbijeno: nije testna slika plugina na testnoj stranici.\n" );
		exit( 1 );
	}

	// Kao WordPressovo smeće za medije: mijenja se samo stanje zapisa, datoteke ostaju.
	$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore
	clean_post_cache( $id );
	echo wp_json_encode(
		array(
			'id'     => $id,
			'title'  => $post->post_title,
			'status' => get_post_status( $id ),
		)
	) . "\n";
} )();
