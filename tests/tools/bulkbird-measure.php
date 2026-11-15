<?php
/**
 * Plugin Name: Bulkbird Measure (privremeni mjerni mu-plugin, samo za testne stranice)
 * Description: Bilježi broj upita, učitane datoteke i vrijeme PHP-a za zahtjeve koji nose tajno zaglavlje. Postavlja se samo za vrijeme mjerenja (odluka P6) i odmah uklanja.
 *
 * Ovo je predložak. bin/measure.sh install upisuje SHA-256 tajne vrijednosti zaglavlja
 * i putanju loga umjesto oznaka __HASH__ i __LOG__. Sama tajna je samo u test.env.
 *
 * Ne mijenja odgovor, ne šalje zaglavlja, ne dira bazu. Bez ispravnog zaglavlja ne radi ništa.
 *
 * @package Bulkbird\Helper\Tests
 */

defined( 'ABSPATH' ) || exit;

( static function (): void {
	$hash = '__HASH__';
	$log  = '__LOG__';

	$given = isset( $_SERVER['HTTP_X_BBH_MEASURE'] ) && is_string( $_SERVER['HTTP_X_BBH_MEASURE'] ) ? $_SERVER['HTTP_X_BBH_MEASURE'] : '';
	if ( '' === $given || 64 !== strlen( $hash ) || ! hash_equals( $hash, hash( 'sha256', $given ) ) ) {
		return;
	}

	$label = isset( $_SERVER['HTTP_X_BBH_RUN'] ) && is_string( $_SERVER['HTTP_X_BBH_RUN'] ) ? preg_replace( '/[^A-Za-z0-9_.-]/', '', $_SERVER['HTTP_X_BBH_RUN'] ) : '';

	register_shutdown_function(
		static function () use ( $log, $label ): void {
			global $wpdb;

			$files        = get_included_files();
			$plugin_files = array();
			foreach ( $files as $file ) {
				$pos = strpos( $file, '/plugins/bulkbird-helper/' );
				if ( false !== $pos ) {
					$plugin_files[] = substr( $file, $pos + strlen( '/plugins/bulkbird-helper/' ) );
				}
			}

			$line = array(
				'run'            => $label,
				'path'           => isset( $_SERVER['REQUEST_URI'] ) ? (string) strtok( (string) $_SERVER['REQUEST_URI'], '?' ) : '',
				'method'         => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
				'status'         => http_response_code(),
				'queries'        => isset( $wpdb ) ? (int) $wpdb->num_queries : -1,
				'included_files' => count( $files ),
				'plugin_files'   => $plugin_files,
				'php_ms'         => round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000, 2 ),
				'logged_in'      => function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
				'opcache'        => (bool) ini_get( 'opcache.enable' ),
				'main_cached'    => function_exists( 'opcache_is_script_cached' ) && defined( 'WP_PLUGIN_DIR' ) && opcache_is_script_cached( WP_PLUGIN_DIR . '/bulkbird-helper/bulkbird-helper.php' ),
				'sapi'           => PHP_SAPI,
			);

			@file_put_contents( $log, json_encode( $line ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
		}
	);
} )();
