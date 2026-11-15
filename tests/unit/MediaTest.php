<?php
/**
 * Pravila za slike, upit prema biblioteci i ograničenje učestalosti (jedinično; faze 1 i 2).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

use Bulkbird\Helper\Media_Library;
use Bulkbird\Helper\Media_Rules;
use Bulkbird\Helper\Rate_Limiter;
use PHPUnit\Framework\TestCase;

final class MediaTest extends TestCase {

	private const ALL = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' );

	/** @var string[] */
	private array $files = array();

	protected function setUp(): void {
		BBH_State::reset();
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			@unlink( $file );
		}
	}

	private function file( string $content ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'bbh' );
		file_put_contents( $path, $content );
		$this->files[] = $path;
		return $path;
	}

	private function image( string $type, int $width = 12, int $height = 8 ): string {
		$image = imagecreatetruecolor( $width, $height );
		ob_start();
		if ( 'png' === $type ) {
			imagepng( $image );
		} elseif ( 'gif' === $type ) {
			imagegif( $image );
		} elseif ( 'webp' === $type ) {
			imagewebp( $image );
		} else {
			imagejpeg( $image );
		}
		return $this->file( (string) ob_get_clean() );
	}

	/** PNG koji u zaglavlju tvrdi goleme dimenzije (datoteka od nekoliko desetaka bajtova). */
	private function png_claiming( int $width, int $height ): string {
		$ihdr = pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );
		return $this->file( "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) ) );
	}

	private function inspect( string $path, array $accepted = self::ALL, int $max = 20971520 ) {
		return Media_Rules::inspect_file( $path, (int) filesize( $path ), $accepted, $max );
	}

	private static function assert_error( $result, string $code, int $status ): void {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
		self::assertSame( array( 'status' => $status ), $result->get_error_data() );
		self::assertDoesNotMatchRegularExpression( '#/|\.php|tmp#', $result->get_error_message(), 'poruka greške bez putanja' );
	}

	// --- Vrsta po sadržaju (H2.14, H2.17–H2.20, H2.23; kvarovi H2.57, H2.58) --------------------

	public function test_h2_14_real_images_are_recognised_by_content(): void {
		foreach ( array( 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ) as $type => $mime ) {
			$result = $this->inspect( $this->image( $type ) );
			self::assertIsArray( $result, $type );
			self::assertSame( $mime, $result['mime'] );
			self::assertSame( Media_Rules::TYPES[ $mime ], $result['extension'] );
			self::assertSame( 12, $result['width'] );
			self::assertSame( 8, $result['height'] );
			self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result['sha256'] );
		}
	}

	public function test_h2_20_extension_follows_content_not_name(): void {
		$png    = $this->image( 'png' );
		$result = $this->inspect( $png );
		self::assertSame( 'png', $result['extension'] );
		self::assertSame( 'slika.png', Media_Rules::safe_filename( 'slika.jpg', $result['extension'] ) );
	}

	public function test_h2_17_svg_is_never_accepted(): void {
		$svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
		self::assert_error( $this->inspect( $this->file( $svg ) ), 'bulkbird_unsupported_type', 415 );
		self::assertArrayNotHasKey( 'image/svg+xml', Media_Rules::TYPES );
		self::assertNotContains( 'svg', Media_Rules::TYPES );
	}

	public function test_h2_18_h2_19_non_images_are_rejected_whatever_their_name(): void {
		foreach ( array( '<?php echo "x"; ?>', '%PDF-1.4 test', "PK\x03\x04zip", '<html><body>x</body></html>', 'plain text', "BM\x00\x00bmp" ) as $content ) {
			self::assert_error( $this->inspect( $this->file( $content ) ), 'bulkbird_unsupported_type', 415 );
		}
	}

	public function test_h2_23_empty_file(): void {
		self::assert_error( $this->inspect( $this->file( '' ) ), 'bulkbird_invalid_image', 400 );
	}

	public function test_h2_16_h2_29_types_the_shop_does_not_accept(): void {
		self::assert_error( $this->inspect( $this->image( 'png' ), array( 'image/jpeg' ) ), 'bulkbird_unsupported_type', 415 );
	}

	public function test_upload_types_respect_the_shop_and_the_server(): void {
		self::assertSame( self::ALL, Media_Rules::upload_mime_types() );

		// Stara trgovina: WordPress ne dopušta AVIF, a poslužitelj ga ne zna obraditi.
		unset( BBH_Media_State::$site_mimes['avif'] );
		BBH_Media_State::$editor_supports = array( 'image/webp' );
		self::assertSame( array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), Media_Rules::upload_mime_types() );

		// Trgovina je sama zabranila WebP.
		unset( BBH_Media_State::$site_mimes['webp'] );
		self::assertSame( array( 'image/jpeg', 'image/png', 'image/gif' ), Media_Rules::upload_mime_types() );
		BBH_Media_State::$site_mimes['webp'] = 'image/webp';
		BBH_Media_State::$site_mimes['avif'] = 'image/avif';
	}

	// --- Veličina (H2.24, H2.25, H2.28; kvar H2.59) ---------------------------------------------

	public function test_h2_24_h2_25_size_limit(): void {
		$jpeg = $this->image( 'jpeg' );
		$size = (int) filesize( $jpeg );
		self::assertIsArray( $this->inspect( $jpeg, self::ALL, $size ), 'točno na granici' );
		self::assert_error( $this->inspect( $jpeg, self::ALL, $size - 1 ), 'bulkbird_file_too_large', 413 );
		self::assert_error( Media_Rules::inspect_file( $jpeg, 25000000, self::ALL, 20971520 ), 'bulkbird_file_too_large', 413 );
	}

	public function test_effective_limit_is_the_smaller_of_shop_and_20_mb(): void {
		self::assertSame( 20971520, Media_Rules::effective_max_bytes( 67108864 ) );
		self::assertSame( 2097152, Media_Rules::effective_max_bytes( 2097152 ) );
		self::assertSame( 20971520, Media_Rules::effective_max_bytes( 0 ) );
	}

	public function test_h2_28_huge_dimensions_are_rejected_before_any_processing(): void {
		self::assert_error( $this->inspect( $this->png_claiming( 20000, 20000 ) ), 'bulkbird_image_too_large', 400 );
		self::assertIsArray( $this->inspect( $this->png_claiming( 8000, 8000 ) ), '64 MP je još dopušteno' );
	}

	// --- Ime datoteke (H2.21) -------------------------------------------------------------------

	public function test_h2_21_file_names_are_made_safe(): void {
		foreach ( array( 'napad.php.jpg', '../../x.jpg', 'C:\\Users\\a\\x.jpg', '.htaccess', 'a b c.JPG', str_repeat( 'x', 400 ) . '.jpg', '' ) as $name ) {
			$safe = Media_Rules::safe_filename( $name, 'jpg' );
			self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+\.jpg$/', $safe, $name );
			self::assertStringNotContainsString( '.php', $safe, 'nijedan dio imena ne smije biti nastavak .php' );
			self::assertLessThanOrEqual( 154, strlen( $safe ) );
		}
		self::assertSame( 'napad-php.jpg', Media_Rules::safe_filename( 'napad.php.jpg', 'jpg' ) );
		self::assertSame( 'x.jpg', Media_Rules::safe_filename( '../../x.jpg', 'jpg' ) );
		self::assertSame( 'image.png', Media_Rules::safe_filename( '', 'png' ) );
	}

	// --- Idempotentnost (H2.39, H2.42, H2.45, H2.46; kvarovi H2.60, H2.61) ----------------------

	public function test_h2_39_h2_42_decision_for_a_repeated_upload_id(): void {
		$sha = str_repeat( 'a', 64 );
		self::assertSame( 'create', Media_Rules::decide( null, $sha ) );
		self::assertSame( 'existing', Media_Rules::decide( array( 'status' => 'inherit', 'sha256' => $sha ), $sha ) );
		self::assertSame( 'conflict', Media_Rules::decide( array( 'status' => 'inherit', 'sha256' => str_repeat( 'b', 64 ) ), $sha ) );
		self::assertSame( 'conflict', Media_Rules::decide( array( 'status' => 'inherit', 'sha256' => '' ), $sha ) );
		self::assertSame( 'trashed', Media_Rules::decide( array( 'status' => 'trash', 'sha256' => $sha ), $sha ) );
	}

	public function test_h2_11_upload_id_is_compared_case_insensitively(): void {
		self::assertSame( '3f2b8c1e-9d4a-4b7e-8f1a-2c3d4e5f6a7b', Media_Rules::normalize_upload_id( ' 3F2B8C1E-9D4A-4B7E-8F1A-2C3D4E5F6A7B ' ) );
	}

	// --- Bez dodjele proizvodu (H2.36; kvar H2.62) ----------------------------------------------

	public function test_h2_36_new_attachment_is_never_attached_to_anything(): void {
		$args = Media_Rules::attachment_args( 'bbh-test-x', 'image/jpeg', 'https://shop.example/x.jpg', 7 );
		self::assertSame( 0, $args['post_parent'] );
		self::assertSame( 'inherit', $args['post_status'] );
		self::assertSame( 7, $args['post_author'] );
		self::assertSame( 'image/jpeg', $args['post_mime_type'] );
	}

	// --- Upit prema biblioteci (H1.24, H1.26, H1.28, H1.34; kvarovi H2.63, H2.64) ---------------

	public function test_h1_26_search_text_travels_only_as_a_value(): void {
		$evil  = "%' OR 1=1 -- _x";
		$parts = Media_Library::where( array( 'search' => $evil ), self::ALL );
		self::assertStringNotContainsString( 'OR 1=1', $parts['where'] );
		self::assertStringNotContainsString( $evil, $parts['where'] );
		self::assertTrue( $parts['needs_file_join'] );
		self::assertSame( "%\\%' OR 1=1 -- \\_x%", $parts['values'][5], 'znakovi % i _ su obični tekst' );
		self::assertSame( $parts['values'][5], $parts['values'][6] );
		self::assertSame( substr_count( $parts['where'], '%s' ), count( $parts['values'] ) );
	}

	public function test_h1_24_search_covers_title_and_file_name_only(): void {
		$parts = Media_Library::where( array( 'search' => 'hoodie' ), self::ALL );
		self::assertStringContainsString( 'p.post_title LIKE %s', $parts['where'] );
		self::assertStringContainsString( "SUBSTRING_INDEX(f.meta_value, '/', -1) LIKE %s", $parts['where'], 'ime datoteke bez mape' );
	}

	public function test_h1_27_empty_search_is_ignored(): void {
		foreach ( array( '', '   ' ) as $search ) {
			$parts = Media_Library::where( array( 'search' => $search ), self::ALL );
			self::assertFalse( $parts['needs_file_join'] );
			self::assertStringNotContainsString( 'LIKE', $parts['where'] );
		}
	}

	public function test_h1_34_only_listed_image_types_and_no_trash(): void {
		$parts = Media_Library::where( array(), self::ALL );
		self::assertSame(
			"p.post_type = 'attachment' AND p.post_status IN ('inherit', 'private') AND p.post_mime_type IN (%s, %s, %s, %s, %s)",
			$parts['where'],
			'uvjet mora biti točno ovaj: bez OR i bez drugih vrsta zapisa'
		);
		self::assertStringNotContainsString( ' OR ', Media_Library::where( array( 'modified_after' => '2026-01-01 00:00:00' ), self::ALL )['where'] );
		self::assertSame( self::ALL, $parts['values'] );
		self::assertSame( self::ALL, Media_Rules::listed_mime_types() );
	}

	public function test_h1_28_modified_after_is_strict(): void {
		$parts = Media_Library::where( array( 'modified_after' => '2026-11-13 00:00:00' ), self::ALL );
		self::assertStringContainsString( 'p.post_modified_gmt > %s', $parts['where'] );
		self::assertSame( '2026-11-13 00:00:00', end( $parts['values'] ) );
	}

	public function test_h1_10_h1_22_ordering(): void {
		self::assertSame( 'p.post_date_gmt DESC, p.ID DESC', Media_Library::order_by( 'date' ) );
		self::assertSame( 'p.post_title ASC, p.ID ASC', Media_Library::order_by( 'title' ) );
		self::assertSame( 'p.post_date_gmt DESC, p.ID DESC', Media_Library::order_by( "title; DROP TABLE x" ) );
	}

	// --- Stavka (H1.14; kvar H2.65) -------------------------------------------------------------

	public function test_h1_14_filename_is_a_bare_name(): void {
		self::assertSame( 'mh01-black_main.jpg', Media_Library::filename( '2026/11/mh01-black_main.jpg', '' ) );
		self::assertSame( 'x.jpg', Media_Library::filename( '/home/user/htdocs/wp-content/uploads/2026/11/x.jpg', '' ) );
		self::assertSame( 'y.png', Media_Library::filename( '', 'https://shop.example/wp-content/uploads/2026/11/y.png' ) );
		self::assertSame( '2026-11-12T23:09:41', Media_Library::date( '2026-11-12 23:09:41' ) );
		self::assertNull( Media_Library::date( '0000-00-00 00:00:00' ) );
	}

	// --- Ograničenje učestalosti (H1.47–H1.56, H2.53; kvar H2.66) --------------------------------

	public function test_evaluate_boundaries(): void {
		$limits = array( 'requests' => 120, 'uploads' => 30, 'window' => 60 );
		self::assertTrue( Rate_Limiter::evaluate( 120, 0, false, $limits, 600, 610 )['allowed'] );
		$over = Rate_Limiter::evaluate( 121, 0, false, $limits, 600, 610 );
		self::assertFalse( $over['allowed'] );
		self::assertSame( 50, $over['retry_after'] );
		self::assertTrue( Rate_Limiter::evaluate( 31, 30, true, $limits, 600, 610 )['allowed'] );
		self::assertFalse( Rate_Limiter::evaluate( 31, 31, true, $limits, 600, 610 )['allowed'] );
		self::assertTrue( Rate_Limiter::evaluate( 50, 31, false, $limits, 600, 610 )['allowed'], 'čitanje nije prijenos' );
		self::assertSame( 1, Rate_Limiter::evaluate( 121, 0, false, $limits, 600, 660 )['retry_after'], 'nikad 0' );
		self::assertSame( 600, Rate_Limiter::window_start( 659, 60 ) );
	}

	public function test_h1_47_request_121_is_refused_with_retry_after(): void {
		for ( $i = 1; $i <= 120; $i++ ) {
			self::assertTrue( Rate_Limiter::check( 7, false ), "zahtjev $i" );
		}
		$refused = Rate_Limiter::check( 7, false );
		if ( true === $refused ) {
			self::markTestSkipped( 'Prijelaz prozora usred testa.' );
		}
		self::assertSame( 'bulkbird_rate_limited', $refused->get_error_code() );
		$data = $refused->get_error_data();
		self::assertSame( 429, $data['status'] );
		self::assertGreaterThanOrEqual( 1, $data['retry_after'] );
		self::assertLessThanOrEqual( 60, $data['retry_after'] );
	}

	public function test_h2_53_upload_31_is_refused(): void {
		BBH_Media_State::$rate_limits = array( 'requests' => 1000, 'uploads' => 30, 'window' => 3600 );
		for ( $i = 1; $i <= 30; $i++ ) {
			self::assertTrue( Rate_Limiter::check( 7, true ) );
		}
		self::assertInstanceOf( WP_Error::class, Rate_Limiter::check( 7, true ) );
		self::assertTrue( Rate_Limiter::check( 7, false ), 'čitanje i dalje prolazi' );
	}

	public function test_h1_49_h1_51_counters_are_per_user_and_anonymous_is_not_counted(): void {
		BBH_Media_State::$rate_limits = array( 'requests' => 2, 'uploads' => 30, 'window' => 3600 );
		self::assertTrue( Rate_Limiter::check( 7, false ) );
		self::assertTrue( Rate_Limiter::check( 7, false ) );
		self::assertInstanceOf( WP_Error::class, Rate_Limiter::check( 7, false ) );
		self::assertTrue( Rate_Limiter::check( 8, false ), 'drugi korisnik ima svoj brojač' );

		$queries_before = count( $GLOBALS['wpdb']->log );
		for ( $i = 0; $i < 10; $i++ ) {
			self::assertTrue( Rate_Limiter::check( 0, false ) );
		}
		self::assertCount( $queries_before, $GLOBALS['wpdb']->log, 'neprijavljeni zahtjevi ne upisuju ništa' );
		self::assertSame( array( 7, 8 ), array_keys( $GLOBALS['wpdb']->rows ), 'jedan redak po korisniku' );
	}

	public function test_h1_54_h1_57_table_is_created_on_first_use_and_one_write_per_request(): void {
		self::assertFalse( $GLOBALS['wpdb']->table_exists );
		self::assertTrue( Rate_Limiter::check( 7, false ) );
		self::assertTrue( $GLOBALS['wpdb']->table_exists );
		self::assertSame( array( array( 'bulkbird_helper_db_version', '1', false ) ), BBH_Media_State::$updated_options );

		$GLOBALS['wpdb']->log = array();
		Rate_Limiter::check( 7, true );
		self::assertSame( array( 'INSERT', 'SELECT' ), $GLOBALS['wpdb']->log, 'jedan upis i jedno čitanje po zahtjevu' );
	}

	public function test_h1_55_limits_can_be_filtered(): void {
		self::assertSame( array( 'requests' => 120, 'uploads' => 30, 'window' => 60 ), Rate_Limiter::limits() );
		BBH_Media_State::$rate_limits = array( 'requests' => 5 );
		self::assertSame( array( 'requests' => 5, 'uploads' => 30, 'window' => 60 ), Rate_Limiter::limits() );
		BBH_Media_State::$rate_limits = array( 'requests' => 0, 'uploads' => -3, 'window' => 0 );
		self::assertSame( array( 'requests' => 1, 'uploads' => 1, 'window' => 1 ), Rate_Limiter::limits() );
	}

	// --- Dozvole putanja /media (H1.5, H1.7, H2.5; kvarovi H2.55, H2.56) ------------------------

	public function test_h1_7_media_routes_need_both_capabilities(): void {
		Bulkbird\Helper\register_rest_routes();
		$media = BBH_State::$routes[1][2];
		self::assertSame( array( 'GET', 'POST' ), array( $media[0]['methods'], $media[1]['methods'] ) );

		foreach ( array( $media[0]['permission_callback'], $media[1]['permission_callback'] ) as $callback ) {
			self::assertIsCallable( $callback );
			self::assertSame( 'bulkbird_unauthorized', call_user_func( $callback )->get_error_code() );

			BBH_State::$logged_in     = true;
			BBH_Media_State::$user_id = 7;
			foreach ( array( array( 'read' ), array( 'upload_files' ), array( 'manage_woocommerce' ), array( 'upload_files', 'edit_posts' ) ) as $caps ) {
				BBH_State::$caps = $caps;
				self::assertSame( 'bulkbird_forbidden', call_user_func( $callback )->get_error_code(), implode( ',', $caps ) );
			}
			BBH_State::$caps = array( 'upload_files', 'manage_woocommerce' );
			self::assertTrue( call_user_func( $callback ) );
			BBH_State::$logged_in = false;
		}
	}

	public function test_denied_requests_are_not_counted(): void {
		Bulkbird\Helper\register_rest_routes();
		$callback                 = BBH_State::$routes[1][2][0]['permission_callback'];
		BBH_State::$logged_in     = true;
		BBH_Media_State::$user_id = 7;
		BBH_State::$caps          = array( 'read' );
		call_user_func( $callback );
		self::assertSame( array(), $GLOBALS['wpdb']->log, 'odbijeni korisnik ne troši brojač i ne upisuje ništa' );
	}

	public function test_a_request_is_counted_once_however_often_wordpress_asks(): void {
		// WordPress za zaglavlje "Allow" ponovno poziva provjere dozvola svih metoda putanje, s istim zahtjevom.
		Bulkbird\Helper\register_rest_routes();
		$media                    = BBH_State::$routes[1][2];
		BBH_State::$logged_in     = true;
		BBH_Media_State::$user_id = 7;
		BBH_State::$caps          = array( 'upload_files', 'manage_woocommerce' );

		$get = new WP_REST_Request( 'GET', '/bulkbird/v1/media' );
		self::assertTrue( call_user_func( $media[0]['permission_callback'], $get ) );
		self::assertTrue( call_user_func( $media[0]['permission_callback'], $get ) );
		self::assertTrue( call_user_func( $media[1]['permission_callback'], $get ), 'provjera metode POST za zahtjev GET' );
		self::assertSame( array( 'window_start', 'hits', 'upload_hits' ), array_keys( $GLOBALS['wpdb']->rows[7] ) );
		self::assertSame( 1, $GLOBALS['wpdb']->rows[7]['hits'], 'jedan zahtjev = jedan pogodak' );
		self::assertSame( 0, $GLOBALS['wpdb']->rows[7]['upload_hits'], 'čitanje se ne broji kao prijenos' );

		$post = new WP_REST_Request( 'POST', '/bulkbird/v1/media' );
		call_user_func( $media[1]['permission_callback'], $post );
		call_user_func( $media[0]['permission_callback'], $post );
		call_user_func( $media[1]['permission_callback'], $post );
		self::assertSame( 2, $GLOBALS['wpdb']->rows[7]['hits'] );
		self::assertSame( 1, $GLOBALS['wpdb']->rows[7]['upload_hits'], 'jedan prijenos = jedan pogodak prijenosa' );

		call_user_func( $media[0]['permission_callback'], new WP_REST_Request( 'GET', '/bulkbird/v1/media' ) );
		self::assertSame( 3, $GLOBALS['wpdb']->rows[7]['hits'], 'novi zahtjev se broji' );
	}

	public function test_media_route_arguments(): void {
		Bulkbird\Helper\register_rest_routes();
		$get  = BBH_State::$routes[1][2][0]['args'];
		$post = BBH_State::$routes[1][2][1]['args'];
		self::assertSame( array( 'page', 'per_page', 'search', 'modified_after', 'orderby' ), array_keys( $get ) );
		self::assertSame( array( 1, 100, 50 ), array( $get['per_page']['minimum'], $get['per_page']['maximum'], $get['per_page']['default'] ) );
		self::assertSame( array( 'date', 'title' ), $get['orderby']['enum'] );
		self::assertSame( 'date-time', $get['modified_after']['format'] );
		foreach ( array( '3f2b8c1e-9d4a-4b7e-8f1a-2c3d4e5f6a7b', '3F2B8C1E-9D4A-4B7E-8F1A-2C3D4E5F6A7B' ) as $uuid ) {
			self::assertSame( 1, preg_match( '/' . $post['client_upload_id']['pattern'] . '/', $uuid ), 'H2.11: velika i mala slova' );
		}
		foreach ( array( '123', 'nije-uuid', str_repeat( 'a', 36 ), '3f2b8c1e-9d4a-4b7e-8f1a-2c3d4e5f6a7b-extra', ' 3f2b8c1e-9d4a-4b7e-8f1a-2c3d4e5f6a7b' ) as $bad ) {
			self::assertSame( 0, preg_match( '/' . $post['client_upload_id']['pattern'] . '/', $bad ), $bad );
		}
		self::assertSame( 255, $post['title']['maxLength'] );
		self::assertSame( 1000, $post['alt']['maxLength'] );
	}

	public function test_h1_47_retry_after_header(): void {
		$response = new WP_REST_Response( array( 'code' => 'bulkbird_rate_limited', 'message' => 'x', 'data' => array( 'status' => 429, 'retry_after' => 17 ) ), 429 );
		$out      = Bulkbird\Helper\finish_own_responses( $response, null, new WP_REST_Request( 'GET', '/bulkbird/v1/media' ) );
		self::assertSame( '17', $out->headers['Retry-After'] );
		self::assertSame( 'no-store, private, max-age=0', $out->headers['Cache-Control'] );
	}
}
