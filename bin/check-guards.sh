#!/usr/bin/env bash
# Čuvari (scenariji H0.74, H0.75, H0.76 dijelom, H0.79): pada ako plugin dobije CSS/JS,
# kuku na frontend, putanju bez provjere dozvole ili ako u repozitorij uđe tajna.
set -uo pipefail
cd "$(dirname "$0")/.."
FAIL=0
bad() { echo "PAD: $*" >&2; FAIL=1; }

# Kod plugina = ono što ulazi u paket.
mapfile -t CODE < <(git ls-files 'bulkbird-helper.php' 'uninstall.php' 'includes/*.php')
[ "${#CODE[@]}" -gt 0 ] || { echo "nema koda plugina" >&2; exit 1; }

# 1. Nula CSS/JS.
if grep -nE 'wp_(enqueue|register)_(script|style)|wp_add_inline_(script|style)|wp_localize_script|<script|<style|<link[^>]+stylesheet' "${CODE[@]}"; then
  bad "kod plugina učitava ili ispisuje CSS/JS"
fi
ASSETS=$(git ls-files | grep -vE '^(tests|docs|bin|fixtures|\.github)/' | grep -iE '\.(css|js|mjs|scss|map)$' || true)
[ -z "$ASSETS" ] || bad "CSS/JS datoteke u paketu: $ASSETS"

# 2. Nula frontenda: zabranjene kuke.
FORBIDDEN_HOOKS='wp_head|wp_footer|wp_body_open|template_redirect|template_include|wp_enqueue_scripts|the_content|the_title|init|wp|wp_loaded|plugins_loaded|after_setup_theme|shutdown|parse_request|pre_get_posts|woocommerce_before_[a-z_]+|woocommerce_after_[a-z_]+'
if grep -nE "add_(action|filter)\(\s*['\"]($FORBIDDEN_HOOKS)['\"]" "${CODE[@]}"; then
  bad "kuka koja se izvršava na stranicama za kupce"
fi

# 3. Dozvole: nikad __return_true; svaka metoda putanje ima permission_callback.
if grep -nE '__return_true' "${CODE[@]}"; then
  bad "__return_true u kodu plugina"
fi
for F in "${CODE[@]}"; do
  grep -q 'register_rest_route(' "$F" || continue
  M=$(grep -cE "'methods'\s*=>" "$F"); P=$(grep -cE "'permission_callback'\s*=>" "$F")
  [ "$M" -gt 0 ] && [ "$M" -eq "$P" ] || bad "$F: $M × 'methods', ali $P × 'permission_callback'"
done

# 4. SQL samo kroz prepare (grubo; PHPCS ima točno pravilo).
if grep -nE '\$wpdb->(query|get_var|get_row|get_col|get_results)\(\s*["'"'"']' "${CODE[@]}"; then
  bad "SQL bez \$wpdb->prepare"
fi

# 5. Tajne u repozitoriju.
if git grep -nIE '\bc[ks]_[0-9a-f]{40}\b|BEGIN [A-Z ]*PRIVATE KEY' -- . ':!bin/check-guards.sh' ; then
  bad "tajna (REST ključ ili privatni ključ) u repozitoriju"
fi
TRACKED_SECRET_FILES=$(git ls-files | grep -E '(^|/)(wp-config\.php|.*\.env|id_ed25519|id_rsa)$' || true)
[ -z "$TRACKED_SECRET_FILES" ] || bad "datoteka s tajnama u repozitoriju: $TRACKED_SECRET_FILES"

# 6. Svaka datoteka plugina se štiti od izravnog poziva.
for F in "${CODE[@]}"; do
  grep -qE "defined\( '(ABSPATH|WP_UNINSTALL_PLUGIN)' \) \|\| exit;" "$F" || bad "$F: nema zaštite od izravnog poziva"
done

if [ "$FAIL" -eq 0 ]; then echo "čuvari: sve u redu (${#CODE[@]} datoteka plugina)"; fi
exit "$FAIL"
