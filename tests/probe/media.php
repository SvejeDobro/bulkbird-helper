<?php
/**
 * Proba medijske biblioteke za testove faza 1 i 2. Samo čita.
 *
 *   BBH_IDS=12,13 wp eval-file tests/probe/media.php
 *
 * Ispis: JSON (bez tajni i bez osobnih podataka; autor privitka samo kao ID).
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	global $wpdb;

	$mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' );
	$in    = "'" . implode( "','", $mimes ) . "'";
	$test  = "p.post_title LIKE 'bbh-test-%' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_bulkbird_upload_id')";

	// phpcs:disable
	$out = array(
		'image_count'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='attachment' AND p.post_status IN ('inherit','private') AND p.post_mime_type IN ($in)" ),
		'attachments_total'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='attachment'" ),
		'others_count'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='attachment' AND NOT ($test)" ),
		'others_max_modified' => (string) $wpdb->get_var( "SELECT MAX(p.post_modified_gmt) FROM {$wpdb->posts} p WHERE p.post_type='attachment' AND NOT ($test)" ),
		'test_count'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='attachment' AND $test" ),
		'products_max_modified' => (string) $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ),
		'products_count'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ),
		'rate_table'          => (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}bulkbird_rate'" ),
		'db_version'          => get_option( 'bulkbird_helper_db_version', null ),
		'media_trash'         => defined( 'MEDIA_TRASH' ) && MEDIA_TRASH,
		'items'               => array(),
	);
	if ( $out['rate_table'] ) {
		$out['rate_rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bulkbird_rate" );
	}
	// phpcs:enable

	foreach ( array_filter( array_map( 'intval', explode( ',', (string) getenv( 'BBH_IDS' ) ) ) ) as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			$out['items'][ $id ] = null;
			continue;
		}
		$meta = wp_get_attachment_metadata( $id );
		$file = get_attached_file( $id );
		$out['items'][ $id ] = array(
			'title'        => $post->post_title,
			'mime'         => $post->post_mime_type,
			'status'       => $post->post_status,
			'parent'       => (int) $post->post_parent,
			'author'       => (int) $post->post_author,
			'alt'          => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'upload_id'    => (string) get_post_meta( $id, '_bulkbird_upload_id', true ),
			'sha256_ok'    => (bool) preg_match( '/^[0-9a-f]{64}$/', (string) get_post_meta( $id, '_bulkbird_upload_sha256', true ) ),
			'file_exists'  => is_string( $file ) && is_file( $file ),
			'file_matches' => is_string( $file ) && is_file( $file ) && hash_file( 'sha256', $file ) === get_post_meta( $id, '_bulkbird_upload_sha256', true ),
			'basename'     => is_string( $file ) ? wp_basename( $file ) : '',
			'in_uploads'   => is_string( $file ) && 0 === strpos( wp_normalize_path( $file ), wp_normalize_path( wp_get_upload_dir()['basedir'] ) . '/' ),
			'sizes'        => is_array( $meta ) && isset( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : array(),
			'width'        => is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0,
			'used_by'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_thumbnail_id') AND meta_value = %s", (string) $id ) ), // phpcs:ignore
		);
	}

	echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} )();
