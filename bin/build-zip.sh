#!/usr/bin/env bash
# Izrada paketa plugina iz git reference (scenariji H0.61, H0.62, H0.64, H0.78).
#   bin/build-zip.sh            # iz HEAD-a (provjera u CI-ju)
#   bin/build-zip.sh v0.1.0     # iz oznake; verzija u zaglavlju mora biti jednaka oznaci
# Rezultat: build/bulkbird-helper-<verzija>.zip i .sha256 (ponovljivo: isti commit → isti SHA-256).
set -euo pipefail
cd "$(dirname "$0")/.."
REF="${1:-HEAD}"
git rev-parse -q --verify "$REF^{commit}" > /dev/null || { echo "nepoznata referenca: $REF" >&2; exit 1; }
VERSION=$(git show "$REF:bulkbird-helper.php" | sed -nE 's/^ \* Version:\s+(\S+)\s*$/\1/p' | head -1)
CONST=$(git show "$REF:bulkbird-helper.php" | sed -nE "s/^const VERSION\s+= '([^']+)';/\1/p" | head -1)
STABLE=$(git show "$REF:readme.txt" | sed -nE 's/^Stable tag:\s+(\S+)\s*$/\1/p' | head -1)
[ -n "$VERSION" ] || { echo "nema verzije u zaglavlju plugina" >&2; exit 1; }
[ "$VERSION" = "$CONST" ] || { echo "zaglavlje ($VERSION) i konstanta VERSION ($CONST) se razlikuju" >&2; exit 1; }
[ "$VERSION" = "$STABLE" ] || { echo "zaglavlje ($VERSION) i readme.txt Stable tag ($STABLE) se razlikuju" >&2; exit 1; }
if git rev-parse -q --verify "refs/tags/$REF" > /dev/null; then
  [ "v$VERSION" = "$REF" ] || { echo "oznaka $REF i verzija plugina $VERSION se razlikuju" >&2; exit 1; }
fi
mkdir -p build
OUT="build/bulkbird-helper-$VERSION.zip"
git archive --format=zip --prefix=bulkbird-helper/ -o "$OUT" "$REF"
LIST=$(unzip -Z1 "$OUT")
if echo "$LIST" | grep -E '^bulkbird-helper/(tests|docs|bin|fixtures|vendor|\.github)/|CLAUDE\.md|composer\.|\.dist$|\.env$'; then
  echo "paket sadrži razvojne datoteke" >&2; exit 1
fi
echo "$LIST" | grep -qx 'bulkbird-helper/bulkbird-helper.php' || { echo "paket nema glavnu datoteku" >&2; exit 1; }
( cd build && sha256sum "bulkbird-helper-$VERSION.zip" > "bulkbird-helper-$VERSION.zip.sha256" )
echo "paket: $OUT ($(echo "$LIST" | grep -vc '/$') datoteka)"
cat "build/bulkbird-helper-$VERSION.zip.sha256"
