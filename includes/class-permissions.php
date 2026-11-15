<?php
/**
 * Capability checks shared by all REST routes.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Permission callbacks.
 */
final class Permissions {

	/**
	 * Requires an authenticated user with the given capability.
	 *
	 * @param string $capability WordPress capability.
	 * @return true|\WP_Error True when allowed; 401 when nobody is authenticated; 403 when the capability is missing.
	 */
	public static function require_capability( string $capability ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'bulkbird_unauthorized',
				__( 'Authentication is required.', 'bulkbird-helper' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error(
				'bulkbird_forbidden',
				__( 'You are not allowed to use this endpoint.', 'bulkbird-helper' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
