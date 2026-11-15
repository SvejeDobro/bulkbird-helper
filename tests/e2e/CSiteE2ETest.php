<?php
/**
 * Poglavlja 3, 5 (dio) i 7 (dio) scenarija na stvarnoj stranici:
 * stanje nakon aktivacije, kuke, dozvole putanja, izravan poziv datoteka (H0.36–H0.39, H0.47, H0.48, H0.55, H0.56, H0.59, H0.75).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class CSiteE2ETest extends E2ECase {

	public function test_h0_36_plugin_is_active_in_the_expected_version(): void {
		$site = self::site();
		self::assertTrue( $site['plugin_active'], 'plugin mora biti aktivan' );
		self::assertSame( self::expected_version(), $site['plugin_version'] );
		self::assertFalse( $site['multisite'] );
	}

	public function test_h0_37_state_after_activation(): void {
		$internal = self::internal();
		self::assertSame( array(), $internal['tables'], 'faza 0 ne stvara tablice' );
		self::assertSame( array(), $internal['cron'], 'faza 0 nema cron poslova' );
		self::assertCount( 1, $internal['options'], 'najviše jedna vlastita opcija' );
		self::assertSame( 'bulkbird_helper_db_version', $internal['options'][0]['option_name'] );
		self::assertContains( $internal['options'][0]['autoload'], array( 'no', 'off' ), 'opcija se ne smije automatski učitavati' );
	}

	public function test_h0_38_no_extra_links_in_the_plugin_list(): void {
		$site = self::site();
		self::assertSame( array(), $site['action_links'], 'plugin ne dodaje poveznice (npr. Postavke)' );
		self::assertSame( array(), $site['row_meta'] );
		self::assertSame( array(), $site['admin_ui_hooks'], 'nema izbornika, obavijesti ni admin skripti' );
	}

	public function test_h0_39_hpos_compatibility_is_declared(): void {
		$compat = self::site()['hpos_compat'] ?? null;
		if ( null === $compat ) {
			self::markTestSkipped( 'Ova verzija WooCommercea ne nudi popis kompatibilnosti.' );
		}
		self::assertTrue( $compat['compatible'] );
		self::assertFalse( $compat['incompatible'] );
		self::assertFalse( $compat['uncertain'] );
	}

	public function test_h0_47_plugin_files_do_nothing_when_called_directly(): void {
		$files = array( 'bulkbird-helper.php', 'uninstall.php', 'includes/class-info-controller.php', 'includes/class-permissions.php', 'includes/class-environment.php', 'includes/class-activation.php', 'includes/class-status-report.php' );
		foreach ( $files as $file ) {
			$response = self::http( 'GET', self::url( '/wp-content/plugins/bulkbird-helper/' . $file ) );
			self::assertNotSame( 500, $response['status'], $file );
			self::assertDoesNotMatchRegularExpression( '#Fatal error|Warning|Notice|/home/|Stack trace|plugin_version#i', $response['body'], $file . ' ne smije ništa ispisati' );
			if ( 200 === $response['status'] ) {
				self::assertSame( '', trim( $response['body'] ), $file . ' mora vratiti prazno tijelo' );
			}
		}
	}

	public function test_h0_48_status_report_section(): void {
		$report = self::site()['status_report'];
		self::assertTrue( $report['has_section'] );
		self::assertTrue( $report['has_version'] );
		self::assertTrue( $report['has_api_version'] );
		self::assertFalse( $report['script_or_style'], 'odjeljak ne smije sadržavati skripte ni stilove' );
	}

	public function test_h0_55_storefront_html_references_no_plugin_assets(): void {
		foreach ( array( '/', '/shop/', '/cart/' ) as $path ) {
			$response = self::http( 'GET', self::url( $path ) );
			self::assertSame( 200, $response['status'], $path );
			self::assertStringNotContainsStringIgnoringCase( 'bulkbird', $response['body'], $path . ': HTML ne smije spominjati plugin' );
		}
	}

	public function test_h0_56_only_the_expected_hooks_are_registered(): void {
		$hooks = array_map( static fn( string $hook ): string => (string) strtok( $hook, ' ' ), self::internal()['hooks'] );
		sort( $hooks );
		self::assertSame(
			array(
				'before_woocommerce_init',
				'rest_api_init',
				'rest_post_dispatch',
				'woocommerce_rest_is_request_to_rest_api',
				'woocommerce_system_status_report',
			),
			array_values( array_filter( $hooks, static fn( string $hook ): bool => 0 !== strpos( $hook, 'activate_' ) ) )
		);
	}

	public function test_h0_59_wp_cli_request_loads_only_what_it_needs(): void {
		$loaded = self::internal()['plugin_files_loaded'];
		sort( $loaded );
		self::assertSame(
			array( 'bulkbird-helper.php', 'includes/class-environment.php', 'includes/class-info-controller.php', 'includes/class-permissions.php' ),
			$loaded,
			'aktivacijske i statusne datoteke se ne učitavaju pri REST zahtjevu'
		);
	}

	public function test_h0_75_every_route_denies_anonymous_users(): void {
		$routes = self::internal()['routes'];
		$own    = array_values( array_filter( $routes, static fn( array $route ): bool => '/bulkbird/v1' !== $route['route'] ) );
		self::assertNotEmpty( $own );
		foreach ( $own as $route ) {
			self::assertTrue( $route['has_permission_cb'], $route['route'] );
			self::assertFalse( $route['is_return_true'], $route['route'] );
			self::assertTrue( $route['anonymous_is_denied'], $route['route'] );
		}
		// Indeks namespacea (/bulkbird/v1) registrira sam WordPress; popisuje samo putanje.
		self::assertCount( count( $routes ) - 1, $own );
	}
}
