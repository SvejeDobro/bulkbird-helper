<?php
/**
 * GET and POST /bulkbird/v1/media
 *
 * The routes are registered in bulkbird-helper.php; this class is loaded only
 * when one of them is called.
 *
 * The upload never assigns the image to a product and never deletes or changes
 * anything that already exists in the shop.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Media endpoints.
 */
final class Media_Controller {

	private const LOCK_WAIT_SECONDS = 10;

	/**
	 * GET /media: one page of images.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_items( \WP_REST_Request $request ) {
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? $per_page : 50;
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$args = array(
			'search'   => (string) $request->get_param( 'search' ),
			'orderby'  => (string) $request->get_param( 'orderby' ),
			'page'     => $page,
			'per_page' => $per_page,
		);

		$modified_after = (string) $request->get_param( 'modified_after' );
		if ( '' !== $modified_after ) {
			$timestamp = rest_parse_date( $modified_after );
			if ( false === $timestamp ) {
				return Media_Rules::error( 'rest_invalid_param', __( 'Invalid parameter(s): modified_after', 'bulkbird-helper' ), 400 );
			}
			$args['modified_after'] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		$found    = Media_Library::find( $args );
		$response = new \WP_REST_Response( Media_Library::items( $found['ids'] ), 200 );
		$response->header( 'X-WP-Total', (string) $found['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $found['total'] / $per_page ) );

		return $response;
	}

	/**
	 * POST /media: stores one image, once per client_upload_id.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload( \WP_REST_Request $request ) {
		$max_bytes = Media_Rules::upload_max_bytes();

		// When the body is larger than PHP accepts, PHP hands over neither fields nor files.
		$files = $request->get_file_params();
		if ( array() === $files && array() === $request->get_body_params() && $this->declared_length() > $max_bytes ) {
			return Media_Rules::error( 'bulkbird_file_too_large', __( 'The file is larger than this shop accepts.', 'bulkbird-helper' ), 413 );
		}

		$upload_id = $request->get_param( 'client_upload_id' );
		if ( ! is_string( $upload_id ) || '' === $upload_id ) {
			return new \WP_Error(
				'rest_missing_callback_param',
				__( 'Missing parameter(s): client_upload_id', 'bulkbird-helper' ),
				array(
					'status' => 400,
					'params' => array( 'client_upload_id' ),
				)
			);
		}
		$upload_id = Media_Rules::normalize_upload_id( $upload_id );

		$file = $this->single_file( $files );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$inspected = Media_Rules::inspect_file( $file['tmp_name'], (int) $file['size'], Media_Rules::upload_mime_types(), $max_bytes );
		if ( is_wp_error( $inspected ) ) {
			return $inspected;
		}

		if ( ! $this->acquire_lock( $upload_id ) ) {
			$error = Media_Rules::error( 'bulkbird_upload_in_progress', __( 'An upload with this id is still in progress.', 'bulkbird-helper' ), 409 );
			$error->add_data(
				array(
					'status'      => 409,
					'retry_after' => 5,
				)
			);
			return $error;
		}

		try {
			$existing = $this->find_by_upload_id( $upload_id );
			$decision = Media_Rules::decide( $existing, $inspected['sha256'] );

			if ( 'trashed' === $decision ) {
				return Media_Rules::error( 'bulkbird_upload_trashed', __( 'An image with this upload id is in the trash.', 'bulkbird-helper' ), 409 );
			}
			if ( 'conflict' === $decision ) {
				return Media_Rules::error( 'bulkbird_upload_id_conflict', __( 'This upload id was already used for a different file.', 'bulkbird-helper' ), 409 );
			}
			if ( 'existing' === $decision && null !== $existing ) {
				return $this->item_response( $existing['id'], 200 );
			}

			$attachment_id = $this->store( $file, $inspected, $upload_id, $request );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			return $this->item_response( $attachment_id, 201 );
		} finally {
			$this->release_lock( $upload_id );
		}
	}

	/**
	 * Content-Length declared by the client.
	 *
	 * @return int
	 */
	private function declared_length(): int {
		return isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
	}

