<?php
/**
 * Poglavlje 2 scenarija: GET /bulkbird/v1/info (H0.21–H0.35).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class BInfoE2ETest extends E2ECase {

	private const INFO   = '/wp-json/bulkbird/v1/info';
	private const FIELDS = array(
		'plugin_version',
		'api_version',
		'features',
		'wordpress_version',
		'woocommerce_version',
		'php_version',
		'hpos_enabled',
		'timezone',
		'currency',
		'max_upload_bytes',
		'memory_limit',
		'max_execution_time',
		'detected_plugins',
		'analytics_lookup',
		'media',
	);

	/** @return array{status: int, headers: array<string, string>, body: string, json: mixed, seconds: float} */
	private static function info( string $suffix = '' ): array {
		return self::http( 'GET', self::url( self::INFO . $suffix ), array( 'basic' => self::key( 'K_READ' ) ) );
	}

	public function test_h0_21_fields(): void {
		$response = self::info();
		self::assertSame( 200, $response['status'], self::describe( $response ) );
		self::assertStringStartsWith( 'application/json', $response['headers']['content-type'] ?? '' );
		self::assertSame( self::FIELDS, array_keys( $response['json'] ) );
	}

	public function test_h0_22_types(): void {
		$info = self::info()['json'];
		foreach ( array( 'plugin_version', 'wordpress_version', 'woocommerce_version', 'php_version', 'timezone', 'currency', 'analytics_lookup' ) as $field ) {
			self::assertIsString( $info[ $field ], $field );
		}
		foreach ( array( 'api_version', 'max_upload_bytes', 'memory_limit', 'max_execution_time' ) as $field ) {
			self::assertIsInt( $info[ $field ], $field );
		}
		self::assertIsBool( $info['hpos_enabled'] );
		self::assertIsArray( $info['features'] );
		self::assertIsArray( $info['detected_plugins'] );
		self::assertSame( 1, $info['api_version'] );
		self::assertGreaterThan( 0, $info['max_upload_bytes'] );
	}

	public function test_h0_23_plugin_version_matches_deployed_tag(): void {
		self::assertSame( self::expected_version(), self::info()['json']['plugin_version'] );
		self::assertSame( self::expected_version(), self::site()['plugin_version'] );
	}

	public function test_h2_49_features_and_media_capabilities(): void {
		$info = self::info()['json'];
		self::assertSame( array( 'media.read', 'media.upload' ), $info['features'] );
		self::assertSame( array( 'upload_mime_types', 'upload_max_bytes' ), array_keys( $info['media'] ) );
		self::assertSame( min( $info['max_upload_bytes'], 20971520 ), $info['media']['upload_max_bytes'] );
		foreach ( array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ) as $mime ) {
			self::assertContains( $mime, $info['media']['upload_mime_types'] );
		}
		// H2.15 / H2.16: AVIF prima samo trgovina čiji ga WordPress i poslužitelj podržavaju.
		self::assertSame( 'NEW' === self::target(), in_array( 'image/avif', $info['media']['upload_mime_types'], true ) );
		self::assertNotContains( 'image/svg+xml', $info['media']['upload_mime_types'] );
	}

	public function test_h0_25_h0_26_values_match_the_shop(): void {
		$info = self::info()['json'];
		$site = self::site();
		self::assertSame( $site['wp'], $info['wordpress_version'] );
		self::assertSame( $site['wc'], $info['woocommerce_version'] );
		self::assertSame( $site['currency'], $info['currency'] );
		self::assertSame( $site['timezone'], $info['timezone'] );
		self::assertSame( 'yes' === $site['hpos_option'], $info['hpos_enabled'] );
		// PHP weba i PHP WP-CLI-ja moraju biti ista grana (npr. 8.3), inače je okolina krivo postavljena.
		self::assertSame( implode( '.', array_slice( explode( '.', $site['php'] ), 0, 2 ) ), implode( '.', array_slice( explode( '.', $info['php_version'] ), 0, 2 ) ) );

		if ( 'NEW' === self::target() ) {
			self::assertTrue( $info['hpos_enabled'] );
			self::assertStringStartsWith( '7.1.', $info['wordpress_version'] );
			self::assertStringStartsWith( '11.3.', $info['woocommerce_version'] );
			self::assertStringStartsWith( '8.3.', $info['php_version'] );
		} else {
			self::assertStringStartsWith( '6.4.', $info['wordpress_version'], 'STARA mora biti na WordPressu 6.4.x' );
			self::assertStringStartsWith( '8.0.', $info['woocommerce_version'] );
			self::assertStringStartsWith( '8.1.', $info['php_version'] );
		}
	}

	public function test_h0_27_detected_plugins_only_from_the_allow_list(): void {
		$detected = self::info()['json']['detected_plugins'];
		foreach ( $detected as $plugin ) {
			self::assertSame( array( 'slug', 'version' ), array_keys( $plugin ) );
		}
		$slugs = array_column( $detected, 'slug' );
		foreach ( array( 'woocommerce', 'classic-editor', 'demo-data-generator', 'disable-emails', 'limit-login-attempts-reloaded', 'wps-hide-login', 'bulkbird-helper' ) as $not_listed ) {
			self::assertNotContains( $not_listed, $slugs, 'pluginovi izvan popisa se ne navode' );
		}
	}

	public function test_h0_28_analytics_lookup_state(): void {
		$state = self::info()['json']['analytics_lookup'];
		self::assertContains( $state, array( 'complete', 'importing', 'unknown' ) );
		if ( 'NEW' === self::target() ) {
			self::assertSame( 'complete', $state );
		}
	}

	public function test_h0_29_no_forbidden_content(): void {
		$body = self::info()['body'];
		self::assertDoesNotMatchRegularExpression( '#/home/|\\\\/home\\\\/|htdocs|wp-config|wp-content#i', $body, 'putanje na disku' );
		self::assertDoesNotMatchRegularExpression( '/\bc[ks]_[0-9a-f]{8,}/', $body, 'REST ključ' );
		self::assertDoesNotMatchRegularExpression( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $body, 'e-mail adresa' );
		self::assertDoesNotMatchRegularExpression( '/password|salt|db_name|db_user|user_login|user_email|display_name|wp-login|bulkbird-test-/i', $body, 'tajne, korisnici ili adresa prijave' );
	}

	public function test_h0_30_unknown_parameters_are_ignored(): void {
		$plain = self::info()['json'];
		self::assertSame( $plain, self::info( '?foo=1' )['json'] );
		self::assertSame( $plain, self::info( '?per_page=5&page=3&orderby=x' )['json'] );
	}

	public function test_h0_31_head_request(): void {
		$response = self::http( 'HEAD', self::url( self::INFO ), array( 'basic' => self::key( 'K_READ' ) ) );
		self::assertSame( 200, $response['status'] );
		self::assertSame( '', $response['body'] );
	}

	public function test_h0_32_repeated_requests_are_identical_and_write_nothing(): void {
		self::assertSame( self::info()['json'], self::info()['json'] );
		$second = self::internal()['info_shopmgr_second'];
		self::assertSame( 0, $second['write_queries'], 'GET /info ne smije upisivati u bazu: ' . json_encode( $second['query_verbs'] ) );
	}

	public function test_h0_33_discovery_through_the_public_rest_index(): void {
		$index = self::http( 'GET', self::url( '/wp-json/' ) );
		self::assertSame( 200, $index['status'] );
		self::assertContains( 'bulkbird/v1', $index['json']['namespaces'] ?? array() );
		self::assertStringNotContainsString( self::expected_version(), json_encode( $index['json']['routes']['/bulkbird/v1/info'] ?? array() ) );

		$namespace = self::http( 'GET', self::url( '/wp-json/bulkbird/v1' ) );
		self::assertStringNotContainsString( '"plugin_version"', $namespace['body'], 'indeks namespacea ne otkriva verziju plugina' );
	}

	public function test_h0_34_response_time_through_cloudflare_is_recorded(): void {
		$times = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$response = self::info();
			self::assertSame( 200, $response['status'] );
			$times[] = $response['seconds'];
		}
		sort( $times );
		$median = ( $times[14] + $times[15] ) / 2;
		fwrite( STDERR, sprintf( "\n[H0.34 %s] GET /info kroz Cloudflare: medijan %.0f ms (min %.0f, max %.0f; 30 zahtjeva) — samo zapis, bez praga\n", self::target(), $median * 1000, $times[0] * 1000, $times[29] * 1000 ) );
		self::assertGreaterThan( 0.0, $median );
	}

	public function test_h0_35_fixture(): void {
		$info = self::info()['json'];
		$dir  = dirname( __DIR__, 2 ) . '/fixtures/v1';
		$file = $dir . '/info.' . strtolower( self::target() ) . '.json';
		if ( '1' === getenv( 'BBH_WRITE_FIXTURES' ) ) {
			file_put_contents( $file, json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		}
		if ( ! is_readable( $file ) ) {
			self::markTestSkipped( 'Primjer odgovora još nije zapisan (BBH_WRITE_FIXTURES=1).' );
		}
		$fixture = json_decode( (string) file_get_contents( $file ), true );
		self::assertSame( array_keys( $info ), array_keys( $fixture ), 'primjer odgovora mora imati ista polja kao stvarni odgovor' );
	}
}
