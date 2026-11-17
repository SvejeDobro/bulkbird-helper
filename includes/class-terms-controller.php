<?php
/**
 * GET /bulkbird/v1/terms/order
 *
 * Tells, for every term of a taxonomy, whether WooCommerce has a stored order for it and
 * which one. WooCommerce REST reports 0 both for "no record" and for a stored 0, although
 * the two are sorted differently and a term without a record is left out of the category
 * widget. Reads only; changes nothing in the shop.
 *
 * The route is registered in bulkbird-helper.php; this class is loaded only when it is called.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Term order endpoint.
 */
final class Terms_Controller {

	/**
	 * Taxonomies this endpoint may be asked about.
	 */
	public const TAXONOMIES = array( 'product_cat', 'product_brand' );

	public const META_ORDER   = 'order';
	public const MAX_PER_PAGE = 1000;

	/**
	 * GET /terms/order: one page of terms, in the order the shop's own default listing gives.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function order( \WP_REST_Request $request ) {
		$taxonomy = (string) $request->get_param( 'taxonomy' );
		if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			return new \WP_Error( 'rest_invalid_param', __( 'Invalid parameter(s): taxonomy', 'bulkbird-helper' ), array( 'status' => 400 ) );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'bulkbird_taxonomy_not_found', __( 'This shop does not have this taxonomy.', 'bulkbird-helper' ), array( 'status' => 404 ) );
		}

		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( $per_page, self::MAX_PER_PAGE ) : 100;
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		// The default listing of the taxonomy, empty terms included, with no ordering argument of our
		// own: for product categories WooCommerce turns that into the shop's order, for brands it is
		// by name. All ids are read at once and paged here, so that every page is cut from one order.
		$parents = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'id=>parent',
			)
		);
		if ( ! is_array( $parents ) ) {
			return new \WP_Error( 'bulkbird_terms_unavailable', __( 'The terms could not be read.', 'bulkbird-helper' ), array( 'status' => 500 ) );
		}

		$slice = self::page( $parents, $page, $per_page );
		update_termmeta_cache( array_keys( $slice ) );

		$items = array();
		foreach ( $slice as $term_id => $parent ) {
			$exists  = metadata_exists( 'term', (int) $term_id, self::META_ORDER );
			$items[] = array(
				'id'     => (int) $term_id,
				'parent' => (int) $parent,
				'order'  => self::order_value( $exists, $exists ? get_term_meta( (int) $term_id, self::META_ORDER, true ) : null ),
			);
		}

		$total    = count( $parents );
		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * One page of an ordered id => parent map, keys and order preserved. Pure function.
	 *
	 * @param array<int|string, mixed> $parents  Term id => parent id, in listing order.
	 * @param int                      $page     Page, from 1.
	 * @param int                      $per_page Items per page.
	 * @return array<int|string, mixed>
	 */
	public static function page( array $parents, int $page, int $per_page ): array {
		return array_slice( $parents, ( max( 1, $page ) - 1 ) * max( 1, $per_page ), max( 1, $per_page ), true );
	}

	/**
	 * The stored order as the shop's own sorting reads it. Pure function.
	 *
	 * Null means "no record at all", which WooCommerce sorts before every number and leaves out of
	 * the category widget. A record that is not a number counts as 0, as it does in the shop's SQL.
	 *
	 * @param bool  $exists Whether the term has an order record.
	 * @param mixed $raw    Stored value.
	 * @return int|null
	 */
	public static function order_value( bool $exists, $raw ): ?int {
		if ( ! $exists ) {
			return null;
		}

		return is_numeric( $raw ) ? (int) $raw : 0;
	}
}
