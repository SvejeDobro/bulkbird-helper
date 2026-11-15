<?php
/**
 * Per-user rate limiting for this plugin's REST routes.
 *
 * One row per user in the plugin's own table, updated with a single atomic
 * statement per request. The table is created on the first request to one of
 * this plugin's routes, never on storefront pages. With a persistent object
 * cache the cache is used instead and the table is not touched.
 *
 * Unauthenticated requests are not counted (there is no user, and counting by
 * IP address would mean storing personal data).
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window rate limiter.
 */
final class Rate_Limiter {

	public const TABLE       = 'bulkbird_rate';
	public const CACHE_GROUP = 'bulkbird_helper_rate';

	/**
	 * Limits per window.
	 *
	 * @return array{requests: int, uploads: int, window: int}
	 */
	public static function limits(): array {
		$defaults = array(
			'requests' => 120,
			'uploads'  => 30,
			'window'   => 60,
		);

		/**
		 * Filters the rate limits of the Bulkbird Helper REST routes.
		 *
		 * @param array{requests: int, uploads: int, window: int} $limits Requests and uploads allowed per user per window (seconds).
		 */
		$limits = apply_filters( 'bulkbird_helper_rate_limits', $defaults );
		$limits = is_array( $limits ) ? array_merge( $defaults, $limits ) : $defaults;

		return array(
			'requests' => max( 1, (int) $limits['requests'] ),
			'uploads'  => max( 1, (int) $limits['uploads'] ),
			'window'   => max( 1, (int) $limits['window'] ),
		);
	}

	/**
	 * Start of the fixed window that contains the given moment.
	 *
	 * @param int $now    Unix time.
	 * @param int $window Window length in seconds.
	 * @return int
	 */
	public static function window_start( int $now, int $window ): int {
		return $now - ( $now % $window );
	}

	/**
	 * Decides whether a request is within the limits. Pure function.
	 *
	 * @param int                                             $hits         Requests of this user in the current window, this one included.
	 * @param int                                             $upload_hits  Uploads of this user in the current window, this one included.
	 * @param bool                                            $is_upload    Whether this request is an upload.
	 * @param array{requests: int, uploads: int, window: int} $limits       Limits.
	 * @param int                                             $window_start Start of the current window.
	 * @param int                                             $now          Unix time.
	 * @return array{allowed: bool, retry_after: int}
	 */
	public static function evaluate( int $hits, int $upload_hits, bool $is_upload, array $limits, int $window_start, int $now ): array {
		$over = $hits > $limits['requests'] || ( $is_upload && $upload_hits > $limits['uploads'] );

		return array(
			'allowed'     => ! $over,
			'retry_after' => $over ? max( 1, $window_start + $limits['window'] - $now ) : 0,
		);
	}

	/**
	 * Counts this request for the user and says whether it may proceed.
	 *
	 * @param int  $user_id   Authenticated user; the limit is per user, shared by all of their API keys.
	 * @param bool $is_upload Whether the request is an upload.
	 * @return true|\WP_Error True, or a 429 error carrying "retry_after" seconds.
	 */
	public static function check( int $user_id, bool $is_upload ) {
		if ( $user_id <= 0 ) {
			return true;
		}

		$limits       = self::limits();
		$now          = time();
		$window_start = self::window_start( $now, $limits['window'] );
		$counts       = self::hit( $user_id, $window_start, $is_upload, $limits['window'] );

		if ( null === $counts ) {
			// The counter could not be stored. Refusing every request would break the shop's
			// integration over a bookkeeping problem, so the request proceeds uncounted.
			return true;
		}

		$verdict = self::evaluate( $counts['hits'], $counts['upload_hits'], $is_upload, $limits, $window_start, $now );
		if ( $verdict['allowed'] ) {
			return true;
		}

		return new \WP_Error(
			'bulkbird_rate_limited',
			__( 'Too many requests. Try again later.', 'bulkbird-helper' ),
			array(
				'status'      => 429,
				'retry_after' => $verdict['retry_after'],
			)
		);
	}

	/**
	 * Increments the user's counters for the current window and returns them.
	 *
	 * @param int  $user_id      User.
	 * @param int  $window_start Start of the current window.
	 * @param bool $is_upload    Whether to count an upload as well.
	 * @param int  $window       Window length in seconds.
	 * @return array{hits: int, upload_hits: int}|null Null when the counter could not be stored.
	 */
	private static function hit( int $user_id, int $window_start, bool $is_upload, int $window ): ?array {
		if ( wp_using_ext_object_cache() ) {
			return self::hit_object_cache( $user_id, $window_start, $is_upload, $window );
		}

		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$sql   = $wpdb->prepare(
			'INSERT INTO %i (user_id, window_start, hits, upload_hits) VALUES (%d, %d, 1, %d)
			ON DUPLICATE KEY UPDATE
				hits = IF(window_start = VALUES(window_start), hits + 1, 1),
				upload_hits = IF(window_start = VALUES(window_start), upload_hits + VALUES(upload_hits), VALUES(upload_hits)),
				window_start = VALUES(window_start)',
			$table,
			$user_id,
			$window_start,
			$is_upload ? 1 : 0
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table; the statement is prepared above; a counter must not be cached.
		$suppress = $wpdb->suppress_errors( true );
		$done     = $wpdb->query( $sql );
		if ( false === $done ) {
			// First request after installation or upgrade: the table does not exist yet.
			self::create_table();
			$done = $wpdb->query( $sql );
		}
		$row = false === $done ? null : $wpdb->get_row(
			$wpdb->prepare( 'SELECT hits, upload_hits FROM %i WHERE user_id = %d', $table, $user_id ),
			ARRAY_A
		);
		$wpdb->suppress_errors( $suppress );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'hits'        => (int) $row['hits'],
			'upload_hits' => (int) $row['upload_hits'],
		);
	}

	/**
	 * Same counting in a persistent object cache.
	 *
	 * @param int  $user_id      User.
	 * @param int  $window_start Start of the current window.
	 * @param bool $is_upload    Whether to count an upload as well.
	 * @param int  $window       Window length in seconds.
	 * @return array{hits: int, upload_hits: int}|null
	 */
	private static function hit_object_cache( int $user_id, int $window_start, bool $is_upload, int $window ): ?array {
		$counts = array();
		foreach ( array(
			'hits'        => true,
			'upload_hits' => $is_upload,
		) as $name => $increment ) {
			$key = $name . ':' . $user_id . ':' . $window_start;
			wp_cache_add( $key, 0, self::CACHE_GROUP, 2 * $window );
			$value = $increment ? wp_cache_incr( $key, 1, self::CACHE_GROUP ) : wp_cache_get( $key, self::CACHE_GROUP );
			if ( false === $value ) {
				return null;
			}
			$counts[ $name ] = (int) $value;
		}

		return array(
			'hits'        => $counts['hits'],
			'upload_hits' => $counts['upload_hits'],
		);
	}

	/**
	 * Creates the counter table and records the schema version.
	 *
	 * @return void
	 */
	private static function create_table(): void {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; schema statement with no user input ($collate comes from wpdb).
		$wpdb->query(
			$wpdb->prepare(
				'CREATE TABLE IF NOT EXISTS %i (
					user_id BIGINT UNSIGNED NOT NULL,
					window_start INT UNSIGNED NOT NULL,
					hits INT UNSIGNED NOT NULL,
					upload_hits INT UNSIGNED NOT NULL,
					PRIMARY KEY  (user_id)
				)',
				$wpdb->prefix . self::TABLE
			) . ' ' . $collate
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_option( OPTION_DB_VERSION, '1', false );
	}
}
