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
učitavaju tek kad se pozove naša putanja. Na staroj stranici to nije ponovno mjereno (treba novo odobrenje za
mjerni mu-plugin); potvrđeno je mjerenjem na teststore1 (niže). U WP-CLI-ju se ne može provjeriti jer
WooCommerce ondje pri pokretanju čita sheme svih REST putanja.

## teststore1.bulkbird.app

15. 11. 2026. · WordPress 7.1.4, WooCommerce 11.3.0, PHP 8.3 (FPM), Storefront 4.6.2, HPOS uključen.
Plugin pri mjerenju „poslije”: `0.1.0-rc.2`. Varnish nije u putu zahtjeva (vlasnik je u vhostu postavio
`proxy_pass` izravno na 8080; odgovori nemaju `x-cache-age`), Cloudflare ne sprema (`DYNAMIC`).

**Način mjerenja stranica za kupce.** Trgovina je u WooCommerce načinu „Coming soon”, a privatna poveznica
za pregled je isključena; postavke nisu mijenjane. Zato je svaka stranica mjerena dvaput:

- **neprijavljen** — ono što posjetitelj danas stvarno dobiva: stranicu „uskoro” (ne stvarnu stranicu trgovine);
- **Shop manager** — s kolačićem testnog korisnika `bulkbird-test-shopmgr`, koji vidi stvarne stranice trgovine.
  Prijavljeni zahtjev radi više upita od neprijavljenog, ali je usporedba prije/poslije na istim uvjetima.

| Stranica | Tko | Upiti prije | Upiti poslije | PHP datoteke prije → poslije | Datoteke plugina poslije | PHP ms prije (a / b) | PHP ms poslije (a / b) |
|---|---|---|---|---|---|---|---|
| Naslovnica | neprijavljen | 54 | 53–60 (a), 54 (b) | 1475 → 1476 | `bulkbird-helper.php` | 115,3 / 114,6 | 119,3 / 118,2 |
| Naslovnica | Shop manager | 80 | 79 (a), 80 (b) | 1504 → 1505 | `bulkbird-helper.php` | 146,4 / 144,8 | 146,3 / 150,4 |
| Trgovina `/shop/` | neprijavljen | 54 | 53 (a), 54 (b) | 1475 → 1476 | `bulkbird-helper.php` | 114,6 / 113,5 | 114,5 / 119,4 |
| Trgovina `/shop/` | Shop manager | 80 | 79 (a), 80–82 (b) | 1504 → 1505 | `bulkbird-helper.php` | 144,8 / 145,4 | 150,0 / 144,4 |
| Proizvod (varijabilni) | neprijavljen | 51 | 51 | 1484 → 1485 | `bulkbird-helper.php` | 112,2 / 110,2 | 115,5 / 114,3 |
| Proizvod (varijabilni) | Shop manager | 113 | 113 | 1526 → 1527 | `bulkbird-helper.php` | 189,2 / 190,6 | 195,3 / 192,9 |
| Košarica | neprijavljen | 48 | 47–49 (a), 48 (b) | 1474 → 1475 | `bulkbird-helper.php` | 110,6 / 111,9 | 114,2 / 112,5 |
| Košarica | Shop manager | 98 (jednom 105) | 98 | 1554 → 1555 | `bulkbird-helper.php` | 164,7 / 162,8 | 170,0 / 163,9 |
| `GET /wc/v3/products` (ključ) | — | 60 (jednom 62) | 60 | 1448 → 1449 | `bulkbird-helper.php` | 81,2 / 80,2 | 84,3 / 81,0 |
| `GET /bulkbird/v1/info` (ključ) | — | — | 49 | 1699 | glavna + 3 | — | 100,9 / 99,8 |

Rezultat po scenarijima:

| Scenarij | Rezultat |
|---|---|
| H0.53 — upiti na stranicama za kupce | **0 dodatnih.** Uobičajena vrijednost je ista prije i poslije na svih osam mjerenja. Pojedinačna odstupanja (±1, jednom +6 ili +7) javljaju se i prije i poslije aktivacije i ne ponavljaju se; potječu od povremenih poslova WordPressa i WooCommercea, ne od plugina (plugin na tim stranicama nema nijednu kuku ni upit) |
| H0.54 — učitane PHP datoteke | **+1** (samo glavna datoteka plugina) |
| H0.57 — vrijeme PHP-a | medijani poslije su 0–6 ms viši od medijana prije; razlika dvaju mjerenja istog stanja je do 6 ms (npr. Shop manager, trgovina poslije: 150,0 i 144,4). **Unutar šuma**, ali uz napomenu: na pet od devet redaka oba mjerenja „poslije” su malo iznad oba „prije” (2–4 ms). Mjerenja su išla jedno za drugim, pa to može biti i pomak opterećenja servera; učitavanje jedne datoteke od 9 KB to ne objašnjava. Vrijedi ponoviti s izmjeničnim redoslijedom ako je važno |
| H0.59 — tuđi REST zahtjev (`wc/v3`) | **0 dodatnih upita, +1 datoteka** (samo glavna) — potvrda ispravka iz `0.1.0-rc.2` |
| H0.34 — `GET /info` | 49 upita (svi čitanje), PHP medijan **100 ms** (cilj < 200 ms); kroz Cloudflare medijan 153 ms (samo zapis) |
| H0.55 — CSS/JS | HTML naslovnice, trgovine i košarice ne spominje plugin |
| H0.58 — autoload opcije | plugin ima jednu opciju, bez automatskog učitavanja |

Vrijeme kroz Cloudflare prije aktivacije (samo zapis): neprijavljen 175–189 ms, Shop manager 209–261 ms,
`wc/v3/products` 140–144 ms.
