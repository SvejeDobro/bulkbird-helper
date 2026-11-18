<?php
/**
 * Poziva Terms_Controller::order() iz izvornog koda koji mu se preda, unutar WordPressa ciljne trgovine,
 * bez postavljanja plugina: za primjer odgovora dok nova verzija još nije na stranici. Samo čita.
 *
 *   BBH_CONTROLLER_B64="$(base64 -w0 includes/class-terms-controller.php)" BBH_QUERY='taxonomy=pa_x&per_page=10' \
 *     wp eval-file tests/probe/terms-branch.php
 *
 * Kod se ne zapisuje na disk trgovine. Dozvole i ograničenje učestalosti se ovdje NE provjeravaju
 * (to rade testovi); proba pokazuje samo tijelo i zaglavlja odgovora.
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	if ( class_exists( 'Bulkbird\\Helper\\Terms_Controller', false ) ) {
		fwrite( STDERR, "Kontroler postavljenog plugina je već učitan; proba ne bi pokazala kod s grane.\n" );
		exit( 1 );
	}
	$source = (string) base64_decode( (string) getenv( 'BBH_CONTROLLER_B64' ), true );
	if ( 0 !== strpos( $source, '<?php' ) || false === strpos( $source, 'final class Terms_Controller' ) ) {
		fwrite( STDERR, "BBH_CONTROLLER_B64 nije izvorni kod kontrolera.\n" );
		exit( 1 );
	}
	eval( substr( $source, 5 ) ); // phpcs:ignore -- vlastiti kod iz repozitorija, samo u ovoj probi.

	parse_str( (string) getenv( 'BBH_QUERY' ), $query );
	$request = new WP_REST_Request( 'GET', '/bulkbird/v1/terms/order' );
	$request->set_query_params( $query );
	$result = ( new Bulkbird\Helper\Terms_Controller() )->order( $request );
	if ( is_wp_error( $result ) ) {
		$result = rest_convert_error_to_response( $result );
	}
	$headers = $result->get_headers();
	echo wp_json_encode(
		array(
			'status'  => $result->get_status(),
			'headers' => array_intersect_key( $headers, array( 'X-WP-Total' => 1, 'X-WP-TotalPages' => 1 ) ),
			'body'    => $result->get_data(),
		)
	) . "\n";
} )();
