<?php
/**
 * Section in WooCommerce > Status. Loaded only on that admin screen.
 *
 * @package Bulkbird\Helper
 */

declare( strict_types=1 );

namespace Bulkbird\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * System status section.
 */
final class Status_Report {

	/**
	 * Prints the section.
	 *
	 * @return void
	 */
	public static function render(): void {
		$rows = array(
			'Plugin version' => VERSION,
			'API version'    => (string) API_VERSION,
		);
		?>
		<table class="wc_status_table widefat" cellspacing="0">
			<thead>
				<tr>
					<th colspan="3" data-export-label="Bulkbird Helper"><h2>Bulkbird Helper</h2></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<td data-export-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?>:</td>
						<td class="help">&nbsp;</td>
						<td><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
