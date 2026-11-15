# Mjerenje učinka — faza 0

Scenariji H0.53–H0.60 i H0.34. Način: privremeni mjerni mu-plugin (`tests/tools/bulkbird-measure.php`,
odluka P6) koji za zahtjeve s tajnim zaglavljem bilježi broj upita prema bazi, broj učitanih PHP datoteka,
datoteke plugina i vrijeme PHP-a. Mu-plugin je postavljen samo za vrijeme mjerenja i odmah uklonjen.
Svako mjerenje: 30 zahtjeva po stranici, svaki s jedinstvenim parametrom u adresi (da ne dođe iz cachea).
„Prije” i „poslije” su mjereni po dvaput (a, b); razlika između a i b istog stanja je šum mjerenja.

## Stara stranica — teststoreold.bulkbird.app

15. 11. 2026. · WordPress 6.4.14, WooCommerce 8.0.3, PHP 8.1 (FPM), Storefront 4.6.2, HPOS isključen.
Plugin pri mjerenju „poslije”: `0.1.0-rc.1`. Varnish i Cloudflare ne spremaju ove odgovore
(`cf-cache-status: DYNAMIC`, bez `x-cache-age`). Stranice su mjerene kao **neprijavljeni posjetitelj**
(WooCommerce 8.0 nema način „Coming soon”, pa posjetitelj vidi stvarne stranice).

| Stranica | Upiti prije | Upiti poslije | PHP datoteke prije | PHP datoteke poslije | Datoteke plugina poslije | PHP ms prije (a / b) | PHP ms poslije (a / b) |
|---|---|---|---|---|---|---|---|
| Naslovnica (= trgovina) | 136 | 136 | 1236 | 1237 | `bulkbird-helper.php` | 134,5 / 134,3 | 133,9 / 134,4 |
| Trgovina `/shop/` | 136 | 136 | 1236 | 1237 | `bulkbird-helper.php` | 135,0 / 135,3 | 134,8 / 133,2 |
| Proizvod (varijabilni) | 139 | 139 | 1257–1258 | 1258–1259 | `bulkbird-helper.php` | 161,3 / 163,2 | 161,1 / 163,9 |
| Košarica | 68 | 68 | 1228 | 1229 | `bulkbird-helper.php` | 97,4 / 96,8 | 96,3 / 96,3 |
| `GET /wc/v3/products` (ključ) | 83 | 83 | 1406–1407 | 1410–1411 | glavna + 3 (vidi napomenu) | 132,9 / 132,9 | 133,1 / 132,6 |
| `GET /bulkbird/v1/info` (ključ) | — | 71 | — | 1409 | glavna + 3 | — | 123,9 / 124,3 |

Rezultat po scenarijima:

| Scenarij | Rezultat |
|---|---|
| H0.53 — upiti na stranicama za kupce | **0 dodatnih** na sve četiri stranice |
| H0.54 — učitane PHP datoteke | **+1** (samo glavna datoteka plugina), nijedna druga |
| H0.57 — vrijeme PHP-a | razlika prije/poslije (do 1,5 ms) nije veća od razlike dvaju mjerenja istog stanja (do 2,8 ms): **unutar šuma** |
| H0.59 — tuđi REST zahtjev (`wc/v3`) | **0 dodatnih upita**; u `0.1.0-rc.1` su se uz glavnu učitavale i 3 pomoćne datoteke |
| H0.34 — `GET /info` | 71 upit (svi čitanje), PHP medijan **124 ms** (cilj < 200 ms); kroz Cloudflare medijan 181–186 ms (samo zapis) |
| H0.58 — autoload opcije | plugin ima jednu opciju, bez automatskog učitavanja (provjereno u bazi, scenarij H0.37) |

Vrijeme kroz Cloudflare (samo zapis, bez praga), medijan od 30 zahtjeva, prije → poslije:
naslovnica 202 → 197 ms, trgovina 200–203 → 197 ms, košarica 163–165 → 161 ms, proizvod 229 → 224–227 ms,
`wc/v3/products` 190–195 → 188–193 ms.

**Napomena uz H0.59.** Mjerenje je pokazalo da `0.1.0-rc.1` na svakom REST zahtjevu (i tuđem) učitava tri
pomoćne datoteke, bez ijednog upita. U `0.1.0-rc.2` putanje se registriraju samo imenom funkcije, a klase se
učitavaju tek kad se pozove naša putanja. To za web zahtjeve još nije izmjereno (treba ponovno postaviti
mjerni mu-plugin, uz odobrenje); u WP-CLI-ju se ne može provjeriti jer WooCommerce ondje pri pokretanju čita
sheme svih REST putanja.

## teststore1.bulkbird.app

Još nije mjereno: nginx i dalje šalje HTML kroz Varnish iako je u CloudPanelu isključen (`x-cache-age` raste
na naslovnici i stranici proizvoda), pa je vlasnik odredio da se ondje ništa ne mijenja dok se to ne riješi.
Trgovina je u načinu „Coming soon”; mjerit će se preko privatne poveznice za pregled ako je to jednostavan
prekidač (stanje se vraća na kraju), inače s kolačićem testnog korisnika Shop manager.
