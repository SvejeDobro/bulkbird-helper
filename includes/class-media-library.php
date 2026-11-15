<?php
/**
 * Reads images from the media library. Never writes.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Media library reader.
 */
final class Media_Library {

	/**
	 * Sizes tried, in order, when the wanted size does not exist for an image.
	 */
	private const SIZE_FALLBACKS = array(
		'thumbnail' => array( 'thumbnail', 'woocommerce_gallery_thumbnail', 'woocommerce_thumbnail', 'medium' ),
		'medium'    => array( 'medium', 'woocommerce_thumbnail', 'medium_large', 'woocommerce_single', 'large' ),
	);

	/**
	 * Builds the WHERE clause and its values. The search text only ever travels as a value.
	 *
	 * @param array{search?: string, modified_after?: string} $args       Filters; modified_after as "Y-m-d H:i:s" in GMT.
	 * @param string[]                                        $mime_types MIME types to list.
	 * @return array{where: string, values: array<int, string>, needs_file_join: bool}
	 */
	public static function where( array $args, array $mime_types ): array {
		$where  = "p.post_type = 'attachment' AND p.post_status IN ('inherit', 'private')";
		$values = array();

		$where .= ' AND p.post_mime_type IN (' . implode( ', ', array_fill( 0, count( $mime_types ), '%s' ) ) . ')';
		foreach ( $mime_types as $mime_type ) {
			$values[] = $mime_type;
		}

		$search          = isset( $args['search'] ) ? trim( $args['search'] ) : '';
		$needs_file_join = '' !== $search;
		if ( $needs_file_join ) {
			$like     = '%' . addcslashes( $search, '_%\\' ) . '%';
			$where   .= " AND (p.post_title LIKE %s OR SUBSTRING_INDEX(f.meta_value, '/', -1) LIKE %s)";
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $args['modified_after'] ) ) {
			$where   .= ' AND p.post_modified_gmt > %s';
			$values[] = $args['modified_after'];
		}

		return array(
			'where'           => $where,
			'values'          => $values,
			'needs_file_join' => $needs_file_join,
		);
	}

	/**
	 * ORDER BY for a requested ordering.
	 *
	 * @param string $orderby "date" or "title".
	 * @return string
	 */
	public static function order_by( string $orderby ): string {
		return 'title' === $orderby
			? 'p.post_title ASC, p.ID ASC'
			: 'p.post_date_gmt DESC, p.ID DESC';
	}

	/**
	 * One page of image ids and the total number of matching images.
	 *
	 * @param array{search?: string, modified_after?: string, orderby?: string, page?: int, per_page?: int} $args Query.
	 * @return array{ids: int[], total: int}
	 */
	public static function find( array $args ): array {
		global $wpdb;

		$parts    = self::where( $args, Media_Rules::listed_mime_types() );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$from     = 'FROM %i p';
		$tables   = array( $wpdb->posts );
		if ( $parts['needs_file_join'] ) {
			$from    .= " LEFT JOIN %i f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'";
			$tables[] = $wpdb->postmeta;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Read-only; every value and table name goes through prepare(); the interpolated parts are fixed SQL fragments built above from placeholders only.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) {$from} WHERE {$parts['where']}", array_merge( $tables, $parts['values'] ) )
		);

		$ids = array();
		if ( $total > 0 && ( $page - 1 ) * $per_page < $total ) {
			$order = self::order_by( (string) ( $args['orderby'] ?? 'date' ) );
			$ids   = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID {$from} WHERE {$parts['where']} ORDER BY {$order} LIMIT %d OFFSET %d",
					array_merge( $tables, $parts['values'], array( $per_page, ( $page - 1 ) * $per_page ) )
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return array(
			'ids'   => array_map( 'intval', $ids ),
			'total' => $total,
		);
	}

	/**
	 * Items for a list of ids, in the same order. Loads posts and meta in bulk (no query per item).
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array<int, array<string, mixed>>
	 */
	public static function items( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		_prime_post_caches( $ids, false, true );

		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$items[] = self::item( $post );
			}
		}

		return $items;
	}

	/**
	 * One item. Contains no author, no e-mail and no path on disk.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return array<string, mixed>
	 */
	public static function item( \WP_Post $post ): array {
		$meta = wp_get_attachment_metadata( $post->ID );
		$meta = is_array( $meta ) ? $meta : array();
		$file = (string) get_post_meta( $post->ID, '_wp_attached_file', true );
		$full = wp_get_attachment_url( $post->ID );
		$full = is_string( $full ) ? $full : '';

		return array(
			'id'           => (int) $post->ID,
			'title'        => (string) $post->post_title,
			'alt'          => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'filename'     => self::filename( $file, $full ),
			'mime'         => (string) $post->post_mime_type,
			'width'        => isset( $meta['width'] ) && (int) $meta['width'] > 0 ? (int) $meta['width'] : null,
			'height'       => isset( $meta['height'] ) && (int) $meta['height'] > 0 ? (int) $meta['height'] : null,
			'filesize'     => isset( $meta['filesize'] ) && (int) $meta['filesize'] > 0 ? (int) $meta['filesize'] : null,
			'date_gmt'     => self::date( $post->post_date_gmt ),
			'modified_gmt' => self::date( $post->post_modified_gmt ),
			'parent_id'    => (int) $post->post_parent,
			'urls'         => array(
				'thumbnail' => self::size_url( $post->ID, 'thumbnail', $full ),
				'medium'    => self::size_url( $post->ID, 'medium', $full ),
				'full'      => $full,
			),
		);
	}

	/**
	 * File name only: never a directory, never a path on disk.
	 *
	 * @param string $attached_file Value of _wp_attached_file (relative path).
	 * @param string $url           Public URL, used when the meta is missing.
	 * @return string
	 */
	public static function filename( string $attached_file, string $url ): string {
		$source = '' !== $attached_file ? $attached_file : (string) wp_parse_url( $url, PHP_URL_PATH );

		return wp_basename( str_replace( '\\', '/', $source ) );
	}

	/**
	 * Date as "YYYY-MM-DDTHH:MM:SS" in GMT.
	 *
	 * @param string $mysql_gmt MySQL datetime in GMT.
	 * @return string|null
	 */
	public static function date( string $mysql_gmt ): ?string {
		if ( '' === $mysql_gmt || '0000-00-00 00:00:00' === $mysql_gmt ) {
			return null;
		}

		return str_replace( ' ', 'T', $mysql_gmt );
	}

	/**
	 * URL of the wanted size, or of the nearest existing one, or of the full image. Never empty.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $size          "thumbnail" or "medium".
	 * @param string $full          URL of the full image.
	 * @return string
	 */
	private static function size_url( int $attachment_id, string $size, string $full ): string {
		foreach ( self::SIZE_FALLBACKS[ $size ] as $candidate ) {
			$source = wp_get_attachment_image_src( $attachment_id, $candidate );
			if ( is_array( $source ) && ! empty( $source[0] ) && ! empty( $source[3] ) ) {
				return (string) $source[0];
			}
		}

		return $full;
	}
}