	/**
	 * The one uploaded file of the request.
	 *
	 * @param array<string, mixed> $files File parameters of the request.
	 * @return array{name: string, tmp_name: string, size: int, error: int, type: string}|\WP_Error
	 */
	private function single_file( array $files ) {
		if ( ! isset( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return Media_Rules::error( 'bulkbird_missing_file', __( 'No file was sent in the "file" field.', 'bulkbird-helper' ), 400 );
		}
		if ( count( $files ) > 1 || is_array( $files['file']['name'] ?? null ) ) {
			return Media_Rules::error( 'bulkbird_single_file_only', __( 'Send exactly one file per request.', 'bulkbird-helper' ), 400 );
		}

		$file  = $files['file'];
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return Media_Rules::error( 'bulkbird_file_too_large', __( 'The file is larger than this shop accepts.', 'bulkbird-helper' ), 413 );
		}
		if ( UPLOAD_ERR_PARTIAL === $error ) {
			return Media_Rules::error( 'bulkbird_upload_incomplete', __( 'The file arrived incomplete.', 'bulkbird-helper' ), 400 );
		}
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return Media_Rules::error( 'bulkbird_missing_file', __( 'No file was sent in the "file" field.', 'bulkbird-helper' ), 400 );
		}
		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return Media_Rules::error( 'bulkbird_upload_failed', __( 'The file could not be stored.', 'bulkbird-helper' ), 500 );
		}

		return array(
			'name'     => isset( $file['name'] ) && is_string( $file['name'] ) ? $file['name'] : '',
			'tmp_name' => $file['tmp_name'],
			'size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
			'error'    => $error,
			'type'     => '',
		);
	}

	/**
	 * Moves the file into the uploads folder and creates the attachment.
	 *
	 * @param array{name: string, tmp_name: string, size: int, error: int, type: string}      $file      Uploaded file.
	 * @param array{mime: string, extension: string, width: int, height: int, sha256: string} $inspected What the content is.
	 * @param string                                                                          $upload_id Normalised client upload id.
	 * @param \WP_REST_Request                                                                $request   Request.
	 * @return int|\WP_Error Attachment id.
	 */
	private function store( array $file, array $inspected, string $upload_id, \WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$failed = Media_Rules::error( 'bulkbird_upload_failed', __( 'The file could not be stored.', 'bulkbird-helper' ), 500 );

		$filename     = Media_Rules::safe_filename( $file['name'], $inspected['extension'] );
		$file['name'] = $filename;
		$file['type'] = $inspected['mime'];

		$moved = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array( $inspected['extension'] => $inspected['mime'] ),
			)
		);
		if ( ! is_array( $moved ) || isset( $moved['error'] ) || empty( $moved['file'] ) || empty( $moved['url'] ) ) {
			return $failed;
		}

		$title = $request->get_param( 'title' );
		$title = is_string( $title ) && '' !== trim( $title ) ? sanitize_text_field( $title ) : sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) );

		$attachment_id = wp_insert_attachment(
			Media_Rules::attachment_args( $title, $inspected['mime'], (string) $moved['url'], get_current_user_id() ),
			(string) $moved['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			// Nothing half-done may stay behind: remove the file this request has just stored.
			wp_delete_file( (string) $moved['file'] );
			return $failed;
		}
		$attachment_id = (int) $attachment_id;

		// Recorded before the sizes are generated, so that a retry after a crash still finds this upload.
		update_post_meta( $attachment_id, Media_Rules::META_UPLOAD_ID, $upload_id );
		update_post_meta( $attachment_id, Media_Rules::META_SHA256, $inspected['sha256'] );

		$alt = $request->get_param( 'alt' );
		if ( is_string( $alt ) && '' !== trim( $alt ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, (string) $moved['file'] ) );

		return $attachment_id;
	}

	/**
	 * Attachment that already carries this upload id.
	 *
	 * @param string $upload_id Normalised client upload id.
	 * @return array{id: int, status: string, sha256: string}|null
	 */
	private function find_by_upload_id( string $upload_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Must see the database, not a cache: this is the duplicate check.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.ID, p.post_status FROM %i p INNER JOIN %i m ON m.post_id = p.ID
				WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = 'attachment'
				ORDER BY p.ID ASC LIMIT 1",
				$wpdb->posts,
				$wpdb->postmeta,
				Media_Rules::META_UPLOAD_ID,
				$upload_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'id'     => (int) $row['ID'],
			'status' => (string) $row['post_status'],
			'sha256' => (string) get_post_meta( (int) $row['ID'], Media_Rules::META_SHA256, true ),
		);
	}

	/**
	 * Response with one item.
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $status        201 for a new image, 200 for one that already existed.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function item_response( int $attachment_id, int $status ) {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post ) {
			return Media_Rules::error( 'bulkbird_upload_failed', __( 'The file could not be stored.', 'bulkbird-helper' ), 500 );
		}

		return new \WP_REST_Response( Media_Library::item( $post ), $status );
	}

	/**
	 * Name of the database lock for an upload id. Unique per site, at most 64 characters.
	 *
	 * @param string $upload_id Normalised client upload id.
	 * @return string
	 */
	private function lock_name( string $upload_id ): string {
		global $wpdb;

		return 'bbh_' . md5( $wpdb->dbname . '|' . $wpdb->prefix . '|' . $upload_id );
	}

	/**
	 * Waits for the lock of an upload id, so that two simultaneous requests cannot both create the image.
	 *
	 * @param string $upload_id Normalised client upload id.
	 * @return bool
	 */
	private function acquire_lock( string $upload_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Advisory lock; nothing to cache.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name( $upload_id ), self::LOCK_WAIT_SECONDS ) );
	}

	/**
	 * Releases the lock of an upload id.
	 *
	 * @param string $upload_id Normalised client upload id.
	 * @return void
	 */
	private function release_lock( string $upload_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Advisory lock; nothing to cache.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name( $upload_id ) ) );
	}
}
