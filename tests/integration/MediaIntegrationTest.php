<?php
/**
 * GET /media i ograničenje učestalosti unutar stvarnog WordPressa i WooCommercea
 * (H1.5, H1.7, H1.17, H1.21, H1.24, H1.28, H1.34–H1.37, H1.43, H1.47, H1.49, H1.55, H1.57, H1.59, H1.62).
 *
 * Sam prijenos (POST) se ovdje ne može izvesti: PHP prihvaća samo datoteke koje su stvarno stigle
 * HTTP-om. Pravila prijenosa pokrivaju jedinični testovi, a cijeli tok testovi stvarnim HTTP-om.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class MediaIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore
		remove_all_filters( 'bulkbird_helper_rate_limits' );
		parent::tear_down();
	}

	private function supported(): bool {
		return defined( 'WC_VERSION' ) && ! is_multisite();
	}

	private function skip_unless_supported(): void {
		if ( ! $this->supported() ) {
			$this->markTestSkipped( 'Putanje ne postoje u ovoj okolini.' );
		}
	}

	private function as_role( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/** @param array<string, mixed> $params */
	private function media( array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/bulkbird/v1/media' );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	private function image( string $title, string $date = '' ): int {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		wp_update_post( array_filter( array( 'ID' => $id, 'post_title' => $title, 'post_date_gmt' => $date, 'post_date' => $date ) ) );
		return $id;
	}

	private function other( string $mime, string $file ): int {
		return self::factory()->attachment->create(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'nije-slika',
				'file'           => $file,
			)
		);
	}

	public function test_h1_62_no_media_routes_without_woocommerce_or_on_multisite(): void {
		$has = isset( rest_get_server()->get_routes()['/bulkbird/v1/media'] );
		$this->assertSame( $this->supported(), $has );
	}

	public function test_h1_5_h1_7_both_capabilities_are_required(): void {
		$this->skip_unless_supported();
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->media()->get_status() );

		// Author ima upload_files, ali ne manage_woocommerce; customer i subscriber nemaju ni jedno.
		foreach ( array( 'author', 'editor', 'customer', 'subscriber' ) as $role ) {
			$this->as_role( $role );
			$response = $this->media();
			$this->assertSame( 403, $response->get_status(), $role );
			$this->assertSame( 'bulkbird_forbidden', $response->get_data()['code'], $role );
		}
		foreach ( array( 'shop_manager', 'administrator' ) as $role ) {
			$this->as_role( $role );
			$this->assertSame( 200, $this->media()->get_status(), $role );
		}

		$request = new WP_REST_Request( 'POST', '/bulkbird/v1/media' );
		$request->set_body_params( array( 'client_upload_id' => wp_generate_uuid4() ) );
		$this->as_role( 'author' );
		$this->assertSame( 403, rest_do_request( $request )->get_status(), 'POST: author' );
		$this->as_role( 'shop_manager' );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status(), 'POST bez datoteke' );
		$this->assertSame( 'bulkbird_missing_file', $response->get_data()['code'] );
	}

	public function test_h1_11_h1_34_to_h1_37_only_images_whole_library_plain_text(): void {
		$this->skip_unless_supported();
		$this->as_role( 'shop_manager' );

		$draft    = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$attached = $this->image( 'Vezana <b>uz</b> skicu "navodnici" šđčćž 😀' );
		wp_update_post( array( 'ID' => $attached, 'post_parent' => $draft ) );
		update_post_meta( $attached, '_wp_attachment_image_alt', 'alt <i>tekst</i>' );
		$loose = $this->image( 'Bez roditelja' );
		$this->other( 'application/pdf', 'x.pdf' );
		$this->other( 'image/svg+xml', 'x.svg' );
		$this->other( 'video/mp4', 'x.mp4' );
		$this->other( 'image/bmp', 'x.bmp' );
		$trashed = $this->image( 'U smeću' );
		wp_update_post( array( 'ID' => $trashed, 'post_status' => 'trash' ) );

		$response = $this->media( array( 'per_page' => 100 ) );
		$this->assertSame( 200, $response->get_status() );
		$items = $response->get_data();
		$ids   = wp_list_pluck( $items, 'id' );
		sort( $ids );
		$this->assertSame( array( $attached, $loose ), $ids, 'samo slike dopuštenih vrsta; bez smeća; i slika vezana uz skicu' );
		$this->assertSame( '2', (string) $response->get_headers()['X-WP-Total'] );

		$item = current( wp_list_filter( $items, array( 'id' => $attached ) ) );
		$this->assertSame( array( 'id', 'title', 'alt', 'filename', 'mime', 'width', 'height', 'filesize', 'date_gmt', 'modified_gmt', 'parent_id', 'urls' ), array_keys( $item ) );
		$this->assertSame( 'Vezana <b>uz</b> skicu "navodnici" šđčćž 😀', $item['title'], 'naslov točno kako je spremljen' );
		$this->assertSame( 'alt <i>tekst</i>', $item['alt'] );
		$this->assertSame( $draft, $item['parent_id'] );
		$this->assertSame( 'image/jpeg', $item['mime'] );
		$this->assertSame( 640, $item['width'] );
		$this->assertSame( 480, $item['height'] );
		$this->assertStringNotContainsString( '/', $item['filename'] );
		$this->assertNotSame( $item['urls']['full'], $item['urls']['thumbnail'] );
		$this->assertNotFalse( wp_json_encode( $items ) );
	}

	public function test_h1_16_h1_17_missing_sizes_and_missing_metadata(): void {
		$this->skip_unless_supported();
		$this->as_role( 'shop_manager' );
		$bare = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Bez metapodataka',
				'file'           => '2020/01/nema-na-disku.png',
			)
		);
		$item = current( wp_list_filter( $this->media()->get_data(), array( 'id' => $bare ) ) );
		$this->assertNull( $item['width'] );
		$this->assertNull( $item['height'] );
		$this->assertNull( $item['filesize'] );
		$this->assertSame( 'nema-na-disku.png', $item['filename'] );
		$this->assertSame( $item['urls']['full'], $item['urls']['thumbnail'], 'bez umanjenih veličina vraća se puna adresa, nikad prazno' );
		$this->assertSame( $item['urls']['full'], $item['urls']['medium'] );
		$this->assertNotSame( '', $item['urls']['full'] );
	}

	public function test_h1_10_h1_19_h1_21_h1_22_paging_and_ordering(): void {
		$this->skip_unless_supported();
		$this->as_role( 'shop_manager' );
		$a = $this->image( 'c-treca', '2024-01-01 10:00:00' );
		$b = $this->image( 'a-prva', '2024-01-02 10:00:00' );
		$c = $this->image( 'b-druga', '2024-01-03 10:00:00' );

		$this->assertSame( array( $c, $b, $a ), wp_list_pluck( $this->media()->get_data(), 'id' ), 'najnovije prvo' );
		$this->assertSame( array( $b, $c, $a ), wp_list_pluck( $this->media( array( 'orderby' => 'title' ) )->get_data(), 'id' ) );

		$page1 = $this->media( array( 'per_page' => 2, 'page' => 1 ) );
		$page2 = $this->media( array( 'per_page' => 2, 'page' => 2 ) );
		$this->assertSame( array( $c, $b ), wp_list_pluck( $page1->get_data(), 'id' ) );
		$this->assertSame( array( $a ), wp_list_pluck( $page2->get_data(), 'id' ) );
		$this->assertSame( '2', (string) $page1->get_headers()['X-WP-TotalPages'] );

		$beyond = $this->media( array( 'page' => 50 ) );
		$this->assertSame( 200, $beyond->get_status() );
		$this->assertSame( array(), $beyond->get_data() );
		$this->assertSame( '3', (string) $beyond->get_headers()['X-WP-Total'] );

		foreach ( array( array( 'per_page' => 0 ), array( 'per_page' => 101 ), array( 'page' => 0 ), array( 'orderby' => 'x' ), array( 'modified_after' => 'jucer' ) ) as $bad ) {
			$this->assertSame( 400, $this->media( $bad )->get_status(), wp_json_encode( $bad ) );
		}
	}

	public function test_h1_24_to_h1_28_search_and_modified_after(): void {
		$this->skip_unless_supported();
		global $wpdb;
		$this->as_role( 'shop_manager' );
		$hoodie = $this->image( 'Plava HOODIE majica' );
		$other  = $this->image( 'Nešto drugo' );

		$this->assertSame( array( $hoodie ), wp_list_pluck( $this->media( array( 'search' => 'hoodie' ) )->get_data(), 'id' ), 'po naslovu, bez razlike velikih i malih slova' );
		$this->assertCount( 2, $this->media( array( 'search' => 'canola' ) )->get_data(), 'po imenu datoteke' );
		foreach ( array( "' OR 1=1 -- ", '%', '_', '<script>', '\\' ) as $search ) {
			$response = $this->media( array( 'search' => $search ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( array(), $response->get_data(), 'pretraga "' . $search . '" je običan tekst' );
			$this->assertSame( '', $wpdb->last_error );
		}
		$this->assertCount( 2, $this->media( array( 'search' => '   ' ) )->get_data() );

		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2024-05-01 12:00:00' ), array( 'ID' => $hoodie ) );
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2024-05-01 12:00:01' ), array( 'ID' => $other ) );
		clean_post_cache( $hoodie );
		clean_post_cache( $other );
		$this->assertSame( array( $other ), wp_list_pluck( $this->media( array( 'modified_after' => '2024-05-01T12:00:00' ) )->get_data(), 'id' ), 'stroga usporedba' );
		$this->assertSame( array( $other ), wp_list_pluck( $this->media( array( 'modified_after' => '2024-05-01T14:00:00+02:00' ) )->get_data(), 'id' ), 'oznaka zone se preračunava u GMT' );
		$this->assertSame( array(), $this->media( array( 'modified_after' => '2099-01-01T00:00:00' ) )->get_data() );
	}

	public function test_h1_43_no_query_per_item(): void {
		$this->skip_unless_supported();
		global $wpdb;
		$this->as_role( 'shop_manager' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->image( 'slika-' . $i );
		}
		$this->media();
		wp_cache_flush();
		$before = $wpdb->num_queries;
		$this->media();
		$few = $wpdb->num_queries - $before;

		for ( $i = 3; $i < 15; $i++ ) {
			$this->image( 'slika-' . $i );
		}
		wp_cache_flush();
		$before = $wpdb->num_queries;
		$this->media();
		$many = $wpdb->num_queries - $before;

		$this->assertSame( $few, $many, 'broj upita ne ovisi o broju stavki' );
		$this->assertLessThanOrEqual( 12, $many );
	}

	public function test_h1_47_h1_49_h1_55_rate_limit(): void {
		$this->skip_unless_supported();
		add_filter(
			'bulkbird_helper_rate_limits',
			static fn(): array => array(
				'requests' => 3,
				'uploads'  => 1,
				'window'   => 3600,
			)
		);
		$this->as_role( 'shop_manager' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 200, $this->media()->get_status() );
		}
		$refused = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/bulkbird/v1/media' ) );
		$refused = apply_filters( 'rest_post_dispatch', rest_ensure_response( $refused ), rest_get_server(), new WP_REST_Request( 'GET', '/bulkbird/v1/media' ) );
		$this->assertSame( 429, $refused->get_status() );
		$this->assertSame( 'bulkbird_rate_limited', $refused->get_data()['code'] );
		$this->assertGreaterThan( 0, (int) $refused->get_headers()['Retry-After'] );
		$this->assertSame( 429, rest_do_request( new WP_REST_Request( 'GET', '/bulkbird/v1/info' ) )->get_status(), '/info dijeli brojač' );

		$this->as_role( 'shop_manager' );
		$this->assertSame( 200, $this->media()->get_status(), 'drugi korisnik ima svoj brojač' );

		// Odbijeni i neprijavljeni se ne broje.
		global $wpdb;
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bulkbird_rate" ); // phpcs:ignore
		$this->as_role( 'customer' );
		$this->media();
		wp_set_current_user( 0 );
		$this->media();
		$this->assertSame( $rows, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bulkbird_rate" ) ); // phpcs:ignore
		$this->assertSame( 2, $rows, 'jedan redak po korisniku' );
	}

	public function test_h1_57_h1_59_counter_table_is_created_on_first_use_and_dropped_on_uninstall(): void {
		$this->skip_unless_supported();
		global $wpdb;
		$table = $wpdb->prefix . 'bulkbird_rate';
		$image = $this->image( 'ostaje nakon deinstalacije' );
		update_post_meta( $image, '_bulkbird_upload_id', wp_generate_uuid4() );

		// Raniji testovi su tablicu već stvorili, a testni okvir je između testova vratio samo opcije
		// (stvaranje tablice se ne može poništiti transakcijom). Ovaj test kreće od stanja bez tablice.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
		delete_option( 'bulkbird_helper_db_version' );

		$this->as_role( 'shop_manager' );
		$this->media();
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'tablica postoji i ima redak korisnika' ); // phpcs:ignore
		$this->assertSame( '1', get_option( 'bulkbird_helper_db_version' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'bulkbird-helper/bulkbird-helper.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$suppress = $wpdb->suppress_errors( true );
		$this->assertNull( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'tablica je uklonjena' ); // phpcs:ignore
		$wpdb->suppress_errors( $suppress );
		$this->assertFalse( get_option( 'bulkbird_helper_db_version' ) );
		$this->assertNotNull( get_post( $image ), 'privitak ostaje' );
		$this->assertNotSame( '', get_post_meta( $image, '_bulkbird_upload_id', true ), 'meta ostaje' );
	}

	public function test_h1_47_response_429_matches_the_fixture(): void {
		$this->skip_unless_supported();
		add_filter(
			'bulkbird_helper_rate_limits',
			static fn(): array => array(
				'requests' => 1,
				'window'   => 3600,
			)
		);
		$this->as_role( 'shop_manager' );
		$this->assertSame( 200, $this->media()->get_status() );

		$request  = new WP_REST_Request( 'GET', '/bulkbird/v1/media' );
		$server   = rest_get_server();
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $server->dispatch( $request ) ), $server, $request );
		$body     = json_decode( (string) wp_json_encode( $server->response_to_data( $response, false ) ), true );

		$this->assertSame( 429, $response->get_status() );
		$retry_after = (int) $response->get_headers()['Retry-After'];
		$this->assertGreaterThanOrEqual( 1, $retry_after );
		$this->assertLessThanOrEqual( 3600, $retry_after );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );

		$file = dirname( __DIR__, 2 ) . '/fixtures/v1/error-429.json';
		if ( '1' === getenv( 'BBH_WRITE_FIXTURES' ) ) {
			file_put_contents( $file, wp_json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore
		}
		$fixture                        = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore
		$fixture['data']['retry_after'] = $retry_after;
		$this->assertSame( $fixture, $body, 'fixtures/v1/error-429.json mora biti jednak stvarnom odgovoru (osim broja sekundi)' );
	}

	/** @param mixed ...$args */
	private function controller_call( string $method, ...$args ) {
		Bulkbird\Helper\load_media_classes();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$controller = new Bulkbird\Helper\Media_Controller();
		$reflection = new ReflectionMethod( $controller, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $controller, ...$args );
	}

	private function attachment_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore
	}

	private const INSPECTED = array(
		'mime'      => 'image/jpeg',
		'extension' => 'jpg',
		'width'     => 640,
		'height'    => 480,
		'sha256'    => 'abc',
	);

	public function test_h2_30_unwritable_uploads_folder(): void {
		$this->skip_unless_supported();
		$this->as_role( 'shop_manager' );
		$before = $this->attachment_count();
		$tmp    = (string) wp_tempnam( 'bbh-test' );
		copy( DIR_TESTDATA . '/images/canola.jpg', $tmp );

		// Lažni kvar: WordPress javlja da mapu za prijenose ne može stvoriti ili u nju pisati.
		$broken = static function ( array $dirs ): array {
			$dirs['error'] = 'Unable to create directory ' . $dirs['path'] . '. Is its parent directory writable by the server?';
			return $dirs;
		};
		add_filter( 'upload_dir', $broken );
		$result = $this->controller_call(
			'store',
			array(
				'name'     => 'slika.jpg',
				'tmp_name' => $tmp,
				'size'     => (int) filesize( $tmp ),
				'error'    => 0,
				'type'     => '',
			),
			self::INSPECTED,
			wp_generate_uuid4(),
			new WP_REST_Request( 'POST', '/bulkbird/v1/media' )
		);
		remove_filter( 'upload_dir', $broken );

		$this->assertWPError( $result );
		$this->assertSame( 'bulkbird_upload_failed', $result->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $result->get_error_data() );
		$this->assertDoesNotMatchRegularExpression( '#/|uploads|directory#i', $result->get_error_message(), 'poruka bez putanje i bez pojedinosti' );
		$this->assertSame( $before, $this->attachment_count(), 'ništa polovično u biblioteci' );
		$this->assertFileExists( $tmp, 'privremena datoteka nije dirana' );
		unlink( $tmp );
	}

	public function test_h2_69_failed_attachment_leaves_no_file_and_deletes_nothing_else(): void {
		$this->skip_unless_supported();
		$this->as_role( 'shop_manager' );
		$existing      = $this->image( 'postojeća slika' );
		$existing_file = (string) get_attached_file( $existing );
		$uploads       = wp_upload_dir();
		$stored        = $uploads['path'] . '/bbh-test-netom-spremljena.jpg';
		$neighbour     = $uploads['path'] . '/bbh-test-susjed.jpg';
		copy( DIR_TESTDATA . '/images/canola.jpg', $stored );
		copy( DIR_TESTDATA . '/images/canola.jpg', $neighbour );
		$before = $this->attachment_count();

		// Lažni kvar nakon premještanja: WordPress odbija stvoriti privitak.
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$result = $this->controller_call(
			'create_attachment',
			array(
				'file' => $stored,
				'url'  => $uploads['url'] . '/bbh-test-netom-spremljena.jpg',
			),
			'bbh-test-netom-spremljena.jpg',
			self::INSPECTED,
			wp_generate_uuid4(),
			new WP_REST_Request( 'POST', '/bulkbird/v1/media' )
		);
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertWPError( $result );
		$this->assertSame( 'bulkbird_upload_failed', $result->get_error_code() );
		$this->assertFileDoesNotExist( $stored, 'netom spremljena datoteka ne ostaje' );
		$this->assertFileExists( $neighbour, 'susjedna datoteka nije dirana' );
		$this->assertFileExists( $existing_file, 'datoteka postojećeg privitka nije dirana' );
		$this->assertNotNull( get_post( $existing ) );
		$this->assertSame( $before, $this->attachment_count() );
		unlink( $neighbour );

		// Bez kvara isti korak stvara privitak i ništa ne briše.
		copy( DIR_TESTDATA . '/images/canola.jpg', $stored );
		$upload_id = wp_generate_uuid4();
		$created   = $this->controller_call(
			'create_attachment',
			array(
				'file' => $stored,
				'url'  => $uploads['url'] . '/bbh-test-netom-spremljena.jpg',
			),
			'bbh-test-netom-spremljena.jpg',
			self::INSPECTED,
			$upload_id,
			new WP_REST_Request( 'POST', '/bulkbird/v1/media' )
		);
		$this->assertIsInt( $created );
		$this->assertFileExists( $stored );
		$this->assertSame( $upload_id, get_post_meta( $created, '_bulkbird_upload_id', true ) );
		$this->assertSame( 0, (int) get_post( $created )->post_parent );
		$this->assertSame( $before + 1, $this->attachment_count() );
	}
}
