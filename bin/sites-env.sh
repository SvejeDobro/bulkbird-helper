#!/usr/bin/env bash
# Opis testne stranice iz okoline ili iz ~/.config/bulkbird-helper/sites.env (vidi sites.env.example).
# Uključuje se sa `source`; bbh_site <new|old> postavlja SITE_PFX, SITE_SSH, SITE_HOME, SITE i SITE_WPCLI.
# U repozitoriju nema adresa, korisnika ni putanja stranica.

bbh_site() {
  local file line key name var missing=""
  case "${1:-}" in
    new) SITE_PFX=NEW ;;
    old) SITE_PFX=OLD ;;
    *) echo "drugi argument mora biti new ili old" >&2; return 1 ;;
  esac
  file="${BBH_SITES_FILE:-$HOME/.config/bulkbird-helper/sites.env}"
  if [ -r "$file" ]; then
    while IFS= read -r line || [ -n "$line" ]; do
      [[ "$line" =~ ^(BBH_SITE_[A-Z0-9_]+)=(.*)$ ]] || continue
      key="${BASH_REMATCH[1]}"
      # Varijabla iz okoline ima prednost pred datotekom.
      [ -n "${!key+x}" ] || printf -v "$key" '%s' "${BASH_REMATCH[2]}"
    done < "$file"
  fi
  for name in HOME HOST WPCLI; do
    var="BBH_SITE_${SITE_PFX}_${name}"
    [ -n "${!var:-}" ] || missing="$missing $var"
  done
  if [ -n "$missing" ]; then
    echo "Nisu postavljene varijable:$missing." >&2
    echo "Postavite ih u okolini ili u datoteci $file (primjer: sites.env.example u repozitoriju)." >&2
    return 1
  fi
  var="BBH_SITE_${SITE_PFX}_SSH";   SITE_SSH="${!var:-}"
  var="BBH_SITE_${SITE_PFX}_HOME";  SITE_HOME="${!var}"
  var="BBH_SITE_${SITE_PFX}_HOST";  SITE="${!var}"
  var="BBH_SITE_${SITE_PFX}_WPCLI"; SITE_WPCLI="${!var}"
}

# Izvršava naredbu na stranici: lokalno ako SITE_SSH nije zadan, inače preko SSH aliasa.
bbh_remote() { if [ -z "$SITE_SSH" ]; then bash -c "$1"; else ssh -o BatchMode=yes "$SITE_SSH" "$1"; fi; }
