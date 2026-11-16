<?php
/**
 * Proba stanja stranice nakon aktivacije (H0.36–H0.39, H0.48). Samo čita.
 * Također služi testovima za dohvat nekoliko netajnih postavki trgovine.
 *
 *   wp eval-file tests/probe/site.php
 *
 * Ispis: JSON (bez tajni i bez osobnih podataka).
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	$basename = 'bulkbird-helper/bulkbird-helper.php';
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$out = array(
		'plugin_active'  => is_plugin_active( $basename ),
		'plugin_version' => defined( 'Bulkbird\Helper\VERSION' ) ? Bulkbird\Helper\VERSION : null,
		'wp'             => get_bloginfo( 'version' ),
		'wc'             => defined( 'WC_VERSION' ) ? WC_VERSION : null,
		'php'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
		'multisite'      => is_multisite(),
		'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
		'timezone'       => wp_timezone_string(),
		'hpos_option'    => get_option( 'woocommerce_custom_orders_table_enabled', 'no' ),
		'autoload_bytes' => (int) $GLOBALS['wpdb']->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$GLOBALS['wpdb']->options} WHERE autoload IN ('yes','on','auto','auto-on')" ), // phpcs:ignore
	);

	// H2.81: smije li prvi administrator (isti kojeg testovi prijavljuju kolačićem) spremati nefiltrirani HTML.
	$admins                          = get_users(
		array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'fields'  => 'ID',
		)
	);
	$out['admin_unfiltered_html']    = $admins ? user_can( (int) $admins[0], 'unfiltered_html' ) : null;
	$out['disallow_unfiltered_html'] = defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML;

	// H0.38: redak u popisu pluginova nema dodatnih poveznica (npr. "Postavke").
	$out['action_links'] = array_keys( (array) apply_filters( 'plugin_action_links_' . $basename, array(), $basename, array(), 'all' ) );
	$out['row_meta']     = array_keys( (array) apply_filters( 'plugin_row_meta', array(), $basename, array(), 'all' ) );

	// H0.39: deklarirana kompatibilnost s HPOS-om.
	$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
	if ( is_callable( array( $features_util, 'get_compatible_plugins_for_feature' ) ) ) {
		$compat             = call_user_func( array( $features_util, 'get_compatible_plugins_for_feature' ), 'custom_order_tables', true );
		$out['hpos_compat'] = array(
			'compatible'   => in_array( $basename, (array) ( $compat['compatible'] ?? array() ), true ),
			'incompatible' => in_array( $basename, (array) ( $compat['incompatible'] ?? array() ), true ),
			'uncertain'    => in_array( $basename, (array) ( $compat['uncertain'] ?? array() ), true ),
		);
	}

	// H0.48: odjeljak u WooCommerce > Status.
	ob_start();
	if ( function_exists( 'Bulkbird\Helper\render_status_report' ) ) {
		Bulkbird\Helper\render_status_report();
	}
	$html                 = (string) ob_get_clean();
	$out['status_report'] = array(
		'has_section'      => false !== strpos( $html, 'Bulkbird Helper' ),
		'has_version'      => defined( 'Bulkbird\Helper\VERSION' ) && false !== strpos( $html, Bulkbird\Helper\VERSION ),
		'has_api_version'  => false !== strpos( $html, 'API version' ),
		'script_or_style'  => (bool) preg_match( '/<script|<style|<link/i', $html ),
	);

	// Admin izbornik: plugin ne dodaje stranice (provjera po registriranim kukama).
	$menu_hooks = array();
	foreach ( array( 'admin_menu', 'admin_notices', 'admin_init', 'wp_dashboard_setup', 'admin_enqueue_scripts' ) as $hook_name ) {
		if ( empty( $GLOBALS['wp_filter'][ $hook_name ] ) ) {
			continue;
		}
		foreach ( $GLOBALS['wp_filter'][ $hook_name ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_string( $fn ) && 0 === stripos( $fn, 'Bulkbird\\Helper' ) ) {
					$menu_hooks[] = $hook_name;
				}
			}
		}
	}
	$out['admin_ui_hooks'] = $menu_hooks;

	echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} )();
