<?php
/**
 * Zajedničke pomoćne funkcije za testove stvarnim HTTP-om.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

abstract class E2ECase extends TestCase {

	/** Netajni opis testnih stranica na ovom serveru. */
	private const TARGETS = array(
		'NEW' => array(
			'ssh'   => '',
			'wpcli' => '/home/bulkbird-teststore1/bin/wp83',
			'logs'  => array( '/home/bulkbird-teststore1/logs/wp-debug.log', '/home/bulkbird-teststore1/logs/php/error.log' ),
		),
		'OLD' => array(
			'ssh'   => 'teststoreold',
			'wpcli' => '~/bin/wp81',
			'logs'  => array( '/home/bulkbird-teststoreold/logs/php/error.log', '/home/bulkbird-teststoreold/logs/wp-debug.log' ),
		),
	);

	/** @var array<string, string>|null */
	private static ?array $env = null;

	/** @var array<string, mixed> */
	private static array $cache = array();

	public static function target(): string {
		$target = strtoupper( (string) ( getenv( 'BBH_TARGET' ) ?: 'NEW' ) );
		if ( ! isset( self::TARGETS[ $target ] ) ) {
			throw new RuntimeException( 'BBH_TARGET mora biti NEW ili OLD.' );
		}
		return $target;
	}

	/** @return array<string, string> */
	private static function env(): array {
		if ( null === self::$env ) {
			$file      = (string) ( getenv( 'BBH_ENV_FILE' ) ?: getenv( 'HOME' ) . '/.config/bulkbird-helper/test.env' );
			self::$env = array();
			if ( is_readable( $file ) ) {
				foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
					if ( preg_match( '/^([A-Z0-9_]+)=(.*)$/', (string) $line, $m ) ) {
						self::$env[ $m[1] ] = $m[2];
					}
				}
			}
		}
		return self::$env;
	}

	protected static function cfg( string $name ): string {
		$key = 'BBH_' . self::target() . '_' . $name;
		$env = self::env();
		if ( ! isset( $env[ $key ] ) || '' === $env[ $key ] ) {
			self::markTestSkipped( 'U test.env nema vrijednosti ' . $key . ' (stranica ' . self::target() . ' nije pripremljena).' );
		}
		return $env[ $key ];
	}

	protected static function url( string $path ): string {
		return rtrim( self::cfg( 'URL' ), '/' ) . $path;
	}

	/** @return array{0: string, 1: string} */
	protected static function key( string $name ): array {
		return array( self::cfg( $name . '_CK' ), self::cfg( $name . '_CS' ) );
	}

	protected static function expected_version(): string {
		$version = (string) getenv( 'BBH_EXPECT_VERSION' );
		if ( '' === $version ) {
			preg_match( "/^const VERSION\s+= '([^']+)';/m", (string) file_get_contents( dirname( __DIR__, 2 ) . '/bulkbird-helper.php' ), $m );
			$version = $m[1] ?? '';
		}
		return $version;
	}

	/**
	 * HTTP zahtjev.
	 *
	 * @param array{basic?: array{0: string, 1: string}, headers?: string[], body?: string|array<string, string>} $opts Opcije.
	 * @return array{status: int, headers: array<string, string>, body: string, json: mixed, seconds: float}
	 */
	protected static function http( string $method, string $url, array $opts = array() ): array {
		$headers = array();
		$ch      = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_NOBODY         => ( 'HEAD' === $method ),
				CURLOPT_USERAGENT      => 'bulkbird-helper-e2e',
				CURLOPT_HTTPHEADER     => $opts['headers'] ?? array(),
				CURLOPT_HEADERFUNCTION => static function ( $handle, string $line ) use ( &$headers ): int {
					if ( false !== strpos( $line, ':' ) ) {
						list( $name, $value ) = explode( ':', $line, 2 );
						$name                 = strtolower( trim( $name ) );
						$headers[ $name ]     = isset( $headers[ $name ] ) ? $headers[ $name ] . ', ' . trim( $value ) : trim( $value );
					}
					return strlen( $line );
				},
			)
		);
		if ( isset( $opts['basic'] ) ) {
			curl_setopt( $ch, CURLOPT_USERPWD, $opts['basic'][0] . ':' . $opts['basic'][1] );
		}
		if ( isset( $opts['body'] ) ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $opts['body'] );
		}
		$body = curl_exec( $ch );
		if ( false === $body ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			throw new RuntimeException( 'HTTP zahtjev nije uspio: ' . $error );
		}
		$status  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$seconds = (float) curl_getinfo( $ch, CURLINFO_TOTAL_TIME );
		curl_close( $ch );

		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => (string) $body,
			'json'    => json_decode( (string) $body, true ),
			'seconds' => $seconds,
		);
	}

	/** Kratak opis odgovora za poruke o padu (bez tijela s podacima i bez ključeva). */
	protected static function describe( array $response ): string {
		$code = is_array( $response['json'] ) && isset( $response['json']['code'] ) ? (string) $response['json']['code'] : '-';
		return 'status ' . $response['status'] . ', code ' . $code;
	}

	/**
	 * Pokreće PHP skriptu iz repozitorija kroz WP-CLI ciljne stranice i vraća dekodirani JSON.
	 *
	 * @param array<string, string> $env Varijable okoline za skriptu.
	 * @return array<string, mixed>
	 */
	protected static function wpcli( string $script, array $env = array() ): array {
		$target  = self::TARGETS[ self::target() ];
		$path    = dirname( __DIR__, 2 ) . '/' . $script;
		$assigns = '';
		foreach ( $env as $name => $value ) {
			$assigns .= $name . '=' . escapeshellarg( $value ) . ' ';
		}
		$remote = $assigns . $target['wpcli'] . ' eval-file -';
		$cmd    = '' === $target['ssh']
			? $remote . ' < ' . escapeshellarg( $path ) . ' 2>/dev/null'
			: 'ssh -o BatchMode=yes ' . escapeshellarg( $target['ssh'] ) . ' ' . escapeshellarg( $remote ) . ' < ' . escapeshellarg( $path ) . ' 2>/dev/null';
		$output = (string) shell_exec( $cmd );
		$start  = strpos( $output, '{' );
		$json   = false === $start ? null : json_decode( substr( $output, $start ), true );
		if ( ! is_array( $json ) ) {
			throw new RuntimeException( 'WP-CLI skripta ' . $script . ' nije vratila JSON.' );
		}
		return $json;
	}

	/** Proba stranice (jednom po pokretanju). @return array<string, mixed> */
	protected static function site(): array {
		return self::$cache['site'] ??= self::wpcli( 'tests/probe/site.php' );
	}

	/** Unutarnja proba (jednom po pokretanju). @return array<string, mixed> */
	protected static function internal(): array {
		return self::$cache['internal'] ??= self::wpcli(
			'tests/probe/internal.php',
			array(
				'BBH_SHOPMGR_ID'  => self::cfg( 'SHOPMGR_ID' ),
				'BBH_CUSTOMER_ID' => self::cfg( 'CUSTOMER_ID' ),
			)
		);
	}

	/**
	 * Kratkotrajna prijava kolačićem.
	 *
	 * @param array<string, string> $who BBH_USER_ID ili BBH_ROLE.
	 * @return array{cookie: string, nonce: string}
	 */
	protected static function session( array $who ): array {
		$data = self::wpcli( 'tests/tools/session.php', $who );
		return array(
			'cookie' => $data['cookie_name'] . '=' . rawurlencode( (string) $data['cookie_value'] ),
			'nonce'  => (string) $data['nonce'],
		);
	}

	/** Broj redaka u logovima stranice koji spominju plugin ili izgledaju kao REST ključ. */
	protected static function suspicious_log_lines(): int {
		$target = self::TARGETS[ self::target() ];
		$files  = implode( ' ', array_map( 'escapeshellarg', $target['logs'] ) );
		$grep   = "cat $files 2>/dev/null | grep -ciE 'bulkbird-helper|Bulkbird.Helper|c[ks]_[0-9a-f]{8}'";
		$cmd    = '' === $target['ssh'] ? $grep : 'ssh -o BatchMode=yes ' . escapeshellarg( $target['ssh'] ) . ' ' . escapeshellarg( $grep );
		return (int) trim( (string) shell_exec( $cmd ) );
	}

	protected static function remember( string $name, $value = null ) {
		if ( null !== $value ) {
			self::$cache[ $name ] = $value;
		}
		return self::$cache[ $name ] ?? null;
	}
}
