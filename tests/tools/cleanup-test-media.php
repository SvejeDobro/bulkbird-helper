<?php
/**
 * Briše testne slike koje su ostavili testovi prijenosa. Trajno odobrenje vlasnika (odluka P17), usko:
 *   - samo privitci kojima naslov počinje s "bbh-test-" I koji imaju meta "_bulkbird_upload_id";
 *   - samo na testnim stranicama čiji je host u BBH_TEST_HOSTS (zarezom odvojeno; prazno = odbija);
 *   - samo kroz WP-CLI;
 *   - prije brisanja ispisuje broj i ID-jeve.
 * Ništa drugo ova skripta ne dira.
 *
 *   wp eval-file tests/tools/cleanup-test-media.php            # briše
 *   BBH_DRY_RUN=1 wp eval-file tests/tools/cleanup-test-media.php   # samo ispis
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
		fwrite( STDERR, "Ova skripta radi samo na testnim stranicama iz BBH_TEST_HOSTS (ovo je $host; vidi sites.env.example).\n" );
		exit( 1 );
	}

	$ids = array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_title LIKE %s ORDER BY p.ID",
				'_bulkbird_upload_id',
				$wpdb->esc_like( 'bbh-test-' ) . '%'
			)
		)
	);

	$out = array(
		'site'    => $host,
		'dry_run' => '1' === getenv( 'BBH_DRY_RUN' ),
		'count'   => count( $ids ),
		'ids'     => $ids,
		'deleted' => array(),
		'failed'  => array(),
	);
	fwrite( STDERR, sprintf( "[%s] testnih slika za brisanje: %d; ID: %s\n", $host, count( $ids ), $ids ? implode( ', ', $ids ) : '-' ) );

	if ( ! $out['dry_run'] ) {
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			// Dvostruka provjera oba uvjeta neposredno prije brisanja.
			if ( ! $post || 'attachment' !== $post->post_type || 0 !== strpos( $post->post_title, 'bbh-test-' ) || '' === (string) get_post_meta( $id, '_bulkbird_upload_id', true ) ) {
				$out['failed'][] = $id;
				continue;
			}
			if ( wp_delete_attachment( $id, true ) ) {
				$out['deleted'][] = $id;
			} else {
				$out['failed'][] = $id;
			}
		}
	}

	echo wp_json_encode( $out ) . "\n";
} )();
