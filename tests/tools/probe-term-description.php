<?php
/**
 * Mjeri što WooCommerce REST napravi s opisom pojma (kategorija, oznaka, brend) ovisno o korisniku
 * (docs/measurements/term-description-kses.md).
 *
 *   BBH_TARGET=NEW php tests/tools/probe-term-description.php > izlaz.json
 *
 * Stvara pojmove "bbh-test-…" (po jedan za svaku taksonomiju i korisnika); nakon mjerenja pokrenuti
 * tests/tools/cleanup-test-terms.php. Sprema ključem Shop managera i prijavom administratora
 * (kolačić + nonce, 5 minuta). Ključ ide samo u zaglavlju. Ništa tajno se ne ispisuje.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

require_once __DIR__ . '/sites.php';

$target = strtoupper( (string) ( getenv( 'BBH_TARGET' ) ?: 'NEW' ) );
$env    = array();
foreach ( (array) file( (string) getenv( 'HOME' ) . '/.config/bulkbird-helper/test.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	if ( preg_match( '/^([A-Z0-9_]+)=(.*)$/', (string) $line, $m ) ) {
		$env[ $m[1] ] = $m[2];
	}
}
$base  = rtrim( $env[ "BBH_{$target}_URL" ], '/' ) . '/wp-json/wc/v3/products/';
$site   = bbh_site( $target );
$remote = static fn( string $assign ): string => $assign . ' ' . $site['wpcli'] . ' eval-file -';
$wpcli  = '' === $site['ssh']
	? static fn( string $assign, string $file ): string => (string) shell_exec( $remote( $assign ) . ' < ' . escapeshellarg( $file ) . ' 2>/dev/null' )
	: static fn( string $assign, string $file ): string => (string) shell_exec( 'ssh -o BatchMode=yes ' . escapeshellarg( $site['ssh'] ) . ' ' . escapeshellarg( $remote( $assign ) ) . ' < ' . escapeshellarg( $file ) . ' 2>/dev/null' );
$json_of = static function ( string $output ): array {
	$start = strpos( $output, '{' );
	return false === $start ? array() : (array) json_decode( substr( $output, $start ), true );
};

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

$session = $json_of( $wpcli( 'BBH_ROLE=administrator', __DIR__ . '/session.php' ) );
$users   = array(
	'shopmgr' => array( 'key' => 'K_RW' ),
	'admin'   => array( 'cookie' => $session['cookie_name'] . '=' . rawurlencode( (string) $session['cookie_value'] ), 'nonce' => (string) $session['nonce'] ),
);

// Svaki dio je zaseban redak, da se u spremljenom tekstu vidi što se s kojim dogodilo.
$parts = array(
	'p'            => '<p>Odlomak teksta.</p>',
	'h2'           => '<h2>Podnaslov</h2>',
	'strong'       => '<strong>naglašeno</strong>',
	'a href'       => '<a href="https://example.com/stranica?a=1&amp;b=2" title="naslov" target="_blank" rel="noopener">poveznica</a>',
	'img'          => '<img src="https://example.com/slika.jpg" alt="opis slike" width="10" height="10">',
	'ul'           => '<ul><li>prva</li><li>druga</li></ul>',
	'iframe'       => '<iframe src="https://example.com/embed" width="300" height="200"></iframe>',
	'script'       => '<script>window.bbhTest = 1;</script>',
	'style'        => '<style>.bbh-test { color: red; }</style>',
	'onclick'      => '<p onclick="bbhTest()">klik</p>',
	'kosa crta'    => 'C:\\temp\\novi i dvije: a\\\\b',
	'ampersand'    => 'Sol & papar, entitet &amp; i &lt;znak&gt;',
	'navodnici'    => 'Dvostruki "navodnici" i jednostruki \'navodnici\'',
	'hrvatska'     => 'Čaša, žlica, šećer, đak, ćup — ČŽŠĐĆ',
	'embed'        => '[embed]https://example.com/video[/embed]',
);
$description = implode( "\n", $parts );

$out = array(
	'target' => $target,
	'sent'   => $description,
	'parts'  => $parts,
	'cases'  => array(),
);
foreach ( array( 'categories' => 'cat', 'tags' => 'tag', 'brands' => 'brand' ) as $endpoint => $short ) {
	foreach ( $users as $who => $auth ) {
		$name    = 'bbh-test-' . $short . '-' . $who;
		$created = $call( 'POST', $base . $endpoint, $auth, array( 'name' => $name, 'description' => $description ) );
		$case    = array( 'endpoint' => $endpoint, 'user' => $who, 'post_status' => $created['status'] );
		$id      = (int) ( $created['json']['id'] ?? 0 );
		if ( 0 === $id ) {
			$case['error']  = (string) ( $created['json']['code'] ?? 'nema odgovora' );
			$out['cases'][] = $case;
			continue;
		}
		$case['id']            = $id;
		$case['post_response'] = (string) ( $created['json']['description'] ?? '' );
		$case['post_stored']   = (string) ( $json_of( $wpcli( 'BBH_TERM_ID=' . $id, __DIR__ . '/../probe/raw-term.php' ) )['description'] ?? '' );

		// Izmjena postojećeg pojma istim opisom (drugi put kroz kod), zatim čitanje.
		$updated               = $call( 'PUT', $base . $endpoint . '/' . $id, $auth, array( 'description' => $description ) );
		$case['put_status']    = $updated['status'];
		$case['put_response']  = (string) ( $updated['json']['description'] ?? '' );
		$case['put_stored']    = (string) ( $json_of( $wpcli( 'BBH_TERM_ID=' . $id, __DIR__ . '/../probe/raw-term.php' ) )['description'] ?? '' );
		$read                  = $call( 'GET', $base . $endpoint . '/' . $id, $auth );
		$case['get_response']  = (string) ( $read['json']['description'] ?? '' );
		$out['cases'][]        = $case;
	}
}
echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
