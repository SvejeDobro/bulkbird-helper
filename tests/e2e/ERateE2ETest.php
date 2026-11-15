<?php
/**
 * Ograničenje učestalosti stvarnim HTTP zahtjevima (H1.47–H1.52, H2.53, H2.54).
 * Traje oko tri minute jer čeka početak i kraj prozora od 60 sekundi.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class ERateE2ETest extends E2ECase {

	private const INFO  = '/wp-json/bulkbird/v1/info';
	private const MEDIA = '/wp-json/bulkbird/v1/media';

	private static function once( string $method, string $path, string $key, array $extra = array() ): array {
		return self::http( $method, self::url( $path ), array_merge( array( 'basic' => self::key( $key ), 'no_retry' => true ), $extra ) );
	}

	public function test_h1_47_to_h1_52_request_limit(): void {
		self::wait_for_fresh_rate_window();
		$started = time();

		$statuses = array();
		for ( $i = 1; $i <= 120; $i++ ) {
			$statuses[] = self::once( 'GET', $i % 2 ? self::INFO : self::MEDIA . '?per_page=1', 'K_READ' )['status'];
		}
		if ( time() - $started > 55 ) {
			self::markTestSkipped( '120 zahtjeva nije stalo u jedan prozor (spora veza); ponovi test.' );
		}
		self::assertSame( array( 200 ), array_values( array_unique( $statuses ) ), 'prvih 120 zahtjeva u prozoru prolazi' );

		$refused = self::once( 'GET', self::INFO, 'K_READ' );
		self::assertSame( 429, $refused['status'], 'H1.47: 121. zahtjev' );
		self::assertSame( 'bulkbird_rate_limited', $refused['json']['code'] );
		self::assertSame( array( 'code', 'message', 'data' ), array_keys( $refused['json'] ) );
		$retry_after = (int) ( $refused['headers']['retry-after'] ?? 0 );
		self::assertGreaterThanOrEqual( 1, $retry_after );
		self::assertLessThanOrEqual( 60, $retry_after );
		self::assertSame( $retry_after, $refused['json']['data']['retry_after'] );
		self::assertMatchesRegularExpression( '/no-store|no-cache/', $refused['headers']['cache-control'] ?? '', 'H1.52' );
		self::assertStringNotContainsString( 'plugin_version', $refused['body'] );

		// H1.50: drugi ključ istog korisnika dijeli brojač.
		self::assertSame( 429, self::once( 'GET', self::MEDIA . '?per_page=1', 'K_RW' )['status'] );

		// H1.49: drugi korisnik (administrator, kolačić) ima svoj brojač.
		$session = self::session( array( 'BBH_ROLE' => 'administrator' ) );
		$admin   = self::http( 'GET', self::url( self::INFO ), array( 'headers' => array( 'Cookie: ' . $session['cookie'], 'X-WP-Nonce: ' . $session['nonce'] ), 'no_retry' => true ) );
		self::assertSame( 200, $admin['status'], 'drugi korisnik nije ograničen' );

		// Korisnik bez prava i dalje dobiva 403 (ne troši i ne dijeli brojač), neprijavljen 401.
		self::assertSame( 403, self::once( 'GET', self::INFO, 'K_CUST' )['status'] );
		self::assertSame( 401, self::http( 'GET', self::url( self::INFO ), array( 'no_retry' => true ) )['status'] );

		// Tuđe putanje nisu ograničene.
		self::assertSame( 200, self::once( 'GET', '/wp-json/wc/v3/products?per_page=1', 'K_READ' )['status'], 'ograničenje vrijedi samo za putanje plugina' );

		// H1.48: nakon isteka Retry-After zahtjev opet prolazi.
		sleep( $retry_after + 1 );
		self::assertSame( 200, self::once( 'GET', self::INFO, 'K_READ' )['status'] );
	}

	public function test_h2_53_h2_54_upload_limit_counts_failed_uploads_too(): void {
		self::wait_for_fresh_rate_window();
		$started = time();
		$before  = self::media_probe()['attachments_total'];

		// 30 neispravnih prijenosa (bez datoteke): svaki 400, svaki se broji, nijedan ništa ne sprema.
		$statuses = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$statuses[] = self::once( 'POST', self::MEDIA, 'K_RW', array( 'body' => array( 'client_upload_id' => '3f2b8c1e-9d4a-4b7e-8f1a-2c3d4e5f6a7b' ) ) )['status'];
		}
		if ( time() - $started > 55 ) {
			self::markTestSkipped( '30 zahtjeva nije stalo u jedan prozor (spora veza); ponovi test.' );
		}
		self::assertSame( array( 400 ), array_values( array_unique( $statuses ) ) );

		$refused = self::once( 'POST', self::MEDIA, 'K_RW', array( 'body' => array( 'client_upload_id' => '3f2b8c1e-9d4a-4b7e-8f1a-2c3d4e5f6a7b' ) ) );
		self::assertSame( 429, $refused['status'], 'H2.53: 31. prijenos u minuti' );
		self::assertSame( 'bulkbird_rate_limited', $refused['json']['code'] );
		self::assertGreaterThanOrEqual( 1, (int) ( $refused['headers']['retry-after'] ?? 0 ) );

		// Čitanje još nije ograničeno (31 zahtjev od 120).
		self::assertSame( 200, self::once( 'GET', self::MEDIA . '?per_page=1', 'K_READ' )['status'] );
		self::assertSame( $before, self::media_probe()['attachments_total'], 'ništa nije spremljeno' );

		sleep( (int) $refused['headers']['retry-after'] + 1 );
	}
}
