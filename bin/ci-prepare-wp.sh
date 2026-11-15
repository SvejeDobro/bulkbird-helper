#!/usr/bin/env bash
# Priprema WordPress + WooCommerce za integracijske testove u CI-ju (scenarij H0.77).
#   bin/ci-prepare-wp.sh <verzija WordPressa|latest> <verzija WooCommercea|latest>
# Treba: wp-cli, unzip, curl, MySQL na 127.0.0.1:3306 (root/root, baza wordpress_tests — samo CI servis).
set -euo pipefail
WP_VERSION="${1:-latest}"; WC_VERSION="${2:-latest}"
WP_DIR="${WP_DIR:-/tmp/wordpress}"

mkdir -p "$WP_DIR"
if [ "$WP_VERSION" = "latest" ]; then
  wp core download --path="$WP_DIR" --locale=en_US --force
else
  wp core download --path="$WP_DIR" --locale=en_US --version="$WP_VERSION" --force
fi
RESOLVED=$(sed -nE "s/^\\\$wp_version = '([^']+)';/\1/p" "$WP_DIR/wp-includes/version.php")

if [ "$WC_VERSION" = "latest" ]; then
  WC_ZIP="https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip"
else
  WC_ZIP="https://downloads.wordpress.org/plugin/woocommerce.$WC_VERSION.zip"
fi
curl -fsSL "$WC_ZIP" -o /tmp/woocommerce.zip
unzip -q -o /tmp/woocommerce.zip -d "$WP_DIR/wp-content/plugins"

cat > "$WP_DIR/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '$WP_DIR/' );
define( 'DB_NAME', 'wordpress_tests' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Bulkbird Helper tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
PHP

# wp-phpunit u grani koja odgovara verziji WordPressa (npr. 6.4.14 -> ~6.4.0).
BRANCH=$(echo "$RESOLVED" | sed -E 's/^([0-9]+\.[0-9]+).*/\1/')
composer require --dev --no-interaction --no-progress "wp-phpunit/wp-phpunit:~$BRANCH.0"
echo "WordPress $RESOLVED, WooCommerce $(sed -nE 's/^ \* Version: (.*)$/\1/p' "$WP_DIR/wp-content/plugins/woocommerce/woocommerce.php"), wp-phpunit ~$BRANCH.0"
