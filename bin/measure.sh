#!/usr/bin/env bash
# Mjerenje učinka plugina na testnoj stranici (scenariji H0.53–H0.60, H0.34; odluka P6).
#
#   bin/measure.sh install <new|old>          # postavlja mjerni mu-plugin  — SAMO UZ ODOBRENJE VLASNIKA
#   bin/measure.sh run     <new|old> <oznaka> # 30 zahtjeva po stranici; oznaka npr. before / after
#   bin/measure.sh report  <new|old>          # tablica medijana po oznaci i stranici
#   bin/measure.sh remove  <new|old>          # uklanja mjerni mu-plugin    — odmah nakon mjerenja, UZ ODOBRENJE
#
# Tajna vrijednost zaglavlja je samo u ~/.config/bulkbird-helper/test.env (BBH_MEASURE_SECRET);
# u mu-plugin na stranici ulazi samo njezin SHA-256. Log mjerenja je izvan web mape.
set -euo pipefail
cd "$(dirname "$0")/.."

ACTION="${1:-}"; TARGET="${2:-}"; LABEL="${3:-}"
ENV_FILE="${BBH_ENV_FILE:-$HOME/.config/bulkbird-helper/test.env}"
# Adrese, korisnici i putanje stranica: iz okoline ili iz sites.env (vidi sites.env.example).
source bin/sites-env.sh
bbh_site "$TARGET"
PFX="$SITE_PFX"
MU_DIR="$SITE_HOME/htdocs/$SITE/wp-content/mu-plugins"
MU_FILE="$MU_DIR/bulkbird-measure.php"
LOG="$SITE_HOME/logs/bulkbird-measure.log"
remote() { bbh_remote "$1"; }
envget() { sed -nE "s/^$1=(.*)$/\1/p" "$ENV_FILE" | tail -1; }

case "$ACTION" in
  install)
    if [ -z "$(envget BBH_MEASURE_SECRET)" ]; then
      ( umask 077; printf '\n# Tajna vrijednost zaglavlja X-Bbh-Measure za mjerni mu-plugin\nBBH_MEASURE_SECRET=%s\n' "$(openssl rand -hex 24)" >> "$ENV_FILE" )
    fi
    HASH=$(printf '%s' "$(envget BBH_MEASURE_SECRET)" | sha256sum | cut -d' ' -f1)
    remote "test ! -e '$MU_FILE'" || { echo "mjerni mu-plugin je već postavljen" >&2; exit 1; }
    sed -e "s|__HASH__|$HASH|" -e "s|__LOG__|$LOG|" tests/tools/bulkbird-measure.php | remote "umask 007; mkdir -p '$MU_DIR' && cat > '$MU_FILE' && php -l '$MU_FILE' > /dev/null"
    echo "mjerni mu-plugin postavljen: $MU_FILE (log: $LOG)"
    ;;
  remove)
    remote "test -e '$MU_FILE'" || { echo "mjerni mu-plugin nije postavljen"; exit 0; }
    remote "rm '$MU_FILE'"
    remote "test ! -e '$MU_FILE'" && echo "mjerni mu-plugin uklonjen: $MU_FILE (log ostaje: $LOG)"
    ;;
  run)
    [[ "$LABEL" =~ ^[A-Za-z0-9_.-]+$ ]] || { echo "treći argument: oznaka mjerenja (npr. before, after)" >&2; exit 1; }
    BBH_TARGET="$PFX" BBH_RUN="$LABEL" php8.3 tests/tools/measure-run.php
    ;;
  report)
    remote "cat '$LOG' 2>/dev/null" | php8.3 tests/tools/measure-report.php
    ;;
  *) echo "prvi argument: install | run | report | remove" >&2; exit 1 ;;
esac
