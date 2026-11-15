<?php
/**
 * Integracijski testovi unutar WordPress testne okoline (scenarij H0.77). Pokreću se u CI-ju
 * (GitHub Actions, MySQL servis); lokalno samo ako postoji zasebna prazna testna baza.
 *
 * Varijable okoline:
 *   WP_TESTS_CONFIG_FILE_PATH  wp-tests-config.php (stvara ga bin/ci-prepare-wp.sh)
 *   WP_MULTISITE=1             multisite način
 *   BBH_WITHOUT_WC=1           WooCommerce se ne učitava (scenarij H0.19)
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

$bbh_root = dirname( __DIR__, 2 );
require_once $bbh_root . '/vendor/autoload.php';

$bbh_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $bbh_tests_dir || ! is_dir( $bbh_tests_dir ) ) {
	fwrite( STDERR, "Nedostaje wp-phpunit (composer require --dev wp-phpunit/wp-phpunit).\n" );
	exit( 1 );
}
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $bbh_root . '/vendor/yoast/phpunit-polyfills' );
}

require_once $bbh_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $bbh_root ): void {
		if ( '1' !== getenv( 'BBH_WITHOUT_WC' ) ) {
			require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		}
		require_once $bbh_root . '/bulkbird-helper.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( class_exists( 'WC_Install' ) ) {
			// Tablice i uloge WooCommercea (shop_manager, customer) u praznoj testnoj bazi.
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore
			wp_roles();
		}
	}
);

require_once $bbh_tests_dir . '/includes/bootstrap.php';
