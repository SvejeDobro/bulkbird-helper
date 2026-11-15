<?php
/**
 * Namjerni kvarovi (scenariji H0.81–H0.90). Svaki je jedna zamjena teksta u kodu plugina.
 * bin/run-faults.sh ih primjenjuje jedan po jedan i očekuje da provjere (jedinični testovi + čuvari) PADNU.
 * Ako uz neki kvar provjere prođu, testovi imaju rupu.
 *
 * Polja: id scenarija, opis, datoteka, tekst koji se traži (mora postojati točno jednom), zamjena.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

return array(
	array(
		'H0.81',
		'permission_callback na /info uvijek dopušta',
		'includes/class-info-controller.php',
		"return Permissions::require_capability( 'manage_woocommerce' );",
		'return true;',
	),
	array(
		'H0.82',
		'uklonjena provjera sposobnosti (dovoljno je biti prijavljen)',
		'includes/class-permissions.php',
		'if ( ! current_user_can( $capability ) ) {',
		'if ( false ) {',
	),
	array(
		'H0.83',
		'filter za WooCommerce autentikaciju traži "sadrži bulkbird" umjesto strogog prefiksa',
		'bulkbird-helper.php',
		"return str_starts_with( \$route, '/' . ROUTE_PREFIX . '/' );",
		'return str_contains( $route, ROUTE_PREFIX );',
	),
	array(
		'H0.84',
		'dodan wp_enqueue_style',
		'bulkbird-helper.php',
		"add_action( 'rest_api_init', __NAMESPACE__ . '\\\\register_rest_routes' );",
		"add_action( 'rest_api_init', __NAMESPACE__ . '\\\\register_rest_routes' );\nadd_action( 'wp_enqueue_scripts', static function (): void { wp_enqueue_style( 'bulkbird-helper', plugins_url( 'a.css', __FILE__ ), array(), VERSION ); } );",
	),
	array(
		'H0.85',
		'dodana kuka na init koja čita opciju iz baze',
		'bulkbird-helper.php',
		"add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\\\declare_hpos_compatibility' );",
		"add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\\\declare_hpos_compatibility' );\nadd_action( 'init', static function (): void { get_option( OPTION_DB_VERSION ); } );",
	),
	array(
		'H0.86',
		'/info vraća putanju na disku',
		'includes/class-info-controller.php',
		"'analytics_lookup'    => Environment::analytics_lookup_state(),",
		"'analytics_lookup'    => Environment::analytics_lookup_state(),\n\t\t\t'root'                => ABSPATH,",
	),
	array(
		'H0.87',
		'uklonjena provjera multisitea pri aktivaciji',
		'includes/class-activation.php',
		'if ( is_multisite() ) {',
		'if ( false ) {',
	),
	array(
		'H0.88',
		'opcija plugina se automatski učitava',
		'includes/class-activation.php',
		"add_option( OPTION_DB_VERSION, '0', '', false );",
		"add_option( OPTION_DB_VERSION, '0', '', true );",
	),
	array(
		'H0.89',
		'deinstalacija ne briše vlastitu opciju',
		'uninstall.php',
		"delete_option( 'bulkbird_helper_db_version' );",
		'// (kvar) opcija ostaje.',
	),
	array(
		'H0.90',
		'poruka greške otkriva putanju datoteke',
		'includes/class-permissions.php',
		"__( 'Authentication is required.', 'bulkbird-helper' ),",
		"__( 'Authentication is required.', 'bulkbird-helper' ) . ' ' . __FILE__,",
	),
	array(
		'H0.83b',
		'rest_route iz tijela zahtjeva se ne provjerava (ključ se može proširiti na tuđu putanju)',
		'bulkbird-helper.php',
		"if ( isset( \$_POST['rest_route'] ) ) {",
		"if ( false && isset( \$_POST['rest_route'] ) ) {",
	),
	array(
		'H0.18',
		'odgovori plugina ne zabranjuju cache',
		'bulkbird-helper.php',
		"\$response->header( 'Cache-Control', 'no-store, private, max-age=0' );",
		'unset( $server );',
	),
);
