<?php
/**
 * Rules for images: which types are accepted, how large they may be,
 * and what a repeated upload means. No database access in this class.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Image rules.
 */
final class Media_Rules {

	/**
	 * Image types this plugin lists and accepts, with their canonical extension. SVG is never accepted.
	 */
	public const TYPES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
		'image/avif' => 'avif',
	);

	public const HARD_MAX_BYTES   = 20971520; // 20 MB.
	public const MAX_PIXELS       = 64000000; // 64 megapixels.
	public const MAX_TITLE_LENGTH = 255;
	public const MAX_ALT_LENGTH   = 1000;

	public const META_UPLOAD_ID = '_bulkbird_upload_id';
	public const META_SHA256    = '_bulkbird_upload_sha256';

	/**
	 * MIME types listed by GET /media.
	 *
	 * @return string[]
	 */
	public static function listed_mime_types(): array {
		return array_keys( self::TYPES );
	}

	/**
	 * MIME types this shop accepts for upload: the plugin's list, minus what the shop
	 * itself disallows, minus what the server cannot process.
	 *
	 * @return string[]
	 */
	public static function upload_mime_types(): array {
		$site = array_values( get_allowed_mime_types() );

		return array_values(
			array_filter(
				self::listed_mime_types(),
				static function ( string $mime ) use ( $site ): bool {
					if ( ! in_array( $mime, $site, true ) ) {
						return false;
					}
					// JPEG, PNG and GIF are always processable; WebP and AVIF depend on the server's image library.
					return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true )
						|| wp_image_editor_supports( array( 'mime_type' => $mime ) );
				}
			)
		);
	}

	/**
	 * Largest accepted upload in bytes.
	 *
	 * @return int
	 */
	public static function upload_max_bytes(): int {
		return self::effective_max_bytes( (int) wp_max_upload_size() );
	}

	/**
	 * The smaller of the shop's limit and the plugin's hard limit.
	 *
	 * @param int $wp_max_bytes What WordPress allows.
	 * @return int
	 */
	public static function effective_max_bytes( int $wp_max_bytes ): int {
		return $wp_max_bytes > 0 ? min( $wp_max_bytes, self::HARD_MAX_BYTES ) : self::HARD_MAX_BYTES;
	}

	/**
	 * Inspects an uploaded temporary file by its content, never by its name.
	 *
	 * @param string   $path          Path of the temporary file.
	 * @param int      $size          Size reported for the file.
	 * @param string[] $accepted      MIME types the shop accepts.
	 * @param int      $max_bytes     Largest accepted size.
	 * @return array{mime: string, extension: string, width: int, height: int, sha256: string}|\WP_Error
	 */
	public static function inspect_file( string $path, int $size, array $accepted, int $max_bytes ) {
		if ( $size > $max_bytes || ( is_file( $path ) && filesize( $path ) > $max_bytes ) ) {
			return self::error( 'bulkbird_file_too_large', __( 'The file is larger than this shop accepts.', 'bulkbird-helper' ), 413 );
		}
		if ( ! is_file( $path ) || 0 === filesize( $path ) ) {
			return self::error( 'bulkbird_invalid_image', __( 'The file is empty or unreadable.', 'bulkbird-helper' ), 400 );
		}

		$mime = wp_get_image_mime( $path );
		if ( ! is_string( $mime ) || ! isset( self::TYPES[ $mime ] ) || ! in_array( $mime, $accepted, true ) ) {
			return self::error( 'bulkbird_unsupported_type', __( 'This file type is not accepted.', 'bulkbird-helper' ), 415 );
		}

		$dimensions = wp_getimagesize( $path );
		$width      = is_array( $dimensions ) ? (int) $dimensions[0] : 0;
		$height     = is_array( $dimensions ) ? (int) $dimensions[1] : 0;
		if ( $width < 1 || $height < 1 ) {
			return self::error( 'bulkbird_invalid_image', __( 'The image cannot be read.', 'bulkbird-helper' ), 400 );
		}
		if ( $width * $height > self::MAX_PIXELS ) {
			return self::error( 'bulkbird_image_too_large', __( 'The image dimensions are too large.', 'bulkbird-helper' ), 400 );
		}

		$sha256 = hash_file( 'sha256', $path );
		if ( ! is_string( $sha256 ) ) {
			return self::error( 'bulkbird_invalid_image', __( 'The file is empty or unreadable.', 'bulkbird-helper' ), 400 );
		}

		return array(
			'mime'      => $mime,
			'extension' => self::TYPES[ $mime ],
			'width'     => $width,
			'height'    => $height,
			'sha256'    => $sha256,
		);
	}

	/**
	 * File name to store: the client's base name, sanitised, with the extension of the real content.
	 *
	 * @param string $client_name Name sent by the client.
	 * @param string $extension   Canonical extension of the detected type.
	 * @return string
	 */
	public static function safe_filename( string $client_name, string $extension ): string {
		$client_name = str_replace( '\\', '/', $client_name );
		$base        = pathinfo( wp_basename( $client_name ), PATHINFO_FILENAME );
		$base        = sanitize_file_name( $base );
		$base        = trim( str_replace( '.', '-', $base ), '-_ ' );
		if ( '' === $base ) {
			$base = 'image';
		}
		if ( strlen( $base ) > 150 ) {
			$base = substr( $base, 0, 150 );
		}

		return $base . '.' . $extension;
	}

	/**
	 * Canonical form of a client upload id.
	 *
	 * @param string $upload_id UUID in any letter case.
	 * @return string
	 */
	public static function normalize_upload_id( string $upload_id ): string {
		return strtolower( trim( $upload_id ) );
	}

	/**
	 * What a request with a given upload id means, given what is already stored. Pure function.
	 *
	 * @param array{status: string, sha256: string}|null $existing Attachment already carrying this upload id, if any.
	 * @param string                                     $sha256   Hash of the file in this request.
	 * @return string "create", "existing", "conflict" or "trashed".
	 */
	public static function decide( ?array $existing, string $sha256 ): string {
		if ( null === $existing ) {
			return 'create';
		}
		if ( 'trash' === $existing['status'] ) {
			return 'trashed';
		}
		if ( ! hash_equals( $existing['sha256'], $sha256 ) ) {
			return 'conflict';
		}

		return 'existing';
	}

	/**
	 * State reported by GET /media/uploads/<client_upload_id>. Pure function.
	 *
	 * A request that is still running wins over what is already stored: the attachment
	 * exists before its sizes are generated, and the client should wait for the end.
	 *
	 * @param array{status: string}|null $existing Attachment carrying this upload id, if any.
	 * @param bool                       $locked   Whether an upload with this id is running right now.
	 * @return string "in_progress", "trashed", "stored" or "missing".
	 */
	public static function upload_state( ?array $existing, bool $locked ): string {
		if ( $locked ) {
			return 'in_progress';
		}
		if ( null === $existing ) {
			return 'missing';
		}

		return 'trash' === $existing['status'] ? 'trashed' : 'stored';
	}

	/**
	 * Body of the upload status response. Pure function.
	 *
	 * @param string                    $upload_id Normalised client upload id.
	 * @param string                    $state     "in_progress", "trashed" or "stored".
	 * @param string                    $sha256    Stored hash; empty when unknown.
	 * @param array<string, mixed>|null $item      Item as in GET /media, for "stored".
	 * @return array{client_upload_id: string, state: string, sha256: string|null, item: array<string, mixed>|null}
	 */
	public static function upload_status_body( string $upload_id, string $state, string $sha256, ?array $item ): array {
		$known = 'in_progress' !== $state && 1 === preg_match( '/^[0-9a-f]{64}$/', $sha256 );

		return array(
			'client_upload_id' => $upload_id,
			'state'            => $state,
			'sha256'           => $known ? $sha256 : null,
			'item'             => 'stored' === $state ? $item : null,
		);
	}

	/**
	 * Arguments for the new attachment. Never attached to a product or any other post.
	 *
	 * @param string $title    Title.
	 * @param string $mime     MIME type.
	 * @param string $guid_url Public URL of the stored file.
	 * @param int    $user_id  Owner: the user the API key belongs to.
	 * @return array<string, mixed>
	 */
	public static function attachment_args( string $title, string $mime, string $guid_url, int $user_id ): array {
		return array(
			'post_title'     => $title,
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => $mime,
			'post_parent'    => 0,
			'post_author'    => $user_id,
			'guid'           => $guid_url,
		);
	}

	/**
	 * Error without paths or request content.
	 *
	 * @param string $code    Error code.
	 * @param string $message Short message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	public static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
