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
define( 'ARRAY_A', 'ARRAY_A' );
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
		BBH_Media_State::$user_id         = 0;
		BBH_Media_State::$rate_limits     = null;
		BBH_Media_State::$updated_options = array();
		BBH_Media_State::$wp_max_upload   = 67108864;
		BBH_Media_State::$editor_supports = array( 'image/webp', 'image/avif' );
		$GLOBALS['wpdb']                  = new BBH_Fake_Wpdb();
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
	public function get_method(): string {
		return $this->method;
	}
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


// --- Dodatne zamjene za paket 0.2.0 -----------------------------------------------------------

/** Lažni $wpdb koji oponaša brojač učestalosti (jedan redak po korisniku, atomski upsert). */
final class BBH_Fake_Wpdb {
	public string $prefix   = 'wp_';
	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $dbname   = 'testdb';
	public bool $table_exists = false;
	/** @var array<int, array{window_start:int, hits:int, upload_hits:int}> */
	public array $rows = array();
	/** @var string[] */
	public array $log = array();
	/** @var array<int, mixed>|null */
	private ?array $last_args = null;

	public function prepare( string $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->last_args = $args;
		return $sql . "\0" . json_encode( $args );
	}
	public function suppress_errors( bool $suppress = true ): bool {
		return false;
	}
	public function get_charset_collate(): string {
		return '';
	}
	public function query( string $prepared ) {
		list( $sql, $json ) = array_pad( explode( "\0", $prepared, 2 ), 2, '[]' );
		$args               = (array) json_decode( $json, true );
		$this->log[]        = strtoupper( (string) strtok( ltrim( $sql ), " \n\t" ) );
		if ( 0 === strpos( ltrim( $sql ), 'CREATE TABLE' ) ) {
			$this->table_exists = true;
			return true;
		}
		if ( 0 === strpos( ltrim( $sql ), 'INSERT INTO' ) ) {
			if ( ! $this->table_exists ) {
				return false;
			}
			list( , $user_id, $window_start, $upload ) = $args;
			$row = $this->rows[ $user_id ] ?? null;
			if ( null === $row || $row['window_start'] !== $window_start ) {
				$row = array( 'window_start' => $window_start, 'hits' => 1, 'upload_hits' => $upload );
			} else {
				++$row['hits'];
				$row['upload_hits'] += $upload;
			}
			$this->rows[ $user_id ] = $row;
			return 1;
		}
		return false;
	}
	public function get_row( string $prepared, $output = null ) {
		list( , $json ) = array_pad( explode( "\0", $prepared, 2 ), 2, '[]' );
		$args           = (array) json_decode( $json, true );
		$this->log[]    = 'SELECT';
		$row            = $this->rows[ $args[1] ] ?? null;
		return null === $row ? null : array( 'hits' => (string) $row['hits'], 'upload_hits' => (string) $row['upload_hits'] );
	}
}

final class BBH_Media_State {
	/** @var array<string, string> */
	public static array $site_mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
		'pdf'          => 'application/pdf',
	);
	/** @var string[] */
	public static array $editor_supports = array( 'image/webp', 'image/avif' );
	public static int $wp_max_upload     = 67108864;
	public static int $user_id           = 0;
	/** @var array<string, mixed>|null */
	public static ?array $rate_limits    = null;
	/** @var array<int, array<int, mixed>> */
	public static array $updated_options = array();
}

function apply_filters( string $hook, $value ) {
	if ( 'bulkbird_helper_rate_limits' === $hook && null !== BBH_Media_State::$rate_limits ) {
		return BBH_Media_State::$rate_limits;
	}
	return $value;
}
function wp_using_ext_object_cache(): bool {
	return false;
}
function update_option( string $name, $value, $autoload = null ): bool {
	BBH_Media_State::$updated_options[] = array( $name, $value, $autoload );
	return true;
}
function get_current_user_id(): int {
	return BBH_Media_State::$user_id;
}
function get_allowed_mime_types(): array {
	return BBH_Media_State::$site_mimes;
}
function wp_image_editor_supports( array $args ): bool {
	return in_array( $args['mime_type'] ?? '', BBH_Media_State::$editor_supports, true );
}
function wp_max_upload_size(): int {
	return BBH_Media_State::$wp_max_upload;
}
function wp_get_image_mime( string $file ) {
	$type = @exif_imagetype( $file );
	return false === $type ? false : image_type_to_mime_type( $type );
}
function wp_getimagesize( string $file ) {
	return @getimagesize( $file );
}
function wp_basename( string $path, string $suffix = '' ): string {
	return urldecode( basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
}
function sanitize_file_name( string $name ): string {
	$name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name );
	return trim( (string) $name, '.-_' );
}
function sanitize_text_field( string $text ): string {
	return trim( strip_tags( $text ) );
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

$bbh_root = dirname( __DIR__, 2 );
require_once $bbh_root . '/bulkbird-helper.php';
require_once $bbh_root . '/includes/class-permissions.php';
require_once $bbh_root . '/includes/class-rate-limiter.php';
require_once $bbh_root . '/includes/class-media-rules.php';
require_once $bbh_root . '/includes/class-media-library.php';
require_once $bbh_root . '/includes/class-environment.php';
require_once $bbh_root . '/includes/class-info-controller.php';
require_once $bbh_root . '/includes/class-activation.php';
require_once $bbh_root . '/includes/class-status-report.php';
