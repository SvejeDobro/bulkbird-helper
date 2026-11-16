<?php
/**
 * Plugin unutar stvarnog WordPressa i WooCommercea: putanje, dozvole, aktivacija, deinstalacija,
 * multisite, rad bez WooCommercea (H0.7, H0.8, H0.19, H0.21, H0.36, H0.37, H0.43–H0.46, H0.49–H0.51, H0.75, H0.91).
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

final class PluginIntegrationTest extends WP_UnitTestCase {

	private static bool $with_wc;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$with_wc = defined( 'WC_VERSION' );
	}

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore
		parent::tear_down();
	}

	private function info(): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', '/bulkbird/v1/info' ) );
	}

	private function supported(): bool {
		return self::$with_wc && ! is_multisite();
	}

	public function test_environment_is_what_the_matrix_says(): void {
		$this->assertSame( '1' === getenv( 'WP_MULTISITE' ), is_multisite() );
		$this->assertSame( '1' !== getenv( 'BBH_WITHOUT_WC' ), self::$with_wc );
		$this->assertSame( $this->supported(), Bulkbird\Helper\is_supported_environment() );
	}

	public function test_h0_19_h0_51_no_routes_without_woocommerce_or_on_multisite(): void {
		$routes = array_keys( rest_get_server()->get_routes() );
		$own    = array_values( array_filter( $routes, static fn( string $route ): bool => 0 === strpos( $route, '/bulkbird/' ) ) );
		if ( $this->supported() ) {
			$this->assertContains( '/bulkbird/v1/info', $own );
		} else {
			$this->assertSame( array(), $own );
			$this->assertSame( 404, $this->info()->get_status() );
		}
	}

	public function test_h0_7_anonymous_is_rejected(): void {
		if ( ! $this->supported() ) {
			$this->markTestSkipped( 'Putanje ne postoje u ovoj okolini.' );
		}
		wp_set_current_user( 0 );
		$response = $this->info();
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'bulkbird_unauthorized', $response->get_data()['code'] );
	}

	public function test_h0_8_users_without_the_capability_are_rejected(): void {
		if ( ! $this->supported() ) {
			$this->markTestSkipped( 'Putanje ne postoje u ovoj okolini.' );
		}
		foreach ( array( 'subscriber', 'customer', 'author', 'editor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$response = $this->info();
			$this->assertSame( 403, $response->get_status(), $role );
			$this->assertSame( 'bulkbird_forbidden', $response->get_data()['code'], $role );
		}
	}

	public function test_h0_21_h0_91_shop_manager_and_administrator_get_the_full_response(): void {
		if ( ! $this->supported() ) {
			$this->markTestSkipped( 'Putanje ne postoje u ovoj okolini.' );
		}
		foreach ( array( 'shop_manager', 'administrator' ) as $role ) {
			$user_id = self::factory()->user->create( array( 'role' => $role ) );
			wp_set_current_user( $user_id );
			$this->assertTrue( user_can( $user_id, 'manage_woocommerce' ), $role . ' mora imati manage_woocommerce' );
			$response = $this->info();
			$this->assertSame( 200, $response->get_status(), $role );
			$data = $response->get_data();
			$this->assertSame(
				array( 'plugin_version', 'api_version', 'features', 'wordpress_version', 'woocommerce_version', 'php_version', 'hpos_enabled', 'timezone', 'currency', 'max_upload_bytes', 'memory_limit', 'max_execution_time', 'detected_plugins', 'analytics_lookup', 'media', 'user' ),
				array_keys( $data )
			);
			$this->assertSame( Bulkbird\Helper\VERSION, $data['plugin_version'] );
			$this->assertSame( get_bloginfo( 'version' ), $data['wordpress_version'] );
			$this->assertSame( WC_VERSION, $data['woocommerce_version'] );
			$this->assertIsBool( $data['hpos_enabled'] );
			$this->assertContains( $data['analytics_lookup'], array( 'complete', 'importing', 'unknown' ) );
			$json = (string) wp_json_encode( $data );
			$this->assertStringNotContainsString( untrailingslashit( ABSPATH ), $json );
			$this->assertStringNotContainsString( 'wp-content', $json );
		}
	}

	public function test_h0_32_info_writes_nothing(): void {
		if ( ! $this->supported() ) {
			$this->markTestSkipped( 'Putanje ne postoje u ovoj okolini.' );
		}
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->info();
		$wpdb->queries = array();
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
		$this->info();
		foreach ( (array) $wpdb->queries as $query ) {
			// Jedini dopušteni upis je brojač učestalosti u vlastitoj tablici.
			if ( false !== strpos( (string) $query[0], 'bulkbird_rate' ) ) {
				continue;
			}
			$this->assertMatchesRegularExpression( '/^\s*(SELECT|SHOW)\b/i', (string) $query[0], 'GET /info smije samo čitati' );
		}
	}

	public function test_h0_75_every_own_route_has_a_real_permission_callback(): void {
		if ( ! $this->supported() ) {
			$this->markTestSkipped( 'Putanje ne postoje u ovoj okolini.' );
		}
		wp_set_current_user( 0 );
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( 0 !== strpos( $route, '/bulkbird/' ) || '/bulkbird/v1' === $route ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$this->assertIsCallable( $handler['permission_callback'], $route );
				$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
				$this->assertNotTrue( call_user_func( $handler['permission_callback'], new WP_REST_Request( 'GET', $route ) ), $route . ' mora odbiti neprijavljenog' );
			}
		}
	}

	public function test_h0_36_h0_37_h0_43_activation_on_a_single_site(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Samo za pojedinačnu stranicu.' );
		}
		global $wpdb;
		delete_option( 'bulkbird_helper_db_version' );
		Bulkbird\Helper\on_activation();
		Bulkbird\Helper\on_activation(); // H0.43: dvostruka aktivacija bez grešaka i bez duplikata.
		$rows = $wpdb->get_results( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE 'bulkbird%'", ARRAY_A );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'bulkbird_helper_db_version', $rows[0]['option_name'] );
		$this->assertContains( $rows[0]['autoload'], array( 'no', 'off' ) );
		$this->assertSame( array(), $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}bulkbird_changes'" ), 'aktivacija ne stvara tablice' );
		$this->assertFalse( wp_next_scheduled( 'bulkbird_helper_cleanup' ) );
	}

	public function test_h0_49_h0_50_activation_is_refused_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Samo za multisite.' );
		}
		delete_option( 'bulkbird_helper_db_version' );
		try {
			Bulkbird\Helper\on_activation();
			$this->fail( 'aktivacija na multisiteu mora biti odbijena' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'does not support WordPress multisite', $e->getMessage() );
		}
		$this->assertFalse( get_option( 'bulkbird_helper_db_version' ), 'ništa se ne smije upisati' );
		$this->assertFalse( Bulkbird\Helper\filter_is_request_to_rest_api( false ) );
	}

	public function test_h0_45_h0_46_uninstall_removes_only_own_data(): void {
		global $wpdb;
		add_option( 'bulkbird_helper_db_version', '0', '', false );
		add_option( 'someone_elses_option', 'keep' );
		$post_id = self::factory()->post->create();
		$before  = array(
			'posts'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'options' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
			'users'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
		);

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'bulkbird-helper/bulkbird-helper.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( 'bulkbird_helper_db_version' ) );
		$this->assertSame( 'keep', get_option( 'someone_elses_option' ) );
		$this->assertNotNull( get_post( $post_id ) );
		$this->assertSame( $before['posts'], (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
		$this->assertSame( $before['options'] - 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) );
		$this->assertSame( $before['users'], (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) );
	}

	public function test_h0_56_no_front_end_hooks(): void {
		global $wp_filter;
		foreach ( array( 'wp_head', 'wp_footer', 'template_redirect', 'wp_enqueue_scripts', 'the_content', 'init', 'wp', 'admin_menu', 'admin_notices' ) as $hook_name ) {
			if ( empty( $wp_filter[ $hook_name ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$this->assertFalse( is_string( $callback['function'] ) && 0 === stripos( $callback['function'], 'Bulkbird\\Helper' ), 'plugin ne smije imati kuku na ' . $hook_name );
				}
			}
		}
	}

	public function test_h2_80_to_h2_83_user_unfiltered_html(): void {
		if ( ! defined( 'WC_VERSION' ) || is_multisite() ) {
			$this->markTestSkipped( 'Putanja /info ne postoji u ovoj okolini.' );
		}
		foreach ( array( 'administrator', 'shop_manager' ) as $role ) {
			$user_id = self::factory()->user->create( array( 'role' => $role ) );
			wp_set_current_user( $user_id );
			$data = $this->info()->get_data();

			$this->assertContains( 'user.unfiltered_html', $data['features'], $role );
			$this->assertSame( array( 'unfiltered_html' ), array_keys( $data['user'] ), $role . ': točno jedno polje' );
			$this->assertSame( current_user_can( 'unfiltered_html' ), $data['user']['unfiltered_html'], $role . ': ono što WordPress kaže' );
			$this->assertSame( 1, $data['api_version'] );

			// Ništa što otkriva tko je korisnik.
			$user = get_userdata( $user_id );
			$json = (string) wp_json_encode( $data );
			foreach ( array( $user->user_login, $user->user_email, $user->display_name, $role ) as $forbidden ) {
				$this->assertStringNotContainsString( (string) $forbidden, $json, $role );
			}
			$this->assertStringNotContainsString( '"id"', strtolower( $json ) );
		}

		// Zadano u WordPressu (jedna stranica): administrator smije, Shop manager ne.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $this->info()->get_data()['user']['unfiltered_html'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertFalse( $this->info()->get_data()['user']['unfiltered_html'] );

		// Trgovina koja to pravo uskrati ili dodijeli (kao DISALLOW_UNFILTERED_HTML ili dodatak za uloge): vrijedi ono što WordPress kaže.
		$deny = static function ( array $caps, string $cap ): array {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		$this->assertFalse( $this->info()->get_data()['user']['unfiltered_html'] );
		remove_filter( 'map_meta_cap', $deny, 10 );

		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		get_userdata( $manager )->add_cap( 'unfiltered_html' );
		wp_set_current_user( $manager );
		$this->assertTrue( $this->info()->get_data()['user']['unfiltered_html'] );
	}
}
