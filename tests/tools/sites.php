<?php
/**
 * Opis testnih stranica iz okoline ili iz ~/.config/bulkbird-helper/sites.env (vidi sites.env.example).
 *
 * U repozitoriju nema adresa, korisnika ni putanja stranica. Tajne nisu u toj datoteci, nego u test.env.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

/**
 * Vraća opis stranice NEW ili OLD.
 *
 * @return array{ssh: string, home: string, host: string, wpcli: string, logs: array<int, string>, access: string}
 * @throws RuntimeException Kad cilj nije NEW/OLD ili obavezne varijable nisu postavljene.
 */
function bbh_site( string $target ): array {
	if ( ! in_array( $target, array( 'NEW', 'OLD' ), true ) ) {
		throw new RuntimeException( 'BBH_TARGET mora biti NEW ili OLD.' );
	}
	$file   = (string) ( getenv( 'BBH_SITES_FILE' ) ?: getenv( 'HOME' ) . '/.config/bulkbird-helper/sites.env' );
	$values = array();
	if ( is_readable( $file ) ) {
		foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			if ( preg_match( '/^(BBH_SITE_[A-Z0-9_]+)=(.*)$/', (string) $line, $m ) ) {
				$values[ $m[1] ] = $m[2];
			}
		}
	}
	$site    = array();
	$missing = array();
	foreach ( array( 'SSH', 'HOME', 'HOST', 'WPCLI', 'LOGS', 'ACCESS_LOG' ) as $name ) {
		$key   = 'BBH_SITE_' . $target . '_' . $name;
		$env   = getenv( $key );
		$value = false !== $env ? (string) $env : (string) ( $values[ $key ] ?? '' );
		if ( '' === $value && 'SSH' !== $name ) {
			$missing[] = $key;
		}
		$site[ $name ] = $value;
	}
	if ( array() !== $missing ) {
		throw new RuntimeException(
			'Nisu postavljene varijable: ' . implode( ', ', $missing ) . '. Postavite ih u okolini ili u datoteci '
			. $file . ' (primjer: sites.env.example u repozitoriju).'
		);
	}
	return array(
		'ssh'    => $site['SSH'],
		'home'   => $site['HOME'],
		'host'   => $site['HOST'],
		'wpcli'  => $site['WPCLI'],
		'logs'   => array_values( array_filter( (array) preg_split( '/\s+/', $site['LOGS'] ) ) ),
		'access' => $site['ACCESS_LOG'],
	);
}
