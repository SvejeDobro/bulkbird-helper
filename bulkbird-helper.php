<?php
/**
 * Plugin Name:          Bulkbird Helper
 * Description:          Optional companion for the Bulkbird desktop app. Adds a few REST endpoints that the WooCommerce REST API does not offer. No settings page, no front-end code.
 * Version:              0.1.0-rc.2
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Author:               Bulkbird
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          bulkbird-helper
 * WC requires at least: 8.0
 * WC tested up to:      11.3
 *
 * This is the only file of the plugin that WordPress loads on every request.
 * It must stay tiny: no database access, no assets, no front-end hooks.
 * Everything else lives in includes/ and is loaded on demand.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

const VERSION           = '0.1.0-rc.2';
const API_VERSION       = 1;
const REST_NAMESPACE    = 'bulkbird/v1';
const ROUTE_PREFIX      = 'bulkbird';
const MIN_WC_VERSION    = '8.0';
const OPTION_DB_VERSION = 'bulkbird_helper_db_version';
const PLUGIN_FILE       = __FILE__;

/**
 * Whether the plugin may do anything at all on this site.
 *
 * Multisite is not supported in v1, and without a recent enough WooCommerce
 * the plugin silently stays idle (no routes, no notices).
 *
 * @return bool
 */
function is_supported_environment(): bool {
	if ( is_multisite() ) {
		return false;
	}

	return defined( 'WC_VERSION' ) && version_compare( (string) WC_VERSION, MIN_WC_VERSION, '>=' );
}

/**
 * Whether a REST route belongs to this plugin.
 *
 * Strict prefix match on the first path segment: "bulkbird/..." only.
 *
 * @param string $route REST route, with or without leading slash.
 * @return bool
 */
function is_own_route( string $route ): bool {
	$route = '/' . trim( $route, '/' ) . '/';

	return str_starts_with( $route, '/' . ROUTE_PREFIX . '/' );
}

/**
 * Whether the current HTTP request addresses one of this plugin's REST routes.
 *
 * Mirrors the order in which WordPress resolves the REST route: a "rest_route"
 * request variable (POST body first, then query string) wins over the path.
 * Reads only the request itself; never touches the database.
 *
 * @return bool
 */
function request_targets_own_route(): bool {
	// The raw values are only compared against a fixed prefix, never stored, echoed or executed.
	// Sanitising them first could turn a foreign route into one that looks like ours.
	// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( isset( $_POST['rest_route'] ) ) {
		return is_string( $_POST['rest_route'] ) && is_own_route( wp_unslash( $_POST['rest_route'] ) );
	}
	if ( isset( $_GET['rest_route'] ) ) {
		return is_string( $_GET['rest_route'] ) && is_own_route( wp_unslash( $_GET['rest_route'] ) );
	}

	if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}
	$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );
	// phpcs:enable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	// Cheapest possible exit for every request that is not ours (all front-end pages).
	if ( false === strpos( $request_uri, '/' . ROUTE_PREFIX . '/' ) ) {
		return false;
	}

	$path = wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( ! is_string( $path ) ) {
		return false;
	}
	$path = trim( $path, '/' );

	$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
	if ( is_string( $home_path ) && '' !== trim( $home_path, '/' ) ) {
		$home_path = trim( $home_path, '/' );
		if ( ! str_starts_with( $path . '/', $home_path . '/' ) ) {
			return false;
		}
		$path = trim( substr( $path, strlen( $home_path ) ), '/' );
	}

	if ( str_starts_with( $path, 'index.php/' ) ) {
		$path = substr( $path, strlen( 'index.php/' ) );
	}

	$rest_prefix = trim( rest_get_url_prefix(), '/' ) . '/';
	if ( ! str_starts_with( $path, $rest_prefix ) ) {
		return false;
	}

	return is_own_route( substr( $path, strlen( $rest_prefix ) ) );
}

/**
 * Lets WooCommerce authenticate its REST API keys on this plugin's routes.
 *
 * WooCommerce only authenticates keys on its own namespaces. This widens that
 * to "bulkbird/" and to nothing else.
 *
 * @param mixed $is_rest_api_request Whether WooCommerce already treats the request as its own.
 * @return bool
 */
function filter_is_request_to_rest_api( $is_rest_api_request ): bool {
	if ( $is_rest_api_request ) {
		return true;
	}
	if ( is_multisite() ) {
		return false;
	}

	return request_targets_own_route();
}
add_filter( 'woocommerce_rest_is_request_to_rest_api', __NAMESPACE__ . '\\filter_is_request_to_rest_api' );

/**
 * Registers the REST routes. Runs on REST requests only.
 *
 * @return void
 */
function register_rest_routes(): void {
	if ( ! is_supported_environment() ) {
		return;
	}

	// Only names are registered here. The classes behind them are loaded when one of
	// these callbacks actually runs, so REST requests for other routes load nothing more.
	register_rest_route(
		REST_NAMESPACE,
		'/info',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\rest_get_info',
				'permission_callback' => __NAMESPACE__ . '\\rest_can_get_info',
				'args'                => array(),
			),
			'schema' => __NAMESPACE__ . '\\rest_get_info_schema',
		)
	);

	add_filter( 'rest_post_dispatch', __NAMESPACE__ . '\\forbid_caching_of_own_responses', 10, 3 );
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );

/**
 * Loads the classes behind the REST routes. Called only from the route callbacks.
 *
 * @return void
 */
function load_rest_classes(): void {
	require_once __DIR__ . '/includes/class-permissions.php';
	require_once __DIR__ . '/includes/class-environment.php';
	require_once __DIR__ . '/includes/class-info-controller.php';
}

/**
 * Permission callback of GET /info: shop managers and administrators only.
 *
 * @return true|\WP_Error
 */
function rest_can_get_info() {
	load_rest_classes();

	return Permissions::require_capability( 'manage_woocommerce' );
}

/**
 * Callback of GET /info.
 *
 * @return \WP_REST_Response
 */
function rest_get_info(): \WP_REST_Response {
	load_rest_classes();

	return ( new Info_Controller() )->get_info();
}

/**
 * Schema of GET /info.
 *
 * @return array<string, mixed>
 */
function rest_get_info_schema(): array {
	load_rest_classes();

	return ( new Info_Controller() )->get_schema();
}

/**
 * Marks every response of this plugin's routes (errors included) as not cacheable.
 *
 * @param mixed $response Response object.
 * @param mixed $server   REST server.
 * @param mixed $request  Request object.
 * @return mixed
 */
function forbid_caching_of_own_responses( $response, $server, $request ) {
	if ( $response instanceof \WP_HTTP_Response && $request instanceof \WP_REST_Request && is_own_route( $request->get_route() ) ) {
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
	}

	return $response;
}

/**
 * Declares compatibility with WooCommerce High-Performance Order Storage.
 *
 * @return void
 */
function declare_hpos_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PLUGIN_FILE, true );
	}
}
add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\declare_hpos_compatibility' );

/**
 * Prints the plugin's section in WooCommerce > Status. Admin screen only.
 *
 * @return void
 */
function render_status_report(): void {
	if ( ! is_supported_environment() ) {
		return;
	}

	require_once __DIR__ . '/includes/class-status-report.php';

	Status_Report::render();
}
add_action( 'woocommerce_system_status_report', __NAMESPACE__ . '\\render_status_report' );

/**
 * Activation: refuses multisite, records the schema version.
 *
 * @return void
 */
function on_activation(): void {
	require_once __DIR__ . '/includes/class-activation.php';

	Activation::activate();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\on_activation' );
