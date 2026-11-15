<?php
/**
 * Activation routine. Loaded only while the plugin is being activated.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Activation.
 */
final class Activation {

	/**
	 * Refuses multisite (network-wide and per-site alike) and records the schema version.
	 *
	 * Phase 0 creates no tables and no cron jobs.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( is_multisite() ) {
			wp_die(
				esc_html__( 'Bulkbird Helper v1 does not support WordPress multisite. The plugin was not activated.', 'bulkbird-helper' ),
				esc_html__( 'Bulkbird Helper', 'bulkbird-helper' ),
				array(
					'response'  => 200,
					'back_link' => true,
				)
			);
		}

		// Not autoloaded: the front end must never pay for this plugin.
		add_option( OPTION_DB_VERSION, '0', '', false );
	}
}
