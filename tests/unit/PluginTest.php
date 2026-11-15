<?php
/**
 * Dozvole, /info, aktivacija, multisite, deinstalacija, zaglavlja (jedinično).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

use Bulkbird\Helper\Activation;
use Bulkbird\Helper\Environment;
use Bulkbird\Helper\Info_Controller;
use Bulkbird\Helper\Permissions;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

	protected function setUp(): void {
		BBH_State::reset();
	}

	// --- Dozvole (H0.7, H0.8, H0.91; kvarovi H0.81, H0.82) -------------------------------------

	public function test_h0_7_anonymous_gets_401(): void {
		$result = Permissions::require_capability( 'manage_woocommerce' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'bulkbird_unauthorized', $result->get_error_code() );
		self::assertSame( array( 'status' => 401 ), $result->get_error_data() );
	}

	public function test_h0_8_logged_in_without_capability_gets_403(): void {
		BBH_State::$logged_in = true;
		BBH_State::$caps      = array( 'read' );
		$result               = Permissions::require_capability( 'manage_woocommerce' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'bulkbird_forbidden', $result->get_error_code() );
		self::assertSame( array( 'status' => 403 ), $result->get_error_data() );
	}

	public function test_h0_91_shop_manager_capability_is_enough(): void {
		BBH_State::$logged_in = true;
		BBH_State::$caps      = array( 'manage_woocommerce' );
		self::assertTrue( Permissions::require_capability( 'manage_woocommerce' ) );
	}

	// --- Putanja /info (H0.21, H0.22, H0.24, H0.29, H0.75) --------------------------------------

	public function test_h0_75_info_route_has_a_real_permission_callback(): void {
		( new Info_Controller() )->register_routes();
		self::assertCount( 1, BBH_State::$routes );
		list( $namespace, $route, $args ) = BBH_State::$routes[0];
		self::assertSame( 'bulkbird/v1', $namespace );
		self::assertSame( '/info', $route );
		self::assertSame( 'GET', $args[0]['methods'] );
		self::assertIsCallable( $args[0]['permission_callback'] );
		self::assertNotSame( '__return_true', $args[0]['permission_callback'] );

		$denied = call_user_func( $args[0]['permission_callback'] );
		self::assertInstanceOf( WP_Error::class, $denied, 'neprijavljen mora biti odbijen' );

		BBH_State::$logged_in = true;
		BBH_State::$caps      = array( 'read', 'upload_files', 'edit_posts' );
		$denied               = call_user_func( $args[0]['permission_callback'] );
		self::assertInstanceOf( WP_Error::class, $denied, 'prijavljen bez manage_woocommerce mora biti odbijen' );
	}

	public function test_h0_21_h0_22_info_fields_and_types(): void {
		$data = ( new Info_Controller() )->get_info()->get_data();

		self::assertSame(
			array(
				'plugin_version',
				'api_version',
				'features',
				'wordpress_version',
				'woocommerce_version',
				'php_version',
				'hpos_enabled',
				'timezone',
				'currency',
				'max_upload_bytes',
				'memory_limit',
				'max_execution_time',
				'detected_plugins',
				'analytics_lookup',
			),
			array_keys( $data )
		);
		self::assertSame( Bulkbird\Helper\VERSION, $data['plugin_version'] );
		self::assertSame( 1, $data['api_version'] );
		self::assertSame( array(), $data['features'], 'H0.24: u fazi 0 prazan popis' );
		self::assertIsString( $data['wordpress_version'] );
		self::assertIsString( $data['woocommerce_version'] );
		self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $data['php_version'] );
		self::assertIsBool( $data['hpos_enabled'] );
		self::assertIsInt( $data['max_upload_bytes'] );
		self::assertIsInt( $data['memory_limit'] );
		self::assertIsInt( $data['max_execution_time'] );
		self::assertIsArray( $data['detected_plugins'] );
		self::assertContains( $data['analytics_lookup'], array( 'complete', 'importing', 'unknown' ) );
	}

	public function test_schema_lists_exactly_the_returned_fields(): void {
		$controller = new Info_Controller();
		self::assertSame(
			array_keys( $controller->get_info()->get_data() ),
			array_keys( $controller->get_schema()['properties'] )
		);
	}

	public function test_h0_29_info_contains_no_paths_or_secrets(): void {
		$json = (string) json_encode( ( new Info_Controller() )->get_info()->get_data() );
		foreach ( array( ABSPATH, WP_PLUGIN_DIR, '/home/', '\/home\/', 'wp-config', 'password', '@' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $json, 'zabranjen sadržaj: ' . $needle );
		}
		self::assertDoesNotMatchRegularExpression( '/\bc[ks]_[0-9a-f]{8,}/', $json, 'zabranjen sadržaj: REST ključ' );
	}

	// --- Prepoznati pluginovi (H0.27) -----------------------------------------------------------

	public function test_h0_27_only_allow_listed_active_plugins_are_reported(): void {
		BBH_State::$options['active_plugins'] = array(
			'wordpress-seo/wp-seo.php',
			'some-private-plugin/some-private-plugin.php',
			'elementor/elementor.php',
			'../../wp-config.php',
			'hello.php',
		);
		$detected = Environment::detected_plugins();
		self::assertSame(
			array(
				array(
					'slug'    => 'elementor',
					'version' => '3.25.1',
				),
				array(
					'slug'    => 'wordpress-seo',
					'version' => '',
				),
			),
			$detected,
			'samo s popisa, poredano po slugu; verzija prazna ako datoteka nije čitljiva'
		);
	}

	public function test_detected_plugins_survives_a_broken_option(): void {
		BBH_State::$options['active_plugins'] = 'not-an-array';
		self::assertSame( array(), Environment::detected_plugins() );
	}

	// --- Zaglavlja protiv cachea (H0.18) --------------------------------------------------------

	public function test_h0_18_own_responses_forbid_caching_foreign_ones_are_untouched(): void {
		$own = Bulkbird\Helper\forbid_caching_of_own_responses( new WP_REST_Response(), null, new WP_REST_Request( 'GET', '/bulkbird/v1/info' ) );
		self::assertSame( 'no-store, private, max-age=0', $own->headers['Cache-Control'] );

		$foreign = Bulkbird\Helper\forbid_caching_of_own_responses( new WP_REST_Response(), null, new WP_REST_Request( 'GET', '/wc/v3/products' ) );
		self::assertArrayNotHasKey( 'Cache-Control', $foreign->headers );
	}

	// --- Aktivacija i multisite (H0.37, H0.49, H0.50, H0.51, H0.52; kvarovi H0.87, H0.88) -------

	public function test_h0_37_activation_adds_one_option_without_autoload(): void {
		Activation::activate();
		self::assertSame( array( array( 'bulkbird_helper_db_version', '0', false ) ), BBH_State::$added_options );
	}

	public function test_h0_49_h0_50_activation_is_refused_on_multisite(): void {
		BBH_State::$multisite = true;
		try {
			Activation::activate();
			self::fail( 'aktivacija na multisiteu mora biti odbijena' );
		} catch ( BBH_Die_Exception $e ) {
			self::assertStringContainsString( 'does not support WordPress multisite', $e->getMessage() );
			self::assertSame( strip_tags( $e->getMessage() ), $e->getMessage(), 'H0.52: poruka bez HTML-a' );
		}
		self::assertSame( array(), BBH_State::$added_options, 'ništa se ne smije upisati' );
	}

	public function test_h0_51_no_routes_on_multisite(): void {
		BBH_State::$multisite = true;
		Bulkbird\Helper\register_rest_routes();
		self::assertSame( array(), BBH_State::$routes );
		self::assertFalse( Bulkbird\Helper\is_supported_environment() );
	}

	public function test_routes_are_registered_on_a_supported_single_site(): void {
		Bulkbird\Helper\register_rest_routes();
		self::assertCount( 1, BBH_State::$routes );
	}

	// --- Deinstalacija (H0.45; kvar H0.89) ------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_h0_45_uninstall_deletes_only_own_option(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'bulkbird-helper/bulkbird-helper.php' );
		require dirname( __DIR__, 2 ) . '/uninstall.php';
		self::assertSame( array( 'bulkbird_helper_db_version' ), BBH_State::$deleted_options );
	}

	// --- Kuke (H0.56) ---------------------------------------------------------------------------

	public function test_h0_56_main_file_registers_no_front_end_hooks(): void {
		$hooks = array_column( BBH_State::$hooks, 0 );
		sort( $hooks );
		self::assertSame(
			array(
				'activate',
				'before_woocommerce_init',
				'rest_api_init',
				'woocommerce_rest_is_request_to_rest_api',
				'woocommerce_system_status_report',
			),
			array_values( array_unique( array_diff( $hooks, array( 'rest_post_dispatch' ) ) ) )
		);
	}
}
