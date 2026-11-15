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
		return self::require_capabilities( array( $capability ) );
	}

	/**
	 * Requires an authenticated user who has every one of the given capabilities.
	 *
	 * @param string[] $capabilities WordPress capabilities; all are required.
	 * @return true|\WP_Error True when allowed; 401 when nobody is authenticated; 403 when any capability is missing.
	 */
	public static function require_capabilities( array $capabilities ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'bulkbird_unauthorized',
				__( 'Authentication is required.', 'bulkbird-helper' ),
				array( 'status' => 401 )
			);
		}

		foreach ( $capabilities as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				return new \WP_Error(
					'bulkbird_forbidden',
					__( 'You are not allowed to use this endpoint.', 'bulkbird-helper' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}
}
