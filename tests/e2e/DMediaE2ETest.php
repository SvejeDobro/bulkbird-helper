<?php
/**
 * Faze 1 i 2: GET i POST /bulkbird/v1/media stvarnim HTTP zahtjevima (scenariji H1.x i H2.x).
 *
 * Prijenosi stvaraju stvarne privitke na testnoj stranici. Svi imaju naslov "bbh-test-…" i meta
 * _bulkbird_upload_id; na kraju ih briše tests/tools/cleanup-test-media.php (trajno odobrenje P17).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class DMediaE2ETest extends E2ECase {

	private const MEDIA  = '/wp-json/bulkbird/v1/media';
	private const FIELDS = array( 'id', 'title', 'alt', 'filename', 'mime', 'width', 'height', 'filesize', 'date_gmt', 'modified_gmt', 'parent_id', 'urls' );

	/** @var array<string, mixed> */
	private static array $before = array();

	/** @var string[] */
	private static array $tmp = array();

	public static function setUpBeforeClass(): void {
		self::cleanup_test_media();
		self::$before = self::media_probe();
	}

	public static function tearDownAfterClass(): void {
		self::cleanup_test_media();
		foreach ( self::$tmp as $file ) {
			@unlink( $file );
		}
	}

	// --- Pomoćne funkcije ---------------------------------------------------------------------

	private static function uuid(): string {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
	}

	private static function tmp( string $content, string $suffix = '' ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'bbhe2e' ) . $suffix;
		file_put_contents( $path, $content );
		self::$tmp[] = $path;
		return $path;
	}

	/** Slika s nasumičnim sadržajem (svaki poziv daje drugu datoteku). */
	private static function image( string $type = 'jpeg', int $width = 800, int $height = 600 ): string {
		$image = imagecreatetruecolor( $width, $height );
		for ( $i = 0; $i < 40; $i++ ) {
			imagefilledrectangle( $image, random_int( 0, $width ), random_int( 0, $height ), random_int( 0, $width ), random_int( 0, $height ), imagecolorallocate( $image, random_int( 0, 255 ), random_int( 0, 255 ), random_int( 0, 255 ) ) );
		}
		ob_start();
		if ( 'png' === $type ) {
			imagepng( $image );
		} elseif ( 'gif' === $type ) {
			imagegif( $image );
		} elseif ( 'webp' === $type ) {
			imagewebp( $image );
		} else {
			imagejpeg( $image, null, 85 );
		}
		return self::tmp( (string) ob_get_clean() );
	}

	/**
	 * @param array<string, string> $fields
	 * @return array{status: int, headers: array<string, string>, body: string, json: mixed, seconds: float}
	 */
	private static function post( ?string $path, string $send_as, array $fields, string $key = 'K_RW', array $extra = array() ): array {
		$body = $fields;
		if ( null !== $path ) {
			$body['file'] = new CURLFile( $path, 'application/octet-stream', $send_as );
		}
		return self::http( 'POST', self::url( self::MEDIA ), array_merge( array( 'basic' => self::key( $key ), 'body' => $body ), $extra ) );
	}

	private static function get( string $query = '', string $key = 'K_READ' ): array {
		return self::http( 'GET', self::url( self::MEDIA . $query ), array( 'basic' => self::key( $key ) ) );
	}

	private static function total(): int {
		return (int) self::get( '?per_page=1' )['headers']['x-wp-total'];
	}

	private static function assert_error( array $response, int $status, string $code ): void {
		self::assertSame( $status, $response['status'], self::describe( $response ) );
		self::assertSame( $code, $response['json']['code'] ?? null, self::describe( $response ) );
		self::assertDoesNotMatchRegularExpression( '#/home/|/tmp/|\.php\b|Stack trace|wp-content#i', $response['body'], 'greška ne smije otkriti putanje' );
	}

	// --- GET /media: dozvole (H1.1–H1.9) ------------------------------------------------------

	public function test_h1_1_h1_18_list_with_totals(): void {
		$response = self::get();
		self::assertSame( 200, $response['status'], self::describe( $response ) );
		self::assertSame( (string) self::$before['image_count'], $response['headers']['x-wp-total'] );
		self::assertSame( (string) (int) ceil( self::$before['image_count'] / 50 ), $response['headers']['x-wp-totalpages'] );
		self::assertCount( min( 50, self::$before['image_count'] ), $response['json'] );
	}

	public function test_h1_2_to_h1_5_permissions(): void {
		self::assertSame( 200, self::get( '?per_page=1', 'K_RW' )['status'] );
		self::assert_error( self::get( '', 'K_WRITE' ), 401, 'woocommerce_rest_authentication_error' );
		self::assert_error( self::http( 'GET', self::url( self::MEDIA ) ), 401, 'bulkbird_unauthorized' );
		self::assert_error( self::http( 'GET', self::url( self::MEDIA ), array( 'basic' => array( 'ck_' . str_repeat( 'a', 40 ), 'cs_' . str_repeat( 'b', 40 ) ) ) ), 401, 'bulkbird_unauthorized' );
		$customer = self::get( '', 'K_CUST' );
		self::assert_error( $customer, 403, 'bulkbird_forbidden' );
		self::assertStringNotContainsString( '"urls"', $customer['body'] );
	}

	public function test_h1_6_cookie_and_nonce(): void {
		$session = self::session( array( 'BBH_USER_ID' => self::cfg( 'SHOPMGR_ID' ) ) );
		self::assertSame( 200, self::http( 'GET', self::url( self::MEDIA . '?per_page=1' ), array( 'headers' => array( 'Cookie: ' . $session['cookie'], 'X-WP-Nonce: ' . $session['nonce'] ) ) )['status'] );
		self::assert_error( self::http( 'GET', self::url( self::MEDIA ), array( 'headers' => array( 'Cookie: ' . $session['cookie'] ) ) ), 401, 'bulkbird_unauthorized' );
	}

	public function test_h1_8_rest_route_form(): void {
		// Ključ u adresi se stvarnim HTTP-om više ne izvodi (secret bi ostao u zapisima poslužitelja).
		$direct     = self::get( '?per_page=3' );
		$rest_route = self::http( 'GET', self::url( '/?rest_route=/bulkbird/v1/media&per_page=3' ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assertSame( 200, $rest_route['status'], self::describe( $rest_route ) );
		self::assertSame( $direct['json'], $rest_route['json'] );
	}

	public function test_h2_70_not_signed_in_or_not_allowed_wins_over_an_invalid_parameter(): void {
		if ( version_compare( (string) self::site()['plugin_version'], '0.2.1', '<' ) ) {
			self::markTestSkipped( 'Na stranici je plugin stariji od 0.2.1.' );
		}
		$bad_post = array( 'body' => array( 'client_upload_id' => 'abc' ) );
		self::assert_error( self::http( 'POST', self::url( self::MEDIA ), $bad_post ), 401, 'bulkbird_unauthorized' );
		self::assert_error( self::http( 'POST', self::url( self::MEDIA ), $bad_post + array( 'basic' => self::key( 'K_CUST' ) ) ), 403, 'bulkbird_forbidden' );
		self::assert_error( self::http( 'POST', self::url( self::MEDIA ), $bad_post + array( 'basic' => self::key( 'K_READ' ) ) ), 401, 'woocommerce_rest_authentication_error' );
		self::assertSame( 'rest_invalid_param', self::http( 'POST', self::url( self::MEDIA ), $bad_post + array( 'basic' => self::key( 'K_RW' ) ) )['json']['code'] );

		self::assert_error( self::http( 'GET', self::url( self::MEDIA . '?per_page=abc' ) ), 401, 'bulkbird_unauthorized' );
		self::assert_error( self::http( 'GET', self::url( self::MEDIA . '?per_page=abc' ), array( 'basic' => self::key( 'K_CUST' ) ) ), 403, 'bulkbird_forbidden' );
		self::assertSame( 'rest_invalid_param', self::http( 'GET', self::url( self::MEDIA . '?per_page=abc' ), array( 'basic' => self::key( 'K_READ' ) ) )['json']['code'] );
	}

	// --- GET /media: sadržaj (H1.10–H1.42) ----------------------------------------------------

	public function test_h1_10_default_page_newest_first(): void {
		$items = self::get()['json'];
		for ( $i = 1, $n = count( $items ); $i < $n; $i++ ) {
			$previous = $items[ $i - 1 ];
			$current  = $items[ $i ];
			self::assertTrue(
				$previous['date_gmt'] > $current['date_gmt'] || ( $previous['date_gmt'] === $current['date_gmt'] && $previous['id'] > $current['id'] ),
				'najnovije prvo; pri istom datumu veći id prvi'
			);
		}
	}

	public function test_h1_11_h1_12_h1_14_item_shape(): void {
		foreach ( self::get( '?per_page=100' )['json'] as $item ) {
			self::assertSame( self::FIELDS, array_keys( $item ) );
			self::assertSame( array( 'thumbnail', 'medium', 'full' ), array_keys( $item['urls'] ) );
			self::assertIsInt( $item['id'] );
			self::assertIsInt( $item['parent_id'] );
			foreach ( array( 'width', 'height', 'filesize' ) as $field ) {
				self::assertTrue( null === $item[ $field ] || ( is_int( $item[ $field ] ) && $item[ $field ] > 0 ), $field );
			}
			foreach ( array( 'title', 'alt', 'filename', 'mime' ) as $field ) {
				self::assertIsString( $item[ $field ], $field );
			}
			self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $item['date_gmt'] );
			self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $item['modified_gmt'] );
			self::assertStringNotContainsString( '/', $item['filename'], 'H1.14: samo ime datoteke' );
			self::assertContains( $item['mime'], array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ), 'H1.34' );
			foreach ( $item['urls'] as $url ) {
				self::assertStringStartsWith( 'https://', $url );
			}
		}
	}

	public function test_h1_13_values_match_the_database(): void {
		$items = self::get( '?per_page=20&page=2' )['json'];
		$probe = self::media_probe( array_column( $items, 'id' ) )['items'];
		foreach ( $items as $item ) {
			$row = $probe[ $item['id'] ];
			self::assertSame( $row['title'], $item['title'] );
			self::assertSame( $row['mime'], $item['mime'] );
			self::assertSame( $row['parent'], $item['parent_id'] );
			self::assertSame( $row['alt'], $item['alt'] );
			self::assertSame( $row['basename'], $item['filename'] );
			self::assertSame( $row['width'], (int) $item['width'] );
		}
	}

	public function test_h1_15_urls_serve_images(): void {
		$item = self::get( '?per_page=1&page=3' )['json'][0];
		foreach ( $item['urls'] as $size => $url ) {
			$response = self::http( 'GET', $url );
			self::assertSame( 200, $response['status'], $size );
			self::assertStringStartsWith( 'image/', $response['headers']['content-type'] ?? '', $size );
		}
	}

	public function test_h1_19_pages_cover_everything_exactly_once(): void {
		$total = self::$before['image_count'];
		$ids   = array();
		for ( $page = 1; $page <= (int) ceil( $total / 100 ); $page++ ) {
			$response = self::get( '?per_page=100&page=' . $page );
			self::assertSame( 200, $response['status'] );
			$ids = array_merge( $ids, array_column( $response['json'], 'id' ) );
		}
		self::assertCount( $total, $ids );
		self::assertCount( $total, array_unique( $ids ), 'stranice se ne preklapaju' );
		self::assertCount( 1, self::get( '?per_page=1' )['json'] );
	}

	public function test_h1_20_h1_23_h1_30_invalid_parameters(): void {
		foreach ( array( '?per_page=0', '?per_page=101', '?per_page=abc', '?page=0', '?page=-1', '?orderby=nepoznato', '?modified_after=jucer', '?modified_after=2026-13-40T00:00:00', '?search=' . str_repeat( 'x', 201 ) ) as $query ) {
			self::assert_error( self::get( $query ), 400, 'rest_invalid_param' );
		}
	}

	public function test_h1_21_page_beyond_the_last(): void {
		$response = self::get( '?page=9999' );
		self::assertSame( 200, $response['status'] );
		self::assertSame( array(), $response['json'] );
		self::assertSame( (string) self::$before['image_count'], $response['headers']['x-wp-total'] );
	}

	public function test_h1_22_order_by_title(): void {
		$response = self::get( '?orderby=title&per_page=100' );
		self::assertSame( 200, $response['status'] );
		self::assertCount( min( 100, self::$before['image_count'] ), $response['json'] );
		self::assertNotSame( array_column( self::get( '?per_page=100' )['json'], 'id' ), array_column( $response['json'], 'id' ), 'drugi redoslijed nego po datumu' );
		$titles = array_map( 'strtolower', array_column( $response['json'], 'title' ) );
		self::assertLessThanOrEqual( 0, strcmp( preg_replace( '/[^a-z0-9]/', '', $titles[0] ), preg_replace( '/[^a-z0-9]/', '', end( $titles ) ) ), 'prvi naslov nije iza zadnjeg' );
	}

	public function test_h1_24_h1_25_search_in_title_and_file_name(): void {
		$sample = self::get( '?per_page=1&page=5' )['json'][0];
		$needle = substr( pathinfo( $sample['filename'], PATHINFO_FILENAME ), 0, 5 );
		$found  = self::get( '?per_page=100&search=' . rawurlencode( strtoupper( $needle ) ) );
		self::assertSame( 200, $found['status'] );
		self::assertGreaterThan( 0, (int) $found['headers']['x-wp-total'] );
		self::assertContains( $sample['id'], array_column( $found['json'], 'id' ), 'pretraga bez razlike velikih i malih slova nalazi sliku' );
		foreach ( $found['json'] as $item ) {
			self::assertTrue( false !== stripos( $item['title'], $needle ) || false !== stripos( $item['filename'], $needle ) );
		}
		// Mapa u putanji datoteke (npr. godina) nije dio imena i ne smije dati pogodak.
		self::assertSame( '0', self::get( '?search=' . rawurlencode( 'uploads' ) )['headers']['x-wp-total'] );
	}

	public function test_h1_26_h1_27_search_is_plain_text(): void {
		foreach ( array( "' OR 1=1 -- ", '%', '_', '"', '<script>alert(1)</script>', '\\', "'; DROP TABLE wp_posts; --" ) as $search ) {
			$response = self::get( '?search=' . rawurlencode( $search ) );
			self::assertSame( 200, $response['status'], $search );
			self::assertLessThan( self::$before['image_count'], (int) $response['headers']['x-wp-total'], 'pretraga "' . $search . '" ne smije vratiti cijelu biblioteku' );
		}
		self::assertSame( (string) self::$before['image_count'], self::get( '?search=' . rawurlencode( '   ' ) )['headers']['x-wp-total'], 'prazna pretraga se ignorira' );
		self::assertSame( self::$before['image_count'], self::media_probe()['image_count'], 'biblioteka netaknuta' );
	}

	public function test_h1_28_h1_29_h1_31_modified_after(): void {
		self::assertSame( '0', self::get( '?modified_after=2099-01-01T00:00:00' )['headers']['x-wp-total'] );
		self::assertSame( (string) self::$before['image_count'], self::get( '?modified_after=2000-01-01T00:00:00' )['headers']['x-wp-total'] );
		self::assertSame( (string) self::$before['image_count'], self::get( '?modified_after=' . rawurlencode( '2000-01-01T00:00:00Z' ) )['headers']['x-wp-total'] );
		self::assertSame( (string) self::$before['image_count'], self::get( '?modified_after=' . rawurlencode( '2000-01-01T00:00:00+01:00' ) )['headers']['x-wp-total'] );

		// Stroga usporedba: granica točno na najnovijem datumu izmjene ne vraća tu sliku.
		$newest = max( array_column( self::get( '?per_page=100' )['json'], 'modified_gmt' ) );
		$after  = self::get( '?per_page=100&modified_after=' . rawurlencode( $newest ) );
		foreach ( $after['json'] as $item ) {
			self::assertGreaterThan( $newest, $item['modified_gmt'] );
		}
	}

	public function test_h1_38_h1_39_h1_40_h1_41_no_personal_data_head_and_headers(): void {
		$response = self::get( '?per_page=100&foo=1&author=1' );
		self::assertSame( 200, $response['status'] );
		self::assertDoesNotMatchRegularExpression( '#/home/|htdocs|"author"|user_login|user_email|display_name|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}#i', $response['body'] );
		self::assertMatchesRegularExpression( '/no-store|no-cache/', $response['headers']['cache-control'] ?? '' );
		self::assertArrayNotHasKey( 'set-cookie', $response['headers'] );

		$head = self::http( 'HEAD', self::url( self::MEDIA ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assertSame( 200, $head['status'] );
		self::assertSame( '', $head['body'] );
		self::assertSame( (string) self::$before['image_count'], $head['headers']['x-wp-total'] );
	}

	// --- POST /media: uspjeh i idempotentnost (H2.1, H2.31–H2.48) ------------------------------

	public function test_h2_1_upload_and_everything_that_follows_from_it(): void {
		$total  = self::total();
		$file   = self::image( 'jpeg' );
		$id     = self::uuid();
		$fields = array( 'client_upload_id' => $id, 'title' => 'bbh-test-<b>prva</b> šđčćž', 'alt' => 'bbh-test alt <i>tekst</i>' );

		$created = self::post( $file, 'bbh-test-prva.jpg', $fields );
		self::assertSame( 201, $created['status'], self::describe( $created ) );
		$item = $created['json'];
		self::assertSame( self::FIELDS, array_keys( $item ) );
		self::assertSame( 'bbh-test-prva šđčćž', $item['title'], 'H2.33: HTML uklonjen, hrvatski znakovi sačuvani' );
		self::assertSame( 'bbh-test alt tekst', $item['alt'] );
		self::assertSame( 'image/jpeg', $item['mime'] );
		self::assertSame( array( 800, 600 ), array( $item['width'], $item['height'] ) );
		self::assertSame( 0, $item['parent_id'], 'H2.36' );
		self::assertSame( filesize( $file ), $item['filesize'] );
		self::assertNotSame( $item['urls']['full'], $item['urls']['thumbnail'], 'H2.32: umanjena veličina postoji' );
		self::assertNotSame( $item['urls']['full'], $item['urls']['medium'] );
		self::assertMatchesRegularExpression( '/no-store|no-cache/', $created['headers']['cache-control'] ?? '' );
		self::assertSame( $total + 1, self::total() );

		// H2.31, H2.48: ista stavka kroz GET.
		$listed = self::get( '?search=' . rawurlencode( 'bbh-test-prva' ) );
		self::assertSame( array( $item ), $listed['json'] );

		// H2.37, H2.38 i stanje na disku.
		$row = self::media_probe( array( $item['id'] ) )['items'][ $item['id'] ];
		self::assertSame( $id, $row['upload_id'] );
		self::assertTrue( $row['sha256_ok'] );
		self::assertTrue( $row['file_matches'], 'spremljena datoteka je bajt po bajt poslana datoteka' );
		self::assertTrue( $row['in_uploads'] );
		self::assertSame( (int) self::cfg( 'SHOPMGR_ID' ), $row['author'] );
		self::assertSame( 0, $row['parent'] );
		self::assertSame( 0, $row['used_by'], 'slika nije dodijeljena nijednom proizvodu' );
		self::assertContains( 'thumbnail', $row['sizes'] );

		// H2.39, H2.40: ponavljanje.
		for ( $i = 0; $i < 3; $i++ ) {
			$again = self::post( $file, 'bbh-test-prva.jpg', $fields );
			self::assertSame( 200, $again['status'], self::describe( $again ) );
			self::assertSame( $item, $again['json'] );
		}
		// H2.43: drugi naslov uz isti id i istu datoteku ne uređuje postojeću sliku.
		$retitled = self::post( $file, 'drugo-ime.jpg', array( 'client_upload_id' => $id, 'title' => 'bbh-test-drugi naslov', 'alt' => 'drugi alt' ) );
		self::assertSame( 200, $retitled['status'] );
		self::assertSame( $item, $retitled['json'] );
		// H2.11: UUID velikim slovima je isti UUID.
		self::assertSame( 200, self::post( $file, 'bbh-test-prva.jpg', array( 'client_upload_id' => strtoupper( $id ) ) )['status'] );
		self::assertSame( $total + 1, self::total(), 'i dalje samo jedna nova slika' );

		// H2.42: isti id, druga datoteka.
		$conflict = self::post( self::image( 'jpeg' ), 'bbh-test-druga.jpg', array( 'client_upload_id' => $id, 'title' => 'bbh-test-druga' ) );
		self::assert_error( $conflict, 409, 'bulkbird_upload_id_conflict' );
		self::assertSame( $total + 1, self::total() );
		self::assertSame( array( $item ), self::get( '?search=' . rawurlencode( 'bbh-test-prva' ) )['json'], 'postojeća slika nepromijenjena' );

		// H2.44: ista datoteka, novi id → nova slika s drugim imenom datoteke.
		$copy = self::post( $file, 'bbh-test-prva.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-prva kopija' ) );
		self::assertSame( 201, $copy['status'] );
		self::assertNotSame( $item['id'], $copy['json']['id'] );
		self::assertNotSame( $item['filename'], $copy['json']['filename'] );
		self::assertSame( $total + 2, self::total() );
	}

	public function test_h2_34_title_defaults_to_the_file_name(): void {
		$response = self::post( self::image(), 'bbh-test-bez-naslova.jpg', array( 'client_upload_id' => self::uuid() ) );
		self::assertSame( 201, $response['status'], self::describe( $response ) );
		self::assertSame( 'bbh-test-bez-naslova', $response['json']['title'] );
		self::assertSame( '', $response['json']['alt'] );
	}

	public function test_h2_2_to_h2_6_permissions(): void {
		$total = self::total();
		self::assertSame( 201, self::post( self::image(), 'bbh-test-write.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-write' ), 'K_WRITE' )['status'], 'H2.2: ključ samo za pisanje smije prenijeti' );

		self::assert_error( self::post( self::image(), 'bbh-test-read.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-read' ), 'K_READ' ), 401, 'woocommerce_rest_authentication_error' );
		self::assert_error( self::post( self::image(), 'bbh-test-cust.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-cust' ), 'K_CUST' ), 403, 'bulkbird_forbidden' );
		$anonymous = self::http( 'POST', self::url( self::MEDIA ), array( 'body' => array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-anon', 'file' => new CURLFile( self::image(), 'image/jpeg', 'bbh-test-anon.jpg' ) ) ) );
		self::assert_error( $anonymous, 401, 'bulkbird_unauthorized' );

		$session = self::session( array( 'BBH_USER_ID' => self::cfg( 'SHOPMGR_ID' ) ) );
		$cookie  = self::http( 'POST', self::url( self::MEDIA ), array( 'headers' => array( 'Cookie: ' . $session['cookie'], 'X-WP-Nonce: ' . $session['nonce'] ), 'body' => array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-cookie', 'file' => new CURLFile( self::image(), 'image/jpeg', 'bbh-test-cookie.jpg' ) ) ) );
		self::assertSame( 201, $cookie['status'], self::describe( $cookie ) );

		self::assertSame( $total + 2, self::total(), 'spremljene su samo dvije dopuštene slike' );
	}

	public function test_h2_7_to_h2_12_request_shape(): void {
		$total = self::total();
		self::assert_error( self::post( null, '', array( 'client_upload_id' => self::uuid() ) ), 400, 'bulkbird_missing_file' );
		$two = self::http( 'POST', self::url( self::MEDIA ), array( 'basic' => self::key( 'K_RW' ), 'body' => array( 'client_upload_id' => self::uuid(), 'file' => new CURLFile( self::image(), 'image/jpeg', 'bbh-test-a.jpg' ), 'file2' => new CURLFile( self::image(), 'image/jpeg', 'bbh-test-b.jpg' ) ) ) );
		self::assert_error( $two, 400, 'bulkbird_single_file_only' );
		self::assert_error( self::post( self::image(), 'bbh-test-x.jpg', array( 'title' => 'bbh-test-x' ) ), 400, 'rest_missing_callback_param' );
		foreach ( array( '123', 'nije-uuid', str_repeat( 'a', 36 ), '3f2b8c1e-9d4a-4b7e-8f1a-2c3d4e5f6a7b-extra' ) as $bad ) {
			self::assert_error( self::post( self::image(), 'bbh-test-x.jpg', array( 'client_upload_id' => $bad, 'title' => 'bbh-test-x' ) ), 400, 'rest_invalid_param' );
		}
		$json = self::http( 'POST', self::url( self::MEDIA ), array( 'basic' => self::key( 'K_RW' ), 'headers' => array( 'Content-Type: application/json' ), 'body' => (string) json_encode( array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-json' ) ) ) );
		self::assert_error( $json, 400, 'bulkbird_missing_file' );
		// H2.35: predugi naslov i alt.
		self::assert_error( self::post( self::image(), 'bbh-test-x.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-' . str_repeat( 'x', 300 ) ) ), 400, 'rest_invalid_param' );
		self::assert_error( self::post( self::image(), 'bbh-test-x.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-x', 'alt' => str_repeat( 'x', 1001 ) ) ), 400, 'rest_invalid_param' );
		self::assertSame( $total, self::total(), 'ništa nije spremljeno' );
	}

	// --- POST /media: vrsta i sadržaj (H2.14–H2.23) --------------------------------------------

	public function test_h2_14_png_gif_webp(): void {
		foreach ( array( 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ) as $type => $mime ) {
			$response = self::post( self::image( $type, 400, 300 ), 'bbh-test-vrsta.' . $type, array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-vrsta-' . $type ) );
			self::assertSame( 201, $response['status'], $type . ': ' . self::describe( $response ) );
			self::assertSame( $mime, $response['json']['mime'] );
			self::assertStringEndsWith( '.' . $type, $response['json']['filename'] );
		}
	}

	public function test_h2_15_h2_16_avif(): void {
		if ( ! class_exists( 'Imagick' ) || ! in_array( 'AVIF', Imagick::queryFormats( 'AVIF' ), true ) ) {
			self::markTestSkipped( 'Na ovom računalu se ne može izraditi AVIF datoteka za test.' );
		}
		$source = new Imagick( self::image( 'png', 400, 300 ) );
		$source->setImageFormat( 'avif' );
		$avif     = self::tmp( $source->getImageBlob() );
		$total    = self::total();
		$response = self::post( $avif, 'bbh-test-avif.avif', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-avif' ) );
		if ( 'NEW' === self::target() ) {
			self::assertSame( 201, $response['status'], self::describe( $response ) );
			self::assertSame( 'image/avif', $response['json']['mime'] );
		} else {
			self::assert_error( $response, 415, 'bulkbird_unsupported_type' );
			self::assertSame( $total, self::total() );
		}
	}

	public function test_h2_17_h2_18_h2_19_h2_23_refused_content(): void {
		$total = self::total();
		$svg   = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
		$cases = array(
			'svg'             => array( $svg, 'bbh-test-x.svg' ),
			'svg kao jpg'     => array( $svg, 'bbh-test-x.jpg' ),
			'svg kao png'     => array( $svg, 'bbh-test-x.png' ),
			'php kao jpg'     => array( '<?php echo "bbh"; ?>', 'bbh-test-x.jpg' ),
			'php'             => array( '<?php echo "bbh"; ?>', 'bbh-test-x.php' ),
			'pdf'             => array( "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n", 'bbh-test-x.pdf' ),
			'zip'             => array( "PK\x03\x04" . str_repeat( "\0", 30 ), 'bbh-test-x.zip' ),
			'html'            => array( '<html><body><h1>x</h1></body></html>', 'bbh-test-x.html' ),
			'tekst kao gif'   => array( 'obican tekst', 'bbh-test-x.gif' ),
			'bmp'             => array( "BM" . str_repeat( "\0", 60 ), 'bbh-test-x.bmp' ),
		);
		foreach ( $cases as $name => $case ) {
			$response = self::post( self::tmp( $case[0] ), $case[1], array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-odbijeno' ) );
			self::assert_error( $response, 415, 'bulkbird_unsupported_type' );
		}
		$empty = self::post( self::tmp( '' ), 'bbh-test-prazna.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-prazna' ) );
		self::assertContains( $empty['status'], array( 400, 415 ), self::describe( $empty ) );
		self::assertSame( $total, self::total(), 'ništa od odbijenog nije spremljeno' );
	}

	public function test_h2_20_h2_21_name_does_not_decide_and_is_made_safe(): void {
		$png = self::post( self::image( 'png', 300, 200 ), 'bbh-test-zapravo-png.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-zapravo-png' ) );
		self::assertSame( 201, $png['status'], self::describe( $png ) );
		self::assertSame( 'image/png', $png['json']['mime'] );
		self::assertStringEndsWith( '.png', $png['json']['filename'] );

		$ids = array();
		foreach ( array( 'napad.php.jpg', '../../../bbh-izvan.jpg', 'C:\\Users\\x\\slika.jpg', 'ime s razmacima šđčćž.JPG', str_repeat( 'd', 300 ) . '.jpg', '.htaccess' ) as $name ) {
			$response = self::post( self::image( 'jpeg', 300, 200 ), $name, array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-ime' ) );
			self::assertSame( 201, $response['status'], $name . ': ' . self::describe( $response ) );
			self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+\.jpg$/', $response['json']['filename'], $name );
			self::assertStringNotContainsString( '.php', $response['json']['filename'] );
			$ids[] = $response['json']['id'];
		}
		foreach ( self::media_probe( $ids )['items'] as $row ) {
			self::assertTrue( $row['in_uploads'], 'datoteka je u mapi za prijenose' );
		}
	}

	public function test_h2_22_jpeg_with_appended_php_is_stored_and_served_as_an_image(): void {
		$file = self::image( 'jpeg', 300, 200 );
		file_put_contents( $file, '<?php echo "BBH_EXECUTED_" . (6*7); ?>', FILE_APPEND );
		$response = self::post( $file, 'bbh-test-poliglot.php.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-poliglot' ) );
		self::assertSame( 201, $response['status'], self::describe( $response ) );
		self::assertStringEndsWith( '.jpg', $response['json']['filename'] );
		$served = self::http( 'GET', $response['json']['urls']['full'] );
		self::assertSame( 200, $served['status'] );
		self::assertStringStartsWith( 'image/jpeg', $served['headers']['content-type'] ?? '' );
		self::assertStringNotContainsString( 'BBH_EXECUTED_42', $served['body'], 'kod u slici se ne izvršava' );
	}

	// --- POST /media: veličina (H2.24–H2.28) ---------------------------------------------------

	public function test_h2_24_h2_25_size_limit(): void {
		$limit = (int) self::http( 'GET', self::url( '/wp-json/bulkbird/v1/info' ), array( 'basic' => self::key( 'K_READ' ) ) )['json']['media']['upload_max_bytes'];
		$total = self::total();

		$jpeg   = (string) file_get_contents( self::image( 'jpeg', 300, 200 ) );
		$at     = self::tmp( $jpeg . str_repeat( "\0", $limit - strlen( $jpeg ) ) );
		$over   = self::tmp( $jpeg . str_repeat( "\0", $limit - strlen( $jpeg ) + 1 ) );
		self::assertSame( $limit, filesize( $at ) );

		$too_large = self::post( $over, 'bbh-test-prevelika.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-prevelika' ) );
		self::assert_error( $too_large, 413, 'bulkbird_file_too_large' );
		self::assertSame( $total, self::total() );

		$on_limit = self::post( $at, 'bbh-test-na-granici.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-na-granici' ) );
		self::assertSame( 201, $on_limit['status'], self::describe( $on_limit ) );
		self::assertSame( $limit, $on_limit['json']['filesize'] );
	}

	public function test_h2_26_h2_27_body_larger_than_php_accepts(): void {
		$info     = self::http( 'GET', self::url( '/wp-json/bulkbird/v1/info' ), array( 'basic' => self::key( 'K_READ' ) ) )['json'];
		$size     = (int) $info['max_upload_bytes'] + 4 * 1024 * 1024;
		$total    = self::total();
		$response = self::post( self::tmp( str_repeat( "\0", $size ) ), 'bbh-test-golema.jpg', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-golema' ) );
		self::assertSame( 413, $response['status'], 'zahtjev veći od granice PHP-a mora završiti s 413 (' . self::describe( $response ) . ')' );
		fwrite( STDERR, sprintf( "\n[H2.26 %s] %d MB: 413, tijelo %s\n", self::target(), $size / 1048576, is_array( $response['json'] ) ? 'JSON plugina (' . ( $response['json']['code'] ?? '?' ) . ')' : 'nije JSON (odbio poslužitelj ispred WordPressa)' ) );
		self::assertSame( $total, self::total() );
	}

	public function test_h2_28_huge_dimensions(): void {
		$ihdr     = pack( 'NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0 );
		$png      = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) );
		$total    = self::total();
		$response = self::post( self::tmp( $png ), 'bbh-test-dimenzije.png', array( 'client_upload_id' => self::uuid(), 'title' => 'bbh-test-dimenzije' ) );
		self::assert_error( $response, 400, 'bulkbird_image_too_large' );
		self::assertSame( $total, self::total() );
	}

	// --- GET /media/uploads/<client_upload_id> (0.3.0; H2.71–H2.79) ------------------------------

	public function test_h2_72_to_h2_78_upload_status_by_reading(): void {
		if ( version_compare( (string) self::site()['plugin_version'], '0.3.0', '<' ) ) {
			self::markTestSkipped( 'Na stranici je plugin stariji od 0.3.0.' );
		}
		$status = static fn( string $id, string $key = 'K_READ' ): array => self::http( 'GET', self::url( self::MEDIA . '/uploads/' . $id ), array( 'basic' => self::key( $key ) ) );

		$id   = self::uuid();
		$file = self::image( 'jpeg', 640, 480 );

		// Prije prijenosa: 404 vlastitim kodom (ne "nema putanje").
		self::assert_error( $status( $id ), 404, 'bulkbird_upload_not_found' );

		$created = self::post( $file, 'bbh-test-stanje.jpg', array( 'client_upload_id' => $id, 'title' => 'bbh-test-stanje' ) );
		self::assertSame( 201, $created['status'], self::describe( $created ) );
		$total = self::total();

		// Ključ samo za čitanje smije provjeriti ishod.
		$stored = $status( $id );
		self::assertSame( 200, $stored['status'], self::describe( $stored ) );
		self::assertSame( array( 'client_upload_id', 'state', 'sha256', 'item' ), array_keys( $stored['json'] ) );
		self::assertSame( array( $id, 'stored', hash_file( 'sha256', $file ) ), array( $stored['json']['client_upload_id'], $stored['json']['state'], $stored['json']['sha256'] ) );
		self::assertSame( $created['json'], $stored['json']['item'], 'ista stavka kao u odgovoru na POST' );
		self::assertMatchesRegularExpression( '/no-store|no-cache/', $stored['headers']['cache-control'] ?? '' );
		self::assertSame( $stored['json'], $status( strtoupper( $id ) )['json'], 'velika slova' );

		// Dozvole i neispravan ID.
		self::assert_error( self::http( 'GET', self::url( self::MEDIA . '/uploads/' . $id ) ), 401, 'bulkbird_unauthorized' );
		self::assert_error( $status( $id, 'K_CUST' ), 403, 'bulkbird_forbidden' );
		self::assert_error( $status( 'abc' ), 400, 'rest_invalid_param' );
		self::assert_error( self::http( 'GET', self::url( self::MEDIA . '/uploads/abc' ) ), 401, 'bulkbird_unauthorized' );

		// Čitanje ništa ne mijenja.
		self::assertSame( $total, self::total() );
		self::assertSame( $created['json']['modified_gmt'], $status( $id )['json']['item']['modified_gmt'] );
	}

	// --- POST /media: istovremeni zahtjevi (H2.41) ---------------------------------------------

	public function test_h2_41_two_simultaneous_requests_with_the_same_upload_id(): void {
		// Istovremeni zahtjevi se ne ponavljaju nakon 429, pa test kreće s praznim brojačem prijenosa (30 u minuti).
		self::wait_for_fresh_rate_window();
		$total = self::total();
		$file  = self::image( 'jpeg', 1600, 1200 );
		$id    = self::uuid();
		$make  = static fn(): array => array(
			'url'   => self::url( self::MEDIA ),
			'basic' => self::key( 'K_RW' ),
			'body'  => array( 'client_upload_id' => $id, 'title' => 'bbh-test-istovremeno', 'file' => new CURLFile( $file, 'image/jpeg', 'bbh-test-istovremeno.jpg' ) ),
		);
		$results  = self::http_parallel_post( array( $make(), $make(), $make() ) );
		$statuses = array_column( $results, 'status' );
		sort( $statuses );
		self::assertSame( 1, count( array_keys( $statuses, 201, true ) ), 'točno jedan zahtjev stvara sliku: ' . implode( ',', $statuses ) );
		foreach ( $statuses as $status ) {
			self::assertContains( $status, array( 200, 201, 409 ), 'neočekivan status: ' . implode( ',', $statuses ) );
		}
		$ids = array();
		foreach ( $results as $result ) {
			if ( in_array( $result['status'], array( 200, 201 ), true ) ) {
				$ids[] = $result['json']['id'];
			}
		}
		self::assertCount( 1, array_unique( $ids ), 'svi uspješni odgovori vraćaju istu sliku' );
		self::assertSame( $total + 1, self::total(), 'u biblioteci je točno jedna nova slika' );
	}

	// --- Ništa drugo nije dirano (H2.36, H2.50) ------------------------------------------------

	public function test_h2_50_nothing_else_in_the_shop_was_changed(): void {
		$after = self::media_probe();
		self::assertSame( self::$before['others_count'], $after['others_count'], 'nijedan raniji privitak nije obrisan niti dodan' );
		self::assertSame( self::$before['others_max_modified'], $after['others_max_modified'], 'nijedan raniji privitak nije izmijenjen' );
		self::assertSame( self::$before['products_count'], $after['products_count'] );
		self::assertSame( self::$before['products_max_modified'], $after['products_max_modified'], 'nijedan proizvod nije izmijenjen' );
		self::assertGreaterThan( 0, $after['test_count'], 'testne slike postoje i nose naslov bbh-test- i meta _bulkbird_upload_id' );
		self::assertTrue( $after['rate_table'], 'tablica brojača postoji nakon prvog zahtjeva' );
		self::assertSame( '1', $after['db_version'] );
		self::assertLessThanOrEqual( 3, $after['rate_rows'], 'jedan redak po korisniku, ne po zahtjevu' );
	}
}
