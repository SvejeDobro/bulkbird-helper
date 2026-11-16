<?php
/**
 * Unutarnja proba plugina unutar stvarnog WordPressa, kroz WP-CLI. Samo čita.
 *
 *   wp eval-file tests/probe/internal.php
 *
 * Varijable okoline:
 *   BBH_REQUIRE      putanja do bulkbird-helper.php ako plugin NIJE aktivan na stranici
 *                    (učitava se samo u ovaj CLI proces; trgovina se ne mijenja)
 *   BBH_SHOPMGR_ID   ID testnog korisnika Shop manager
 *   BBH_CUSTOMER_ID  ID testnog korisnika customer
 *
 * Ispis: JSON s rezultatima (bez tajni i bez osobnih podataka).
 *
 * @package Bulkbird\Helper\Tests
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

( static function (): void {
	global $wpdb, $wp_filter;

	$out = array( 'loaded_from' => 'active plugin' );

	$require = (string) getenv( 'BBH_REQUIRE' );
	if ( '' !== $require ) {
		if ( defined( 'Bulkbird\Helper\VERSION' ) ) {
			fwrite( STDERR, "Plugin je već učitan; BBH_REQUIRE nije potreban.\n" );
			exit( 1 );
		}
		require_once $require;
		$out['loaded_from'] = 'BBH_REQUIRE (nije aktiviran)';
	}
	if ( ! defined( 'Bulkbird\Helper\VERSION' ) ) {
		fwrite( STDERR, "Plugin nije učitan.\n" );
		exit( 1 );
	}

	$out['plugin_version'] = Bulkbird\Helper\VERSION;
	$out['wp']             = get_bloginfo( 'version' );
	$out['wc']             = defined( 'WC_VERSION' ) ? WC_VERSION : null;
	$out['php']            = PHP_VERSION;
	$out['multisite']      = is_multisite();

	$server = rest_get_server();
	if ( '' !== $require && array() === array_filter( array_keys( $server->get_routes() ), static fn( $r ) => 0 === strpos( $r, '/bulkbird/' ) ) ) {
		// REST server je pokrenut prije nego što je plugin učitan u ovaj proces: registriraj naknadno.
		Bulkbird\Helper\register_rest_routes();
		$out['loaded_from'] .= ', putanje registrirane naknadno';
	}

	// H0.75: svaka putanja u bulkbird/ ima permission_callback koji nije __return_true.
	$routes = array();
	foreach ( $server->get_routes() as $route => $handlers ) {
		if ( 0 !== strpos( $route, '/bulkbird/' ) ) {
			continue;
		}
		foreach ( $handlers as $handler ) {
			$callback = $handler['permission_callback'] ?? null;
			$routes[] = array(
				'route'               => $route,
				'methods'             => implode( ',', array_keys( $handler['methods'] ) ),
				'has_permission_cb'   => is_callable( $callback ),
				'is_return_true'      => ( '__return_true' === $callback ),
				'anonymous_is_denied' => is_callable( $callback ) && true !== ( static function () use ( $callback ) {
					wp_set_current_user( 0 );
					return call_user_func( $callback, new WP_REST_Request( 'GET', '/' ) );
				} )(),
			);
		}
	}
	$out['routes']     = $routes;
	$out['namespaces'] = array_values( array_filter( $server->get_namespaces(), static fn( $ns ) => 0 === strpos( $ns, 'bulkbird' ) ) );

	// H0.7, H0.8, H0.21, H0.91: /info po korisnicima.
	$call = static function ( int $user_id ) use ( $wpdb ): array {
		wp_set_current_user( $user_id );
		$before   = $wpdb->num_queries;
		$response = rest_do_request( new WP_REST_Request( 'GET', '/bulkbird/v1/info' ) );
		$data     = $response->get_data();
		return array(
			'status'  => $response->get_status(),
			'code'    => is_array( $data ) && isset( $data['code'] ) ? $data['code'] : null,
			'queries' => $wpdb->num_queries - $before,
			'data'    => 200 === $response->get_status() ? $data : null,
		);
	};

	$out['info_anonymous'] = $call( 0 );
	$customer_id           = (int) getenv( 'BBH_CUSTOMER_ID' );
	$shopmgr_id            = (int) getenv( 'BBH_SHOPMGR_ID' );
	if ( $customer_id > 0 ) {
		$out['info_customer'] = $call( $customer_id );
	}
	if ( $shopmgr_id > 0 ) {
		$out['shopmgr_caps'] = array(
			'unfiltered_html'    => user_can( $shopmgr_id, 'unfiltered_html' ),
			'manage_woocommerce' => user_can( $shopmgr_id, 'manage_woocommerce' ),
			'manage_options'     => user_can( $shopmgr_id, 'manage_options' ),
		);
		$out['info_shopmgr'] = $call( $shopmgr_id );
		// Drugi poziv: koliko upita i ima li upisa (H0.32).
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
		$logged_before = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
		$second        = $call( $shopmgr_id );
		$verbs         = array();
		$rate_queries = 0;
		foreach ( array_slice( (array) $wpdb->queries, $logged_before ) as $query ) {
			// Brojač učestalosti u vlastitoj tablici je jedini dopušteni upis (od 0.2.0).
			if ( false !== strpos( (string) $query[0], 'bulkbird_rate' ) ) {
				++$rate_queries;
				continue;
			}
			$verb           = strtoupper( (string) strtok( ltrim( (string) $query[0] ), " \n\t(" ) );
			$verbs[ $verb ] = ( $verbs[ $verb ] ?? 0 ) + 1;
		}
		$out['info_shopmgr_second'] = array(
			'queries'      => $second['queries'],
			'rate_queries' => $rate_queries,
			'query_verbs'  => $verbs,
			'write_queries' => array_sum( array_diff_key( $verbs, array( 'SELECT' => 0, 'SHOW' => 0 ) ) ),
		);
	}
	wp_set_current_user( 0 );

	// H0.56: kuke plugina (po imenu funkcije u namespaceu Bulkbird\Helper).
	$hooks = array();
	foreach ( $wp_filter as $hook_name => $hook ) {
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn   = $callback['function'];
				$name = is_string( $fn ) ? $fn : ( is_array( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0] ) . '::' . $fn[1] : 'closure' );
				if ( 0 === stripos( $name, 'Bulkbird\\Helper' ) ) {
					$hooks[] = $hook_name . ' -> ' . $name;
				}
			}
		}
	}
	sort( $hooks );
	$out['hooks'] = $hooks;

	// H0.37: opcije, tablice i cron poslovi plugina.
	$out['options'] = $wpdb->get_results( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE 'bulkbird%'", ARRAY_A ); // phpcs:ignore
	$out['tables']  = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}bulkbird%'" ); // phpcs:ignore
	$cron           = array();
	foreach ( (array) _get_cron_array() as $events ) {
		foreach ( array_keys( (array) $events ) as $hook_name ) {
			if ( false !== stripos( $hook_name, 'bulkbird' ) ) {
				$cron[] = $hook_name;
			}
		}
	}
	$out['cron'] = $cron;

	// Učitane datoteke plugina u ovom procesu.
	$dir                  = dirname( (string) ( new ReflectionFunction( 'Bulkbird\Helper\is_own_route' ) )->getFileName() );
	$out['plugin_files_loaded'] = array_values(
		array_map(
			static fn( $f ) => substr( $f, strlen( $dir ) + 1 ),
			array_filter( get_included_files(), static fn( $f ) => 0 === strpos( $f, $dir . '/' ) && false === strpos( $f, '/tests/' ) )
		)
	);

	echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} )();
