#!/usr/bin/env bash
# Provjerava pripremu integracijskih testova PRIJE pokretanja PHPUnita, da greška pripreme
# bude jasna (koji korak, što nedostaje), a ne zakopana u ispisu testova.
set -uo pipefail
cd "$(dirname "$0")/.."
FAIL=0
ok()  { echo "  u redu: $*"; }
bad() { echo "  NEDOSTAJE: $*" >&2; FAIL=1; }

CONFIG="${WP_PHPUNIT__TESTS_CONFIG:-}"
echo "1. Konfiguracija testova"
if [ -n "$CONFIG" ] && [ -r "$CONFIG" ]; then ok "WP_PHPUNIT__TESTS_CONFIG=$CONFIG"; else bad "WP_PHPUNIT__TESTS_CONFIG ne pokazuje na čitljivu datoteku ('$CONFIG')"; fi
if [ -r "$CONFIG" ]; then
  php -r '
    require $argv[1];
    $missing = array();
    foreach ( array( "ABSPATH", "DB_NAME", "DB_USER", "DB_PASSWORD", "DB_HOST", "WP_TESTS_DOMAIN", "WP_TESTS_EMAIL", "WP_TESTS_TITLE", "WP_PHP_BINARY" ) as $c ) {
      if ( ! defined( $c ) ) { $missing[] = $c; }
    }
    if ( $missing ) { fwrite( STDERR, "  NEDOSTAJE: konstante " . implode( ", ", $missing ) . "\n" ); exit( 1 ); }
    if ( ! isset( $table_prefix ) ) { fwrite( STDERR, "  NEDOSTAJE: \$table_prefix\n" ); exit( 1 ); }
    echo "  u redu: sve konstante; ABSPATH=" . ABSPATH . " WP_PHP_BINARY=" . WP_PHP_BINARY . "\n";
    if ( ! is_file( ABSPATH . "wp-settings.php" ) ) { fwrite( STDERR, "  NEDOSTAJE: WordPress u " . ABSPATH . "\n" ); exit( 1 ); }
    if ( ! is_executable( WP_PHP_BINARY ) ) { fwrite( STDERR, "  NEDOSTAJE: izvršni PHP na " . WP_PHP_BINARY . "\n" ); exit( 1 ); }
    require ABSPATH . "wp-includes/version.php";
    echo "  u redu: WordPress " . $wp_version . "\n";
    $wc = ABSPATH . "wp-content/plugins/woocommerce/woocommerce.php";
    if ( ! is_file( $wc ) ) { fwrite( STDERR, "  NEDOSTAJE: WooCommerce u wp-content/plugins\n" ); exit( 1 ); }
    echo "  u redu: WooCommerce je raspakiran\n";
    mysqli_report( MYSQLI_REPORT_OFF );
    $db = @mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
    if ( ! $db ) { fwrite( STDERR, "  NEDOSTAJE: veza s bazom " . DB_NAME . " na " . DB_HOST . " (" . mysqli_connect_error() . ")\n" ); exit( 1 ); }
    echo "  u redu: baza " . DB_NAME . " dostupna (MySQL " . mysqli_get_server_info( $db ) . ")\n";
  ' "$CONFIG" || FAIL=1
fi

echo "2. Biblioteke"
WPP=$(php -r 'require "vendor/autoload.php"; echo (string) getenv("WP_PHPUNIT__DIR");')
if [ -n "$WPP" ] && [ -f "$WPP/includes/bootstrap.php" ]; then ok "wp-phpunit: $WPP"; else bad "wp-phpunit (composer require --dev wp-phpunit/wp-phpunit)"; fi
if [ -f vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php ]; then ok "yoast/phpunit-polyfills"; else bad "yoast/phpunit-polyfills"; fi
if php -m | grep -qi '^mysqli$'; then ok "PHP mysqli"; else bad "PHP proširenje mysqli"; fi
echo "3. Okolina: PHP $(php -r 'echo PHP_VERSION;'), WP_MULTISITE=${WP_MULTISITE:-0}, BBH_WITHOUT_WC=${BBH_WITHOUT_WC:-0}"
[ "$FAIL" -eq 0 ] && echo "Priprema je potpuna." || echo "Priprema NIJE potpuna." >&2
exit "$FAIL"
