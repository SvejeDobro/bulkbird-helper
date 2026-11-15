<?php
/**
 * Facts about the shop environment that Bulkbird needs.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only environment inspection. Never returns paths, secrets or users.
 */
final class Environment {

	/**
	 * Plugin directory slugs that matter to Bulkbird (page builders, SEO,
	 * multilingual, cache, Omnibus). Only these are ever reported.
	 *
	 * @var string[]
	 */
	private const RELEVANT_PLUGINS = array(
		// Page builders.
		'elementor',
		'elementor-pro',
		'beaver-builder-lite-version',
		'bb-plugin',
		'js_composer',
		'divi-builder',
		'oxygen',
		'brizy',
		'siteorigin-panels',
		// SEO.
		'wordpress-seo',
		'wordpress-seo-premium',
		'seo-by-rank-math',
		'seo-by-rank-math-pro',
		'all-in-one-seo-pack',
		'all-in-one-seo-pack-pro',
		'autodescription',
		'wp-seopress',
		// Multilingual.
		'sitepress-multilingual-cms',
		'woocommerce-multilingual',
		'polylang',
		'polylang-pro',
		'polylang-wc',
		'translatepress-multilingual',
		// Cache and optimisation.
		'wp-rocket',
		'w3-total-cache',
		'wp-super-cache',
		'litespeed-cache',
		'wp-fastest-cache',
		'sg-cachepress',
		'cache-enabler',
		'autoptimize',
		'wp-optimize',
		'nginx-helper',
		'redis-cache',
		'breeze',
		'clp-varnish-cache',
		'varnish-http-purge',
		// Omnibus (lowest price in 30 days).
		'wc-price-history',
		'omnibus',
		'woocommerce-eu-omnibus',
		'iworks-omnibus',
	);

	/**
	 * PHP version as "major.minor.release", without distribution suffixes.
	 *
	 * @return string
	 */
	public static function php_version(): string {
		return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (HPOS).
	 *
	 * @return bool
	 */
	public static function hpos_enabled(): bool {
		$order_util = '\Automattic\WooCommerce\Utilities\OrderUtil';

		return is_callable( array( $order_util, 'custom_orders_table_usage_is_enabled' ) )
			&& (bool) call_user_func( array( $order_util, 'custom_orders_table_usage_is_enabled' ) );
	}

	/**
	 * PHP memory limit in bytes; -1 means unlimited.
	 *
	 * @return int
	 */
	public static function memory_limit_bytes(): int {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '' === $raw || '-1' === trim( $raw ) ) {
			return -1;
		}

		return (int) wp_convert_hr_to_bytes( $raw );
	}

	/**
	 * Active plugins from the allow-list above, as slug and version.
	 *
	 * @return array<int, array{slug: string, version: string}>
	 */
	public static function detected_plugins(): array {
		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			return array();
		}

		$detected = array();
		foreach ( $active as $plugin_file ) {
			if ( ! is_string( $plugin_file ) || 0 !== validate_file( $plugin_file ) ) {
				continue;
			}

			$slug = dirname( $plugin_file );
			if ( '.' === $slug ) {
				$slug = basename( $plugin_file, '.php' );
			}
			if ( ! in_array( $slug, self::RELEVANT_PLUGINS, true ) ) {
				continue;
			}

			$full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			$version   = '';
			if ( is_readable( $full_path ) ) {
				$headers = get_file_data( $full_path, array( 'Version' => 'Version' ) );
				$version = isset( $headers['Version'] ) ? (string) $headers['Version'] : '';
			}

			$detected[] = array(
				'slug'    => $slug,
				'version' => $version,
			);
		}

		usort(
			$detected,
			static function ( array $a, array $b ): int {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);

		return $detected;
	}

	/**
	 * State of the WooCommerce Analytics lookup tables.
	 *
	 * @return string "complete", "importing", or "unknown" when WooCommerce does not expose the state.
	 */
	public static function analytics_lookup_state(): string {
		$reports_sync = '\Automattic\WooCommerce\Admin\ReportsSync';
		if ( ! is_callable( array( $reports_sync, 'is_importing' ) ) ) {
			return 'unknown';
		}

		try {
			return call_user_func( array( $reports_sync, 'is_importing' ) ) ? 'importing' : 'complete';
		} catch ( \Throwable $e ) {
			return 'unknown';
		}
	}
}
