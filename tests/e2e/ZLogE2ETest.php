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
}
