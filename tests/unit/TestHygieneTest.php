<?php
/**
 * Pravilo vlasnika (15. 11. 2026.): testni zahtjevi šalju ključ samo u zaglavlju (Basic), nikad u adresi.
 * Adresa s ključem ostaje u zapisima poslužitelja zajedno sa secretom.
 *
 * Ovaj test pada ako bilo koja testna datoteka ili alat sastavlja adresu s consumer_key / consumer_secret,
 * ili ako pomoćne funkcije za HTTP prestanu odbijati takvu adresu.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class TestHygieneTest extends TestCase {

	/** @return string[] */
	private static function sources(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array();
		foreach ( array( 'tests', 'bin' ) as $dir ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 1 === preg_match( '/\.(php|sh|py)$/', $file->getFilename() ) && __FILE__ !== $file->getPathname() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		sort( $files );
		return $files;
	}

	public function test_no_test_or_tool_builds_a_url_with_a_consumer_key_or_secret(): void {
		$files = self::sources();
		self::assertGreaterThan( 20, count( $files ), 'provjera mora vidjeti testove i alate' );

		$found = array();
		foreach ( $files as $file ) {
			foreach ( (array) file( $file ) as $number => $line ) {
				// Naziv parametra odmah praćen znakom "=": tako izgleda samo u adresi (varijable i ključevi polja ne odgovaraju).
				if ( 1 === preg_match( '/(?<![$\w])consumer_(key|secret)(=(?!>)|%3D)/i', (string) $line ) ) {
					$found[] = substr( $file, strlen( dirname( __DIR__, 2 ) ) + 1 ) . ':' . ( $number + 1 );
				}
			}
		}
		self::assertSame( array(), $found, 'ključ se šalje samo u zaglavlju, nikad u adresi' );
	}

	public function test_every_http_helper_of_the_real_http_tests_refuses_keys_in_the_url(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/e2e/E2ECase.php' );
		self::assertSame( substr_count( $source, 'curl_init(' ), substr_count( $source, 'self::refuse_keys_in_url(' ), 'svaki curl_init() mora imati provjeru adrese ispred sebe' );
		self::assertGreaterThanOrEqual( 2, substr_count( $source, 'self::refuse_keys_in_url(' ) );

		// Jedini drugi kod koji šalje zahtjeve s ključem: alat za mjerenje. Ključ ide kroz CURLOPT_USERPWD.
		foreach ( self::sources() as $file ) {
			$code = (string) file_get_contents( $file );
			if ( str_contains( $code, 'curl_init(' ) && ! str_ends_with( $file, '/e2e/E2ECase.php' ) ) {
				self::assertStringNotContainsString( '_CS', (string) preg_replace( '/CURLOPT_USERPWD[^\n]*/', '', $code ), basename( $file ) . ': secret smije ići samo u CURLOPT_USERPWD' );
			}
		}
	}

	public function test_the_refusal_itself(): void {
		require_once dirname( __DIR__ ) . '/e2e/E2ECase.php';
		$refuse = new ReflectionMethod( E2ECase::class, 'refuse_keys_in_url' );
		$refuse->setAccessible( true );

		$refuse->invoke( null, 'https://shop.example/wp-json/bulkbird/v1/media?per_page=3&search=consumer' );
		foreach ( array(
			'https://shop.example/wp-json/bulkbird/v1/info?consumer' . '_key=x',
			'https://shop.example/wp-json/bulkbird/v1/info?a=1&consumer' . '_secret=x',
			'https://shop.example/?rest_route=/bulkbird/v1/info&Consumer' . '_Key=x',
			'https://shop.example/wp-json/bulkbird/v1/info?consumer' . '_key%3Dx',
			'https://shop.example/wp-json/bulkbird/v1/info?x=cs_' . str_repeat( '0', 40 ),
		) as $url ) {
			try {
				$refuse->invoke( null, $url );
				self::fail( 'adresa nije odbijena' );
			} catch ( LogicException $refused ) {
				self::assertStringContainsString( 'samo u zaglavlju', $refused->getMessage() );
			}
		}
	}
}
