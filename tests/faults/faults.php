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
		'bulkbird-helper.php',
		"return rest_guard( array( 'manage_woocommerce' ), \$request );",
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
	// --- Paket 0.2.0 (faze 1 i 2) -------------------------------------------------------------
	array(
		'H2.55',
		'GET /media bez provjere sposobnosti',
		'bulkbird-helper.php',
		"function rest_can_read_media( \$request = null ) {\n\treturn rest_guard( array( 'upload_files', 'manage_woocommerce' ), \$request );",
		"function rest_can_read_media( \$request = null ) {\n\treturn true;",
	),
	array(
		'H2.56',
		'POST /media traži samo prijavu (bez upload_files i manage_woocommerce)',
		'bulkbird-helper.php',
		"function rest_can_upload_media( \$request = null ) {\n\treturn rest_guard( array( 'upload_files', 'manage_woocommerce' ), \$request );",
		"function rest_can_upload_media( \$request = null ) {\n\treturn rest_guard( array(), \$request );",
	),
	array(
		'H2.56b',
		'/media traži samo upload_files (bez manage_woocommerce; odluka P1)',
		'bulkbird-helper.php',
		"function rest_can_read_media( \$request = null ) {\n\treturn rest_guard( array( 'upload_files', 'manage_woocommerce' ), \$request );",
		"function rest_can_read_media( \$request = null ) {\n\treturn rest_guard( array( 'upload_files' ), \$request );",
	),
	array(
		'H2.57',
		'vrsta se određuje po nastavku imena, ne po sadržaju',
		'includes/class-media-rules.php',
		'$mime = wp_get_image_mime( $path );',
		"\$mime = 'image/jpeg';",
	),
	array(
		'H2.58',
		'SVG dodan na popis dopuštenih vrsta',
		'includes/class-media-rules.php',
		"'image/avif' => 'avif',",
		"'image/avif' => 'avif',\n\t\t'image/svg+xml' => 'svg',",
	),
	array(
		'H2.59',
		'bez provjere veličine datoteke',
		'includes/class-media-rules.php',
		'if ( $size > $max_bytes || ( is_file( $path ) && filesize( $path ) > $max_bytes ) ) {',
		'if ( false ) {',
	),
	array(
		'H2.59b',
		'bez provjere dimenzija slike',
		'includes/class-media-rules.php',
		'if ( $width * $height > self::MAX_PIXELS ) {',
		'if ( false ) {',
	),
	array(
		'H2.60',
		'ponovljeni client_upload_id uvijek stvara novu sliku',
		'includes/class-media-rules.php',
		"if ( null === \$existing ) {\n\t\t\treturn 'create';\n\t\t}",
		"if ( true ) {\n\t\t\treturn 'create';\n\t\t}",
	),
	array(
		'H2.61',
		'isti client_upload_id s drugom datotekom vraća postojeću sliku umjesto 409',
		'includes/class-media-rules.php',
		"if ( ! hash_equals( \$existing['sha256'], \$sha256 ) ) {",
		'if ( false ) {',
	),
	array(
		'H2.62',
		'nova slika se dodjeljuje nekom zapisu (post_parent)',
		'includes/class-media-rules.php',
		"'post_parent'    => 0,",
		"'post_parent'    => 123,",
	),
	array(
		'H2.63',
		'tekst pretrage spojen izravno u SQL',
		'includes/class-media-library.php',
		"\$where   .= \" AND (p.post_title LIKE %s OR SUBSTRING_INDEX(f.meta_value, '/', -1) LIKE %s)\";",
		"\$where   .= \" AND (p.post_title LIKE '%\$search%' OR SUBSTRING_INDEX(f.meta_value, '/', -1) LIKE %s)\";",
	),
	array(
		'H2.64',
		'GET /media vraća i privitke koji nisu slike',
		'includes/class-media-library.php',
		"\$where .= ' AND p.post_mime_type IN (' . implode( ', ', array_fill( 0, count( \$mime_types ), '%s' ) ) . ')';",
		"\$where .= ' AND 1 = 1 OR p.post_mime_type IN (' . implode( ', ', array_fill( 0, count( \$mime_types ), '%s' ) ) . ')';",
	),
	array(
		'H2.65',
		'filename vraća putanju umjesto imena datoteke',
		'includes/class-media-library.php',
		"return wp_basename( str_replace( '\\\\', '/', \$source ) );",
		'return $source;',
	),
	array(
		'H2.66',
		'bez ograničenja učestalosti',
		'includes/class-rate-limiter.php',
		"\$over = \$hits > \$limits['requests'] || ( \$is_upload && \$upload_hits > \$limits['uploads'] );",
		'$over = false;',
	),
	array(
		'H2.66b',
		'neprijavljeni zahtjevi se broje (upis u bazu bez korisnika)',
		'includes/class-rate-limiter.php',
		"if ( \$user_id <= 0 ) {\n\t\t\treturn true;\n\t\t}",
		'',
	),
	array(
		'H2.66c',
		'odbijeni korisnik (403) troši brojač',
		'bulkbird-helper.php',
		"\$allowed = Permissions::require_capabilities( \$capabilities );\n\tif ( true !== \$allowed ) {",
		"\$allowed = Permissions::require_capabilities( \$capabilities );\n\tRate_Limiter::check( get_current_user_id(), false );\n\tif ( true !== \$allowed ) {",
	),
	array(
		'H2.68',
		'brojač učestalosti se upisuje na svakom zahtjevu, i na stranicama za kupce',
		'bulkbird-helper.php',
		"add_action( 'rest_api_init', __NAMESPACE__ . '\\\\register_rest_routes' );",
		"add_action( 'rest_api_init', __NAMESPACE__ . '\\\\register_rest_routes' );\nadd_action( 'wp_loaded', static function (): void { load_rest_classes(); Rate_Limiter::check( get_current_user_id(), false ); } );",
	),
	array(
		'H2.21',
		'ime datoteke se ne sanira',
		'includes/class-media-rules.php',
		"\$base        = trim( str_replace( '.', '-', \$base ), '-_ ' );",
		'',
	),
	array(
		'H2.66d',
		'isti zahtjev se broji svaki put kad WordPress ponovno pita za dozvolu (zaglavlje Allow)',
		'bulkbird-helper.php',
		"if ( ! isset( \$verdicts[ \$key ] ) || \$verdicts[ \$key ]['request'] !== \$request ) {",
		'if ( true ) {',
	),
	array(
		'H2.66e',
		'čitanje se broji kao prijenos',
		'bulkbird-helper.php',
		"'POST' === \$request->get_method() && is_own_route( \$request->get_route() )",
		'true',
	),
	array(
		'H2.11',
		'client_upload_id velikim slovima se odbija',
		'bulkbird-helper.php',
		"'pattern'           => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',",
		"'pattern'           => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$',",
	),
);
