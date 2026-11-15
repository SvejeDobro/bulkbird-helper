<?php
/**
 * Jedinični testovi bez WordPressa: male zamjene WordPress funkcija čije se
 * ponašanje podešava kroz BBH_State. Plugin se učitava jednom, na kraju datoteke.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', '/nonexistent-abspath/' );
define( 'WC_VERSION', '8.0.0' );
define( 'WP_PLUGIN_DIR', __DIR__ . '/fixtures/plugins' );

final class BBH_State {
	public static bool $multisite     = false;
	public static bool $logged_in     = false;
	/** @var string[] */
	public static array $caps         = array();
	public static string $home        = 'https://shop.example';
	/** @var array<string, mixed> */
	public static array $options      = array();
	/** @var array<int, array<int, mixed>> */
	public static array $added_options   = array();
	/** @var string[] */
	public static array $deleted_options = array();
	/** @var array<int, array<int, mixed>> */
	public static array $routes       = array();
	/** @var array<int, array<int, mixed>> */
	public static array $hooks        = array();

	public static function reset(): void {
		self::$multisite       = false;
		self::$logged_in       = false;
		self::$caps            = array();
		self::$home            = 'https://shop.example';
		self::$options         = array();
		self::$added_options   = array();
		self::$deleted_options = array();
		self::$routes          = array();
		$_GET                  = array();
		$_POST                 = array();
		unset( $_SERVER['REQUEST_URI'] );
	}
}

final class BBH_Die_Exception extends RuntimeException {}

class WP_Error {
	/** @param mixed $data */
	public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
	public function get_error_code(): string {
		return $this->code;
	}
	/** @return mixed */
	public function get_error_data() {
		return $this->data;
	}
	public function get_error_message(): string {
		return $this->message;
	}
}

class WP_HTTP_Response {
	/** @var array<string, string> */
	public array $headers = array();
	/** @param mixed $data */
	public function __construct( public $data = null, public int $status = 200 ) {}
	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}
	/** @return mixed */
	public function get_data() {
		return $this->data;
	}
	public function get_status(): int {
		return $this->status;
	}
}
class WP_REST_Response extends WP_HTTP_Response {}
class WP_REST_Request {
	public function __construct( public string $method = 'GET', public string $route = '' ) {}
	public function get_route(): string {
		return $this->route;
	}
}
class WP_REST_Server {
	public const READABLE = 'GET';
}

function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
	BBH_State::$hooks[] = array( $hook, $callback, $priority, $args );
	return true;
}
function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
	return add_filter( $hook, $callback, $priority, $args );
}
function register_activation_hook( string $file, $callback ): void {
	BBH_State::$hooks[] = array( 'activate', $callback, 10, 1 );
}
function is_multisite(): bool {
	return BBH_State::$multisite;
}
function is_user_logged_in(): bool {
	return BBH_State::$logged_in;
}
function current_user_can( string $cap ): bool {
	return BBH_State::$logged_in && in_array( $cap, BBH_State::$caps, true );
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}
function home_url(): string {
	return BBH_State::$home;
}
function rest_get_url_prefix(): string {
	return 'wp-json';
}
function register_rest_route( string $ns, string $route, array $args ): bool {
	BBH_State::$routes[] = array( $ns, $route, $args );
	return true;
}
function __( string $text, string $domain = 'default' ): string {
	return $text;
}
function esc_html__( string $text, string $domain = 'default' ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function wp_die( $message = '', $title = '', $args = array() ): void {
	throw new BBH_Die_Exception( (string) $message );
}
function add_option( string $name, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
	BBH_State::$added_options[] = array( $name, $value, $autoload );
	return true;
}
function delete_option( string $name ): bool {
	BBH_State::$deleted_options[] = $name;
	return true;
}
function get_option( string $name, $default = false ) {
	return BBH_State::$options[ $name ] ?? $default;
}
function get_bloginfo( string $show = '' ): string {
	return '6.4.3';
}
function wp_timezone_string(): string {
	return 'Europe/Zagreb';
}
function get_woocommerce_currency(): string {
	return 'EUR';
}
function wp_max_upload_size(): int {
	return 2097152;
}
function wp_convert_hr_to_bytes( string $value ): int {
	$value = strtolower( trim( $value ) );
	$bytes = (int) $value;
	if ( str_contains( $value, 'g' ) ) {
		$bytes *= 1024 ** 3;
	} elseif ( str_contains( $value, 'm' ) ) {
		$bytes *= 1024 ** 2;
	} elseif ( str_contains( $value, 'k' ) ) {
		$bytes *= 1024;
	}
	return $bytes;
}
function validate_file( string $file ): int {
	return ( str_contains( $file, '..' ) || str_starts_with( $file, '/' ) ) ? 1 : 0;
}
function get_file_data( string $file, array $headers ): array {
	$out = array();
	$src = (string) file_get_contents( $file, false, null, 0, 8192 );
	foreach ( $headers as $key => $label ) {
		$out[ $key ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $src, $m ) ? trim( $m[1] ) : '';
	}
	return $out;
}

$bbh_root = dirname( __DIR__, 2 );
require_once $bbh_root . '/bulkbird-helper.php';
require_once $bbh_root . '/includes/class-permissions.php';
require_once $bbh_root . '/includes/class-environment.php';
require_once $bbh_root . '/includes/class-info-controller.php';
require_once $bbh_root . '/includes/class-activation.php';
require_once $bbh_root . '/includes/class-status-report.php';
