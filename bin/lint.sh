#!/usr/bin/env bash
# php -l nad kodom plugina na svim dostupnim verzijama PHP-a od 8.1 naviše (scenarij H0.71).
set -euo pipefail
cd "$(dirname "$0")/.."
FILES=$(git ls-files '*.php' | grep -vE '^(vendor|tests/faults)/')
FOUND=0
for V in 8.1 8.2 8.3 8.4; do
  BIN="$(command -v "php$V" || true)"
  if [ -z "$BIN" ] && [ "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" = "$V" ]; then BIN="$(command -v php)"; fi
  [ -n "$BIN" ] || continue
  FOUND=$((FOUND+1))
  for F in $FILES; do "$BIN" -l "$F" > /dev/null || { echo "php$V: greška sintakse u $F" >&2; exit 1; }; done
  echo "php$V: sintaksa ispravna ($(echo "$FILES" | wc -l) datoteka)"
done
[ "$FOUND" -gt 0 ] || { echo "nijedan PHP 8.1–8.4 nije pronađen" >&2; exit 1; }
