<?php
/**
 * H0.20: tijekom svih prethodnih testova plugin nije ništa upisao u logove stranice.
 * Mora se izvršiti zadnji (zato ime razreda počinje sa Z).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class ZLogE2ETest extends E2ECase {

	public function test_h0_20_nothing_about_the_plugin_or_keys_in_the_logs(): void {
		$before = self::remember( 'log_lines_before' );
		if ( null === $before ) {
			self::markTestSkipped( 'Pokreni cijelu skupinu e2e (stanje logova se bilježi na početku).' );
		}
		self::assertSame( $before, self::suspicious_log_lines(), 'u logovima su se pojavili novi retci o pluginu ili REST ključu' );
	}

	/**
	 * Pravilo vlasnika (15. 11. 2026.): ključ se šalje samo u zaglavlju. Ako bi ijedan test poslao ključ
	 * u adresi, secret bi ostao u zapisu nginxa. Traži se samo zadnjih 8 znakova svakog secreta, a
	 * ispisuje samo broj redaka.
	 */
	public function test_no_current_secret_in_the_web_server_access_log(): void {
		foreach ( array( 'K_READ', 'K_RW', 'K_WRITE', 'K_CUST' ) as $name ) {
			$tail = substr( self::key( $name )[1], -8 );
			self::assertSame( 8, strlen( $tail ) );
			self::assertSame( 0, self::access_log_lines_containing( $tail ), 'secret ključa ' . $name . ' je u zapisu nginxa' );
		}
	}
}
