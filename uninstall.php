<?php
/**
 * Uninstall: removes only what this plugin created.
 *
 * Never touches products, media, orders or other plugins' data.
 * The attachment meta "_bulkbird_upload_id" (later phases) stays on purpose.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bulkbird_helper_db_version' );
