#!/usr/bin/env bash
# Postavljanje plugina na testnu stranicu ISKLJUČIVO iz git oznake (scenariji H0.61–H0.70).
#
#   bin/deploy.sh v0.1.0 new     # stranica NEW iz sites.env
#   bin/deploy.sh v0.1.0 old     # stranica OLD iz sites.env (lokalno ili preko SSH aliasa)
#
# Adrese, korisnici i putanje stranica nisu u repozitoriju: čitaju se iz okoline ili iz
# ~/.config/bulkbird-helper/sites.env (vidi sites.env.example).
#
# - Prima samo oznaku (ne granu, ne commit); verzija u zaglavlju plugina mora biti jednaka oznaci.
# - Paket nastaje kroz `git archive` oznake, pa izmjene u radnoj kopiji ne mogu ući u paket.
# - Postojeća verzija plugina se NE briše: premješta se u ~/backups/plugin-releases/ na toj stranici.
# - Plugin se NE aktivira. Aktivaciju odobrava vlasnik, svaki put i za svaku stranicu.
set -euo pipefail
cd "$(dirname "$0")/.."

TAG="${1:-}"; TARGET="${2:-}"
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "prvi argument mora biti oznaka oblika v1.2.3" >&2; exit 1; }
git rev-parse -q --verify "refs/tags/$TAG" > /dev/null || { echo "oznaka $TAG ne postoji (postavlja se samo iz oznake)" >&2; exit 1; }

source bin/sites-env.sh
bbh_site "$TARGET"
WP="$SITE_WPCLI"
PLUGINS="$SITE_HOME/htdocs/$SITE/wp-content/plugins"

bin/build-zip.sh "$TAG" > /dev/null
VERSION="${TAG#v}"
ZIP="build/bulkbird-helper-$VERSION.zip"
SHA=$(cut -d' ' -f1 "$ZIP.sha256")
TS=$(date +%Y%m%d-%H%M%S)

remote() { bbh_remote "$1"; }

# 1. Paket na stranicu (izvan web mape) i provjera SHA-256.
STAGE="$SITE_HOME/tmp/bulkbird-helper-deploy-$TS"
remote "umask 077; mkdir -p '$STAGE'"
if [ -z "$SITE_SSH" ]; then cp "$ZIP" "$STAGE/"; else scp -q -o BatchMode=yes "$ZIP" "$SITE_SSH:$STAGE/"; fi
remote "cd '$STAGE' && echo '$SHA  bulkbird-helper-$VERSION.zip' | sha256sum -c - > /dev/null" || { echo "SHA-256 paketa na stranici se ne poklapa" >&2; exit 1; }

# 2. Postojeća verzija se premješta, ne briše.
PREVIOUS=$(remote "if [ -d '$PLUGINS/bulkbird-helper' ]; then sed -nE 's/^ \* Version:\s+(\S+)\s*\$/\1/p' '$PLUGINS/bulkbird-helper/bulkbird-helper.php' | head -1; fi")
if [ -n "$PREVIOUS" ]; then
  KEEP="$SITE_HOME/backups/plugin-releases/bulkbird-helper-$PREVIOUS-$TS"
  remote "umask 077; mkdir -p '$SITE_HOME/backups/plugin-releases' && mv '$PLUGINS/bulkbird-helper' '$KEEP'"
  echo "prethodna verzija $PREVIOUS premještena u $KEEP"
fi

# 3. Raspakiravanje s dozvolama kao ostali pluginovi.
remote "umask 002; unzip -q '$STAGE/bulkbird-helper-$VERSION.zip' -d '$PLUGINS' && test -f '$PLUGINS/bulkbird-helper/bulkbird-helper.php'"

# 4. Provjera i zapis.
STATUS=$(remote "$WP plugin get bulkbird-helper --field=status 2>/dev/null" || echo "?")
INSTALLED=$(remote "$WP plugin get bulkbird-helper --field=version 2>/dev/null" || echo "?")
[ "$INSTALLED" = "$VERSION" ] || { echo "na stranici je verzija '$INSTALLED', očekivano '$VERSION'" >&2; exit 1; }
mkdir -p "$HOME/logs"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) target=$TARGET site=$SITE tag=$TAG sha256=$SHA previous=${PREVIOUS:-none} status=$STATUS" >> "$HOME/logs/bulkbird-helper-deploy.log"
echo "postavljeno: $SITE, bulkbird-helper $VERSION (stanje: $STATUS). Paket ostaje u $STAGE."
echo "Plugin NIJE aktiviran ovom skriptom."
