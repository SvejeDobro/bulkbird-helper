<?php
/**
 * Briše testne globalne atribute, njihove vrijednosti i testne skice proizvoda koje su stvorili testovi i
 * mjerenja plugina. Trajno odobrenje vlasnika (17. i 18. 11. 2026.), usko:
 *   - samo atributi kojima i naziv i slug počinju s "bbh-test-", i samo ako SVE njihove vrijednosti
 *     imaju naziv koji počinje s "bbh-test-" (inače se atribut ne dira);
 *   - samo skice proizvoda (status draft) kojima naziv počinje s "bbh-test-atribut";
 *   - samo na testnim stranicama čiji je host u BBH_TEST_HOSTS (zarezom odvojeno; prazno = odbija); samo kroz WP-CLI;
 *   - prije brisanja ispisuje ID-jeve.
 * Ništa drugo ova skripta ne dira.
 *
 *   wp eval-file tests/tools/cleanup-test-attributes.php
 *   BBH_DRY_RUN=1 wp eval-file tests/tools/cleanup-test-attributes.php   # samo ispis
 *
 * Zadnji redak ispisa je JSON sa sažetkom.
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
	$dry  = '1' === getenv( 'BBH_DRY_RUN' );
	$like = $wpdb->esc_like( 'bbh-test-' ) . '%';
	$done = array(
		'host'       => $host,
		'dry_run'    => $dry,
		'products'   => array(),
		'attributes' => array(),
		'terms'      => array(),
		'skipped'    => array(),
		'failed'     => 0,
	);

	// 1. Skice proizvoda.
	$products = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'draft' AND post_title LIKE %s ORDER BY ID", $wpdb->esc_like( 'bbh-test-atribut' ) . '%' ) ) );
	echo sprintf( "[%s] testnih skica proizvoda za brisanje: %d; ID: %s\n", $host, count( $products ), $products ? implode( ', ', $products ) : '-' );

	// 2. Atributi i njihove vrijednosti.
	$plan = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT attribute_id, attribute_name, attribute_label FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name LIKE %s AND attribute_label LIKE %s ORDER BY attribute_id", $like, $like ) ) as $row ) {
		$taxonomy = 'pa_' . $row->attribute_name;
		$terms    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY t.term_id", $taxonomy ) );
		$foreign  = array_filter( $terms, static fn( $term ): bool => 0 !== strpos( (string) $term->name, 'bbh-test-' ) );
		$ids      = array_map( static fn( $term ): int => (int) $term->term_id, $terms );
		if ( $foreign ) {
			$done['skipped'][] = (int) $row->attribute_id;
			echo sprintf( "[%s] atribut ID %d (%s) ima vrijednosti koje nisu testne; NE DIRA SE\n", $host, (int) $row->attribute_id, $taxonomy );
			continue;
		}
		$plan[] = array( (int) $row->attribute_id, $taxonomy, $ids );
		echo sprintf( "[%s] testni atribut za brisanje: ID %d (%s); vrijednosti: %d; ID: %s\n", $host, (int) $row->attribute_id, $taxonomy, count( $ids ), $ids ? implode( ', ', $ids ) : '-' );
	}
	if ( ! $plan ) {
		echo sprintf( "[%s] testnih atributa za brisanje: 0\n", $host );
	}

	if ( ! $dry ) {
		foreach ( $products as $product_id ) {
			$post = get_post( $product_id );
			// Provjera uvjeta neposredno prije brisanja.
			if ( $post instanceof WP_Post && 'product' === $post->post_type && 'draft' === $post->post_status && 0 === strpos( $post->post_title, 'bbh-test-atribut' ) && wp_delete_post( $product_id, true ) ) {
				$done['products'][] = $product_id;
			} else {
				++$done['failed'];
			}
		}
		foreach ( $plan as list( $attribute_id, $taxonomy, $ids ) ) {
			foreach ( $ids as $term_id ) {
				$term = get_term( $term_id, $taxonomy );
				if ( $term instanceof WP_Term && 0 === strpos( $term->name, 'bbh-test-' ) && true === wp_delete_term( $term_id, $taxonomy ) ) {
					$done['terms'][] = $term_id;
				} else {
					++$done['failed'];
				}
			}
			if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) ) && true === wc_delete_attribute( $attribute_id ) ) {
				$done['attributes'][] = $attribute_id;
			} else {
				++$done['failed'];
			}
		}
		$done['left'] = array(
			'attributes'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name LIKE %s", $like ) ),
			'term_taxonomy'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE %s", $wpdb->esc_like( 'pa_bbh-test-' ) . '%' ) ),
			'terms'          => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE name LIKE %s", $like ) ),
			'termmeta'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'order_pa_bbh-test-' ) . '%' ) ),
			'draft_products' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE %s", $wpdb->esc_like( 'bbh-test-atribut' ) . '%' ) ),
		);
		echo sprintf( "[%s] obrisano: skica %d, vrijednosti %d, atributa %d; nije uspjelo: %d\n", $host, count( $done['products'] ), count( $done['terms'] ), count( $done['attributes'] ), $done['failed'] );
	}
	echo wp_json_encode( $done ) . "\n";
} )();
