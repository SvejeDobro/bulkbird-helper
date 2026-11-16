<?php
/**
 * Mjeri što WooCommerce REST napravi s opisom proizvoda ovisno o tome smije li korisnik spremati
 * nefiltrirani HTML (docs/measurements/unfiltered-html.md).
 *
 *   BBH_TARGET=NEW php tests/tools/probe-unfiltered-html.php
 *
 * Koristi jednu skicu proizvoda "bbh-test-unfiltered-1" (stvara je ako ne postoji; ne briše je).
 * Sprema opis dvaput: ključem Shop managera i prijavom administratora (kolačić + nonce, 5 minuta).
 * Ključ ide samo u zaglavlju. Ništa tajno se ne ispisuje.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

$target = strtoupper( (string) ( getenv( 'BBH_TARGET' ) ?: 'NEW' ) );
$env    = array();
foreach ( (array) file( (string) getenv( 'HOME' ) . '/.config/bulkbird-helper/test.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	if ( preg_match( '/^([A-Z0-9_]+)=(.*)$/', (string) $line, $m ) ) {
		$env[ $m[1] ] = $m[2];
	}
}
$base  = rtrim( $env[ "BBH_{$target}_URL" ], '/' ) . '/wp-json';
$wpcli = 'NEW' === $target
	? static fn( string $assign, string $file ): string => (string) shell_exec( $assign . ' /home/bulkbird-teststore1/bin/wp83 eval-file - < ' . escapeshellarg( $file ) . ' 2>/dev/null' )
	: static fn( string $assign, string $file ): string => (string) shell_exec( 'ssh -o BatchMode=yes teststoreold ' . escapeshellarg( $assign . ' ~/bin/wp81 eval-file -' ) . ' < ' . escapeshellarg( $file ) . ' 2>/dev/null' );

$call = static function ( string $method, string $url, array $auth, ?array $json = null ) use ( $env, $target ): array {
	$ch      = curl_init( $url );
	$headers = array( 'Content-Type: application/json' );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_USERAGENT      => 'bulkbird-helper-probe',
		)
	);
	if ( isset( $auth['key'] ) ) {
		curl_setopt( $ch, CURLOPT_USERPWD, $env[ "BBH_{$target}_{$auth['key']}_CK" ] . ':' . $env[ "BBH_{$target}_{$auth['key']}_CS" ] );
	} else {
		$headers[] = 'Cookie: ' . $auth['cookie'];
		$headers[] = 'X-WP-Nonce: ' . $auth['nonce'];
	}
	if ( null !== $json ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $json ) );
	}
	curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
	$raw    = (string) curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	return array( 'status' => $status, 'json' => json_decode( $raw, true ) );
};

// Skica proizvoda (jedna, trajna dok je vlasnik ne odobri obrisati).
$sku   = 'bbh-test-unfiltered-1';
$found = $call( 'GET', $base . '/wc/v3/products?status=draft&sku=' . $sku, array( 'key' => 'K_READ' ) );
$id    = (int) ( $found['json'][0]['id'] ?? 0 );
if ( 0 === $id ) {
	$made = $call( 'POST', $base . '/wc/v3/products', array( 'key' => 'K_RW' ), array( 'name' => $sku, 'sku' => $sku, 'type' => 'simple', 'status' => 'draft', 'catalog_visibility' => 'hidden' ) );
	$id   = (int) ( $made['json']['id'] ?? 0 );
	echo "skica stvorena: status {$made['status']}, ID $id\n";
} else {
	echo "skica postoji: ID $id\n";
}
if ( 0 === $id ) {
	exit( 1 );
}

$session = json_decode( substr( $s = $wpcli( 'BBH_ROLE=administrator', __DIR__ . '/session.php' ), (int) strpos( $s, '{' ) ), true );
$admin   = array( 'cookie' => $session['cookie_name'] . '=' . rawurlencode( (string) $session['cookie_value'] ), 'nonce' => (string) $session['nonce'] );

$parts = array(
	'obrnuta kosa crta'  => array( '<p>C:\\temp\\novi</p>', 'C:\\temp\\novi' ),
	'dvije kose crte'    => array( '<p>a\\\\b</p>', 'a\\\\b' ),
	'iframe'             => array( '<iframe src="https://example.com/embed" width="300" height="200"></iframe>', '<iframe' ),
	'script'             => array( '<script>window.bbhTest = 1;</script>', '<script' ),
	'style'              => array( '<style>.bbh-test { color: red; }</style>', '<style' ),
	'atribut onclick'    => array( '<p onclick="bbhTest()">klik</p>', 'onclick' ),
	'atribut style'      => array( '<p style="color: red;">boja</p>', 'style="color' ),
	'data- atribut'      => array( '<div data-bbh="1">podatak</div>', 'data-bbh' ),
	'obični HTML'        => array( '<h2>Naslov</h2><p><strong>tekst</strong></p>', '<h2>Naslov</h2><p><strong>tekst</strong></p>' ),
);
$description = implode( "\n", array_column( $parts, 0 ) );

$info = static fn( array $auth ): string => var_export( $call( 'GET', $base . '/bulkbird/v1/info', $auth )['json']['user']['unfiltered_html'] ?? 'nema polja (plugin stariji od 0.4.0)', true );

foreach ( array( 'Shop manager (ključ K_RW)' => array( 'key' => 'K_RW' ), 'administrator (kolačić)' => $admin ) as $who => $auth ) {
	$saved  = $call( 'PUT', $base . '/wc/v3/products/' . $id, $auth, array( 'description' => $description ) );
	$back   = (string) ( $saved['json']['description'] ?? '' );
	$stored = trim( $wpcli( 'BBH_POST_ID=' . $id, __DIR__ . '/../probe/raw-content.php' ) );
	$stored = (string) ( json_decode( $stored, true )['post_content'] ?? '' );
	echo "\n== $who · PUT status {$saved['status']} · /info user.unfiltered_html: " . $info( $auth ) . "\n";
	echo '   odgovor jednak poslanom: ' . ( $back === $description ? 'da' : 'NE' ) . ' · u bazi jednako poslanom: ' . ( $stored === $description ? 'da' : 'NE' ) . ' · odgovor jednak bazi: ' . ( $back === $stored ? 'da' : 'NE' ) . "\n";
	foreach ( $parts as $name => $part ) {
		echo sprintf( "   %-20s %s\n", $name, false !== strpos( $stored, $part[1] ) ? 'sačuvano' : 'UKLONJENO ili izmijenjeno' );
	}
	echo '   u bazi: ' . json_encode( $stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
}
