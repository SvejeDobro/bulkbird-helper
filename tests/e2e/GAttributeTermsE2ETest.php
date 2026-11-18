<?php
/**
 * GET /bulkbird/v1/terms/order za vrijednosti globalnog atributa, stvarnim HTTP-om (0.5.0; H2.106).
 *
 * Stvara testni atribut "bbh-test-atribut" s osam vrijednosti i skicom proizvoda (WooCommerce REST, ključ
 * K_RW; tests/tools/probe-attribute-order.php), mijenja mu postavku poretka i na kraju sve briše
 * (tests/tools/cleanup-test-attributes.php; trajno odobrenje vlasnika za "bbh-test-").
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class GAttributeTermsE2ETest extends E2ECase {

	private const ORDER    = '/wp-json/bulkbird/v1/terms/order';
	private const TAXONOMY = 'pa_bbh-test-atribut';

	/** @return array<string, mixed> */
	private static function tool( string $step, string $order_by = '' ): array {
		$cmd  = 'BBH_TARGET=' . escapeshellarg( self::target() ) . ' BBH_STEP=' . escapeshellarg( $step ) . ' BBH_ORDER_BY=' . escapeshellarg( $order_by )
			. ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/tools/probe-attribute-order.php' ) . ' 2>/dev/null';
		$json = json_decode( (string) shell_exec( $cmd ), true );
		if ( ! is_array( $json ) ) {
			throw new RuntimeException( 'Alat za testni atribut nije vratio JSON (korak ' . $step . ').' );
		}
		return $json;
	}

	public static function setUpBeforeClass(): void {
		if ( version_compare( (string) self::site()['plugin_version'], '0.5.0', '<' ) ) {
			self::markTestSkipped( 'Na stranici je plugin stariji od 0.5.0.' );
		}
		self::wpcli( 'tests/tools/cleanup-test-attributes.php' ); // Ostaci prekinutog pokretanja.
		$created = self::tool( 'create' );
		if ( 201 !== $created['attribute']['status'] ) {
			throw new RuntimeException( 'Testni atribut nije stvoren: status ' . $created['attribute']['status'] );
		}
	}

	public static function tearDownAfterClass(): void {
		if ( version_compare( (string) self::site()['plugin_version'], '0.5.0', '<' ) ) {
			return;
		}
		$done = self::wpcli( 'tests/tools/cleanup-test-attributes.php' );
		fwrite( STDERR, sprintf( "\n[čišćenje] atribut ID: %s; vrijednosti ID: %s; skice ID: %s\n", implode( ', ', $done['attributes'] ), implode( ', ', $done['terms'] ), implode( ', ', $done['products'] ) ) );
	}

	/** @return array{status: int, headers: array<string, string>, body: string, json: mixed, seconds: float} */
	private static function order( string $query, string $key = 'K_READ' ): array {
		return self::http( 'GET', self::url( self::ORDER . $query ), array( 'basic' => self::key( $key ) ) );
	}

	/** @return array<int, array{0: string}> */
	public static function settings(): array {
		return array( array( 'menu_order' ), array( 'name' ), array( 'name_num' ), array( 'id' ) );
	}

	/** @dataProvider settings */
	public function test_h2_106_values_in_the_order_the_shop_gives_for_the_attribute( string $order_by ): void {
		self::assertSame( 200, self::tool( 'set', $order_by )['set']['status'] );
		$probe    = self::wpcli( 'tests/probe/terms.php', array( 'BBH_TAXONOMY' => self::TAXONOMY ) );
		$response = self::order( '?taxonomy=' . self::TAXONOMY . '&per_page=1000' );
		self::assertSame( 200, $response['status'], self::describe( $response ) );
		self::assertCount( 8, $response['json'] );
		self::assertSame( $probe['items'], $response['json'], 'isti redoslijed i isti zapisi kao zadani get_terms() trgovine uz postavku ' . $order_by );
		self::assertSame( '8', $response['headers']['x-wp-total'] ?? '' );
		foreach ( $response['json'] as $item ) {
			self::assertSame( array( 'id', 'parent', 'order' ), array_keys( $item ) );
			self::assertSame( 0, $item['parent'] );
		}
		// Postavku klijent čita iz WooCommerce REST-a, ključem samo za čitanje.
		self::assertSame( $order_by, self::tool( 'read' )['single']['body']['order_by'] );
	}

	public function test_h2_106_other_taxonomies_and_permissions(): void {
		foreach ( array( 'pa_nepostojeci', 'pa_', 'product_tag' ) as $taxonomy ) {
			$response = self::order( '?taxonomy=' . $taxonomy );
			self::assertSame( 400, $response['status'], $taxonomy );
			self::assertSame( 'rest_invalid_param', $response['json']['code'] );
		}
		self::assertSame( 401, self::http( 'GET', self::url( self::ORDER . '?taxonomy=' . self::TAXONOMY ) )['status'] );
		self::assertSame( 403, self::order( '?taxonomy=' . self::TAXONOMY, 'K_CUST' )['status'] );
		$index = self::http( 'GET', self::url( '/wp-json/bulkbird/v1' ) );
		self::assertStringNotContainsString( 'bbh-test-atribut', $index['body'], 'javni opis putanje ne nabraja atribute' );
	}
}
