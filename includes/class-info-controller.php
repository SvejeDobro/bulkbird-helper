<?php
/**
 * GET /bulkbird/v1/info
 *
 * The route itself is registered in bulkbird-helper.php; this class is loaded
 * only when the route is actually called.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the plugin and the shop environment to Bulkbird.
 */
final class Info_Controller {

	/**
	 * Builds the response. Contains no paths, secrets or customer data, and nothing that identifies
	 * a user: of the user behind the key it tells one capability flag and nothing else.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_info(): \WP_REST_Response {
		$data = array(
			'plugin_version'      => VERSION,
			'api_version'         => API_VERSION,
			'features'            => array( 'media.read', 'media.upload', 'media.upload_status', 'user.unfiltered_html' ),
			'wordpress_version'   => (string) get_bloginfo( 'version' ),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'php_version'         => Environment::php_version(),
			'hpos_enabled'        => Environment::hpos_enabled(),
			'timezone'            => wp_timezone_string(),
			'currency'            => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'max_upload_bytes'    => (int) wp_max_upload_size(),
			'memory_limit'        => Environment::memory_limit_bytes(),
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'detected_plugins'    => Environment::detected_plugins(),
			'analytics_lookup'    => Environment::analytics_lookup_state(),
			'media'               => array(
				'upload_mime_types' => Media_Rules::upload_mime_types(),
				'upload_max_bytes'  => Media_Rules::upload_max_bytes(),
			),
			'user'                => array(
				// What WordPress itself answers for the user behind the key. False means WordPress and
				// WooCommerce silently strip iframes, scripts, styles and event attributes from what this
				// user saves. Multisite rules and DISALLOW_UNFILTERED_HTML are already part of the answer.
				'unfiltered_html' => (bool) current_user_can( 'unfiltered_html' ),
			),
		);

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * JSON schema of the response.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bulkbird-info',
			'type'       => 'object',
			'properties' => array(
				'plugin_version'      => array( 'type' => 'string' ),
				'api_version'         => array( 'type' => 'integer' ),
				'features'            => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'wordpress_version'   => array( 'type' => 'string' ),
				'woocommerce_version' => array( 'type' => 'string' ),
				'php_version'         => array( 'type' => 'string' ),
				'hpos_enabled'        => array( 'type' => 'boolean' ),
				'timezone'            => array( 'type' => 'string' ),
				'currency'            => array( 'type' => 'string' ),
				'max_upload_bytes'    => array( 'type' => 'integer' ),
				'memory_limit'        => array(
					'type'        => 'integer',
					'description' => 'Bytes; -1 means unlimited.',
				),
				'max_execution_time'  => array(
					'type'        => 'integer',
					'description' => 'Seconds; 0 means unlimited.',
				),
				'detected_plugins'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'    => array( 'type' => 'string' ),
							'version' => array( 'type' => 'string' ),
						),
					),
				),
				'analytics_lookup'    => array(
					'type' => 'string',
					'enum' => array( 'complete', 'importing', 'unknown' ),
				),
				'media'               => array(
					'type'       => 'object',
					'properties' => array(
						'upload_mime_types' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Image types POST /media accepts on this shop.',
						),
						'upload_max_bytes'  => array(
							'type'        => 'integer',
							'description' => 'Largest file POST /media accepts, in bytes.',
						),
					),
				),
				'user'                => array(
					'type'       => 'object',
					'properties' => array(
						'unfiltered_html' => array(
							'type'        => 'boolean',
							'description' => 'Whether the user behind the key may save unfiltered HTML.',
						),
					),
				),
			),
		);
	}
}
