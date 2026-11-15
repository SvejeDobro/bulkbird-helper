# Bulkbird Helper — scenariji, faza 0

Verzija dokumenta: 2 (14. 11. 2026.) · Status: **prihvaćeno, izvedba na poziv** · Izvor: `docs/spec-v1.md`

Opseg faze 0 (strana plugina): kostur plugina, autentikacija WooCommerce ključem, `GET /bulkbird/v1/info`,
aktivacija / deaktivacija / deinstalacija, odbijanje multisitea, mjerenje učinka, postavljanje iz git
oznake, CI.

Scenarije je pregledao i prihvatio vlasnik projekta (14. 11. 2026.). Izvedba počinje tek na njegov
poziv: do tada se ne stvaraju korisnici ni ključevi, ne instaliraju alati, ne piše kod i ne mijenja
trgovina. Odluke o otvorenim pitanjima su u poglavlju 11.

Izmjene u verziji 2: testni korisnici i ključevi po načelu najmanjih prava (bez ključa administratora),
novi scenarij H0.91, H0.11–H0.13 s korisnikom Shop manager, mjerenje vremena na serveru (H0.34, H0.57),
upisane odluke P1–P13 i odluka o staroj okolini. Brojevi postojećih scenarija nisu mijenjani.

Oznake okolina:

- **NOVA** — teststore1.bulkbird.app (WP 7.1, WC 11.3, PHP 8.3), stvarni HTTP kroz Cloudflare i nginx.
- **STARA** — WP 6.4, WC 8.0, PHP 8.1 (gdje stoji: vidi poglavlje 10).
- **CI** — automatski pri svakom pushu.

Testni ključevi i korisnici (nazivi, ne vrijednosti):

| Naziv | Korisnik | Dozvola ključa |
|---|---|---|
| K-READ | `bulkbird-test-shopmgr` (uloga Shop manager) | read |
| K-RW | `bulkbird-test-shopmgr` (uloga Shop manager) | read_write |
| K-WRITE | `bulkbird-test-shopmgr` (uloga Shop manager) | write |
| K-CUST | `bulkbird-test-customer` (uloga customer, bez `manage_woocommerce`) | read_write |
| K-NEPOZNAT | izmišljen consumer key ispravnog oblika | — |

Pravila za testne korisnike i ključeve:

- Oba korisnika su nova i služe samo testovima. **Ključ administratora se ne stvara.**
- Ključevi su samo za testne korisnike demo trgovina; nikad ključ stvarnog korisnika ili produkcije.
- Vrijednosti ključeva su samo u `~/.config/bulkbird-helper/test.env` (dozvole 600, izvan repozitorija
  i web mape). Datoteku ispunjava skripta bez ispisa ili vlasnik. Nikad u razgovor, log ili repozitorij.
- Ako ključ ikad dospije u log, razgovor ili repozitorij: odmah se opoziva, stvara se novi i javlja se vlasniku.
- Stvaraju se tek nakon poziva na izvedbu; zatim se radi nova polazna kopija koja ih uključuje (P12).

---

## 1. Autentikacija istim WooCommerce ključem

Vrijedi za sve putanje plugina; u fazi 0 provjerava se na `GET /bulkbird/v1/info`.
Svaki scenarij se izvodi stvarnim HTTP zahtjevom na NOVA i STARA.

| ID | Scenarij | Očekivano |
|---|---|---|
| H0.1 | K-READ u zaglavlju `Authorization: Basic`, `GET /info` | 200, valjan JSON |
| H0.2 | K-RW u zaglavlju, `GET /info` | 200 |
| H0.3 | K-READ kao `consumer_key` / `consumer_secret` u adresi, `GET /info` | 200 (isti rezultat kao H0.1). **Od 16. 11. 2026. se stvarnim HTTP-om ne izvodi** (pravilo vlasnika: ključ samo u zaglavlju, jer adresa sa secretom ostaje u zapisu nginxa); zadnji put potvrđeno 15. 11. 2026. na obje stranice s `0.2.0`. Umjesto toga test pada ako ijedan testni zahtjev ima ključ u adresi |
| H0.4 | K-WRITE (samo pisanje), `GET /info` | 401, `woocommerce_rest_authentication_error` (ključ nema dozvolu čitanja) |
| H0.5 | Ispravan consumer key K-READ, pogrešan secret | 401, `woocommerce_rest_authentication_error` |
| H0.6 | K-NEPOZNAT (ključ koji ne postoji) | 401, `bulkbird_unauthorized`; tijelo bez ikakvih podataka iz `/info` |
| H0.7 | Bez ključa i bez kolačića | 401, `bulkbird_unauthorized` |
| H0.8 | K-CUST (valjan ključ, korisnik bez sposobnosti) | 403, `bulkbird_forbidden` |
| H0.9 | `POST /info` (metoda koja ne postoji na putanji) | s K-READ: 401 `woocommerce_rest_authentication_error` (WooCommerce odbija ključ bez dozvole pisanja prije traženja putanje; odobreno 15. 11. 2026.); s K-RW: 404; nikad 200 i nikad izvršavanje |
| H0.10 | K-READ na izmišljenu putanju `/bulkbird/v1/nepostoji` | 404 `rest_no_route` |
| H0.11 | Prijava kolačićem korisnika Shop manager (`bulkbird-test-shopmgr`) + valjan `X-WP-Nonce`, bez ključa | 200. Dodatna provjera: isto s administratorom → 200 |
| H0.12 | Kolačić korisnika Shop manager bez noncea | 401 (WordPress zahtjev tretira kao neprijavljen) |
| H0.13 | Kolačić korisnika `bulkbird-test-customer` + nonce | 403, `bulkbird_forbidden` |
| H0.14 | Opseg filtera: K-READ na tuđu putanju izvan WooCommercea, npr. `GET /wp/v2/users/me` | ponašanje **jednako kao bez plugina** (plugin ne širi ključ na tuđe putanje); uspoređuje se s plugin isključen / uključen |
| H0.15 | Opseg filtera: adresa koja samo sadrži tekst `bulkbird` (npr. `/wp-json/wp/v2/posts?search=bulkbird/v1`, `/wp-json/xbulkbird/v1/info`) | ključ se ne primjenjuje; ponašanje jednako kao bez plugina |
| H0.16 | Putanja u obliku `?rest_route=/bulkbird/v1/info` s K-READ | 200 na obje verzije WooCommercea (odluka P1). Ako na nekoj verziji ne prođe: dokumentirano ograničenje u `docs/api-v1.md`, a Bulkbird koristi zamjenski put; nikad curenje podataka |
| H0.17 | Greške 401 / 403 / 404 | WordPress oblik (`code`, `message`, `data.status`); bez traga stoga, bez putanja na disku, bez imena korisnika |
| H0.18 | Odgovor na uspješan zahtjev s ključem | zaglavlja koja zabranjuju cache (`Cache-Control` s `no-store` ili `no-cache`); bez `Set-Cookie` |
| H0.19 | WooCommerce plugin deaktiviran, Helper aktivan (samo STARA / CI) | putanje plugina nisu registrirane (404), nema PHP grešaka ni obavijesti u adminu |
| H0.20 | Zapis u debug log tijekom H0.1–H0.19 | plugin u `wp-debug.log` ne upisuje ništa (ni ključeve, ni sadržaj zahtjeva, ni PHP obavijesti) |
| H0.91 | **Bulkbirdov stvarni slučaj:** K-READ korisnika s ulogom Shop manager (ima `manage_woocommerce`, nema `manage_options`), `GET /info` | 200 s potpunim odgovorom (sva polja iz H0.21); test potvrđuje i da korisnik doista nije administrator |

Napomene uz autentikaciju (nalazi s teststore1, WC 11.3):

- WooCommerce nepoznat consumer key ne javlja kao grešku — zahtjev nastavlja kao neprijavljen. Zato H0.6
  završava u provjeri sposobnosti plugina, koja mora vratiti 401.
- WooCommerce 11.1+ ima dodatnu provjeru (`reject_out_of_scope_route`): ključ vrijedi izvan `wc/` samo
  kad adresa imenuje istu putanju koja se izvršava. Filter iz specifikacije s tim radi, ali se mora
  dokazati na obje verzije (H0.1–H0.3 na NOVA i STARA).
- Točni kodovi grešaka koje vraća sam WooCommerce (H0.4, H0.5) potvrđuju se na obje verzije; svako
  odstupanje se zapisuje u `docs/api-v1.md`.

## 2. `GET /bulkbird/v1/info`

| ID | Scenarij | Očekivano |
|---|---|---|
| H0.21 | K-READ, `GET /info` | 200; polja: `plugin_version`, `api_version` (=1), `features`, `wordpress_version`, `woocommerce_version`, `php_version`, `hpos_enabled`, `timezone`, `currency`, `max_upload_bytes`, `memory_limit`, `max_execution_time`, `detected_plugins`, `analytics_lookup` |
| H0.22 | Vrste podataka | verzije su tekst; `api_version`, `max_upload_bytes`, `max_execution_time` cijeli brojevi; `hpos_enabled` logička vrijednost; `features` i `detected_plugins` popisi |
| H0.23 | `plugin_version` | jednaka verziji u zaglavlju plugina i git oznaci s koje je postavljen |
| H0.24 | `features` u verziji 0.1.0 | prazan popis `[]` (media, changes, sales još ne postoje); svaka faza dodaje svoju oznaku (odluka P2) |
| H0.25 | Vrijednosti na NOVA | `hpos_enabled: true`, verzije 7.1.x / 11.3.x / 8.3.x, valuta i zona jednake postavkama trgovine (uspoređuje se s `wp83 option get`) |
| H0.26 | Vrijednosti na STARA | verzije 6.4.x / 8.0.x / 8.1.x; `hpos_enabled` prema postavci te trgovine |
| H0.27 | `detected_plugins` | samo slug i verzija, i samo s popisa pluginova važnih Bulkbirdu (page builderi, SEO, WPML/Polylang, cache, Omnibus); na NOVA prazan popis jer nijedan nije instaliran; ostali pluginovi trgovine se ne navode |
| H0.28 | `analytics_lookup` | `complete` ili `importing`; na NOVA `complete` |
| H0.29 | Zabranjeni sadržaj | odgovor ne sadrži: putanje na disku (`/home/`, ABSPATH), podatke baze, ključeve, salt, e-mailove, imena i ID-eve korisnika, URL admina za prijavu; provjera uzorcima nad cijelim tijelom |
| H0.30 | Nepoznati parametri (`?foo=1`, `?per_page=5`) | ignoriraju se, 200, isti odgovor |
| H0.31 | `HEAD /info` s K-READ | 200 bez tijela |
| H0.32 | Dva uzastopna zahtjeva | isti odgovor; plugin pritom ne upisuje ništa u bazu (broj upisa = 0; vidi P3 za „vrijeme zadnjeg zahtjeva”) |
| H0.33 | Otkrivanje: `GET /wp-json/` bez prijave | namespace `bulkbird/v1` je u popisu `namespaces` (Bulkbird ga tako otkriva); indeks ne otkriva verziju plugina |
| H0.34 | Vrijeme odgovora | mjeri se **na serveru** (zahtjev s localhosta ili vrijeme PHP-a kroz mjerni mu-plugin), medijan od 30 zahtjeva; cilj < 200 ms. Vrijeme kroz Cloudflare se samo zapisuje, bez praga |
| H0.35 | Primjer odgovora | test zapisuje `fixtures/v1/info.json` (bez tajni i osobnih podataka) za Bulkbird stranu |

## 3. Aktivacija, deaktivacija, deinstalacija

Na NOVA se plugin aktivira samo uz odobrenje vlasnika (mijenja popis pluginova). Deinstalacija znači
brisanje, pa se na NOVA izvodi samo uz izričito odobrenje; inače na STARA / CI.

| ID | Scenarij | Očekivano |
|---|---|---|
| H0.36 | Aktivacija (`wp83 plugin activate bulkbird-helper`) na ispravnoj okolini | uspjeh; bez PHP obavijesti u `wp-debug.log`; bez admin obavijesti; bez nove stranice ili izbornika |
| H0.37 | Stanje nakon aktivacije | u fazi 0: nijedna nova tablica, nijedan cron posao; najviše jedna vlastita opcija (verzija sheme) s `autoload = no` |
| H0.38 | Popis pluginova | redak s nazivom, verzijom, opisom; samo poveznice Aktiviraj / Deaktiviraj (bez „Postavke”) |
| H0.39 | Deklaracija HPOS-a | WooCommerce → Status ne prikazuje Helper kao nekompatibilan s HPOS-om |
| H0.40 | PHP niži od 8.1 | WordPress odbija aktivaciju prema zaglavlju `Requires PHP`; nema fatalne greške |
| H0.41 | WordPress niži od 6.4 | odbijeno prema zaglavlju `Requires at least` |
| H0.42 | WooCommerce niži od 8.0 ili neaktivan | plugin se može aktivirati, ali ne radi ništa (nema putanja, nema kuka), bez obavijesti (odluka P4) |
| H0.43 | Dvostruka aktivacija / aktivacija nakon deaktivacije | bez grešaka, bez dvostrukih zapisa |
| H0.44 | Deaktivacija | putanje nestaju (404); vlastite opcije ostaju; cron poslova plugina nema; trgovina radi kao prije |
| H0.45 | Deinstalacija (`uninstall.php`) | brišu se samo vlastite opcije (i tablica dnevnika kad bude postojala); broj proizvoda, medija, narudžbi i tuđih opcija nepromijenjen (usporedba brojeva prije i poslije) |
| H0.46 | Deinstalacija dok WooCommerce nije aktivan | uspjeh, bez fatalne greške |
| H0.47 | Izravan poziv datoteka plugina preko weba (`/wp-content/plugins/bulkbird-helper/*.php`) | nijedna ne izvršava kod niti ispisuje grešku s putanjom (provjera `ABSPATH`) |
| H0.48 | Odjeljak u WooCommerce → Status | „Bulkbird Helper”: verzija plugina i `api_version`; ostala polja iz 3.6 dolaze s fazama koje ih trebaju (odluka P3) |

## 4. Multisite

Izvodi se u CI-ju (WordPress testna okolina u multisite načinu); teststore1 se ne pretvara u multisite.

| ID | Scenarij | Očekivano |
|---|---|---|
| H0.49 | Mrežna aktivacija | odbijena s jasnom porukom („Bulkbird Helper v1 ne podržava multisite”); plugin ostaje neaktivan; nema tablica ni opcija |
| H0.50 | Aktivacija na pojedinoj stranici unutar mreže | također odbijena s istom porukom (odluka P5) |
| H0.51 | Plugin već aktivan pa se instalacija pretvori u multisite | plugin ne registrira putanje i ne radi ništa; bez fatalne greške |
| H0.52 | Poruka odbijanja | prevediva, bez HTML-a iz korisničkog unosa, s poveznicom natrag na popis pluginova |

## 5. Mjerenje učinka prije i poslije aktivacije

Mjeri se na NOVA, za neprijavljenog posjetitelja, na: naslovnici, stranici proizvoda (varijabilni
proizvod), stranici trgovine i košarici.

Način brojanja (odluka P6): mali mjerni mu-plugin iz repozitorija (`tests/tools/`). Uvjeti:

- postavlja se samo za vrijeme mjerenja i odmah nakon toga uklanja;
- **svako** postavljanje i uklanjanje traži odobrenje vlasnika;
- brojeve upisuje u zaseban log samo kad zahtjev nosi tajno zaglavlje; vrijednost zaglavlja je u
  `test.env`, nikad u repozitoriju.

| ID | Scenarij | Očekivano |
|---|---|---|
| H0.53 | Broj upita prema bazi, plugin isključen / uključen | razlika = 0 na sve četiri stranice |
| H0.54 | Učitane PHP datoteke | uključen plugin dodaje točno 1 datoteku (glavna datoteka plugina, koju WordPress uvijek učitava); nijedna druga datoteka plugina se ne učitava na stranicama za kupce (tumačenje „0 dodatnih datoteka”, odluka P7) |
| H0.55 | CSS i JS u HTML-u stranice | popis `<link>` i `<script>` identičan prije i poslije; nijedan s `bulkbird` u adresi |
| H0.56 | Kuke na frontendu | plugin nema nijednu funkciju na `wp_head`, `wp_footer`, `template_redirect`, `wp_enqueue_scripts`, `the_content` |
| H0.57 | Vrijeme odgovora, plugin isključen / uključen: mjeri se **na serveru** (zahtjev s localhosta ili vrijeme PHP-a kroz mjerni mu-plugin), medijan od 30 zahtjeva po stranici | razlika unutar šuma mjerenja na serveru (šum = razlika dvaju uzastopnih mjerenja istog stanja); obje vrijednosti se zapisuju. Vrijeme kroz Cloudflare se samo zapisuje, bez praga |
| H0.58 | Autoload opcije | veličina autoload opcija jednaka prije i poslije |
| H0.59 | Admin i REST zahtjevi izvan `bulkbird/` (npr. `GET /wc/v3/products`) | 0 dodatnih upita |
| H0.60 | Izvještaj | `docs/measurements/faza0.md` s tablicom prije / poslije, datumom, verzijama i načinom mjerenja |

## 6. Postavljanje na teststore1 iz git oznake

| ID | Scenarij | Očekivano |
|---|---|---|
| H0.61 | Izrada paketa iz oznake `v0.1.0` (`git archive`) | zip `bulkbird-helper-0.1.0.zip` + SHA-256; sadrži samo datoteke plugina (bez `tests/`, `docs/`, `.github/`, `vendor/`, `CLAUDE.md`) |
| H0.62 | Ponovljena izrada iz iste oznake | isti SHA-256 (ponovljiva izrada) |
| H0.63 | Pokušaj postavljanja s grane ili iz radne kopije s izmjenama | skripta odbija; postavlja se samo točna oznaka |
| H0.64 | Oznaka i verzija u zaglavlju plugina se razlikuju | skripta odbija |
| H0.65 | Prvo postavljanje | datoteke u `wp-content/plugins/bulkbird-helper`, vlasnik i dozvole kao ostali pluginovi; aktivacija kroz `wp83` uz odobrenje |
| H0.66 | Provjera nakon postavljanja | `GET /info` vraća `plugin_version` jednak oznaci; `wp-debug.log` bez novih redaka plugina; naslovnica 200 |
| H0.67 | Nadogradnja na novu oznaku | prethodna verzija se **premješta** u `~/backups/plugin-releases/bulkbird-helper-<verzija>-<vrijeme>/` (ne briše se), zatim se raspakira nova (odluka P8) |
| H0.68 | Povratak na prethodnu oznaku | isti postupak s ranijom oznakom; `GET /info` to potvrđuje |
| H0.69 | Plugin nema ovisnosti u izvođenju | paket radi bez `composer install` (Composer samo za razvojne alate) |
| H0.70 | Zapis o postavljanju | `~/logs/bulkbird-helper-deploy.log`: vrijeme, oznaka, SHA-256; bez tajni |

## 7. CI i statičke provjere

Iste provjere se pokreću lokalno jednom naredbom (`composer check`) i na GitHub Actions.

| ID | Scenarij | Očekivano |
|---|---|---|
| H0.71 | Sintaksa (`php -l`) na PHP 8.1, 8.2, 8.3, 8.4 | bez grešaka |
| H0.72 | PHPCS: WordPress standard (WPCS 3, sigurnosna pravila) + PHPCompatibilityWP za 8.1+ | 0 grešaka i 0 upozorenja |
| H0.73 | PHPStan s WordPress i WooCommerce definicijama | prolazi na razini 8 (odluka P9); svaka iznimka ide u `phpstan-baseline` s obrazloženjem |
| H0.74 | Provjera „nula CSS/JS i nula frontenda” | pada ako se u kodu plugina pojavi `wp_enqueue_script`, `wp_enqueue_style`, `wp_register_script`, `wp_register_style`, kuka na `wp_head`, `wp_footer`, `template_redirect`, `wp_enqueue_scripts`, ili datoteka `.css` / `.js` u paketu |
| H0.75 | Provjera dozvola | pada ako ijedna putanja nema `permission_callback` ili koristi `__return_true`; test prolazi kroz sve registrirane putanje u `bulkbird/v1` |
| H0.76 | Provjera SQL-a | pada ako se `$wpdb->query/get_*` koristi bez `prepare` (PHPCS pravilo) |
| H0.77 | Integracijski testovi u matrici | WP 6.4 + WC 8.0 + PHP 8.1; najnoviji WP + WC + PHP 8.3; multisite (poglavlje 4) |
| H0.78 | Provjera paketa | izrada zipa iz trenutnog commita uspijeva; zip ne sadrži razvojne datoteke |
| H0.79 | Provjera tajni | pada ako se u repozitoriju pojavi uzorak `ck_` / `cs_` ključa, privatni ključ ili `wp-config.php` |
| H0.80 | Zaštita grane | `main` je zelen prije svake oznake; oznaka se stvara samo s commita na kojem je CI prošao |

## 8. Namjerni kvarovi (moraju srušiti testove)

Svaki kvar je mala izmjena (`tests/faults/*.patch`); skripta ih primjenjuje jednu po jednu i očekuje pad.

| ID | Kvar | Mora pasti |
|---|---|---|
| H0.81 | `permission_callback` na `/info` vraća `true` | H0.7, H0.8, H0.75 |
| H0.82 | Uklonjena provjera sposobnosti (ostaje samo „prijavljen”) | H0.8, H0.13 |
| H0.83 | Filter za WooCommerce autentikaciju provjerava „sadrži `bulkbird`” umjesto strogog prefiksa | H0.14, H0.15 |
| H0.84 | Dodan `wp_enqueue_style` | H0.74, H0.55 |
| H0.85 | Dodana kuka na `init` koja čita opciju iz baze | H0.53 |
| H0.86 | `/info` vraća `ABSPATH` | H0.29 |
| H0.87 | Uklonjena provjera multisitea | H0.49 |
| H0.88 | Opcija plugina spremljena s autoloadom | H0.37, H0.58 |
| H0.89 | `uninstall.php` ne briše vlastitu opciju | H0.45 |
| H0.90 | Greška vraća poruku iznimke s putanjom datoteke | H0.17 |

## 9. Predloženi alati za testove

| Namjena | Alat |
|---|---|
| Integracijski testovi unutar WordPressa (putanje, sposobnosti, aktivacija, multisite) | PHPUnit 9.6 + WordPress testna okolina (`wp-phpunit`, `yoast/phpunit-polyfills`), WooCommerce učitan |
| Testovi stvarnim HTTP-om (autentikacija ključem, zaglavlja, Cloudflare/nginx) | PHPUnit, zasebna skupina `e2e`, zahtjevi kroz PHP cURL prema NOVA i STARA |
| Statička analiza | PHPStan + `szepeviktor/phpstan-wordpress` + `php-stubs/woocommerce-stubs` |
| Standard koda i sigurnosna pravila | PHPCS + WPCS 3 + PHPCompatibilityWP |
| Provjera „nula frontenda”, dozvola, tajni, paketa | male skripte u `bin/` (pokreće ih `composer check` i CI) |
| Namjerni kvarovi | `bin/run-faults.sh` nad `tests/faults/*.patch` |
| Aktivacija, deinstalacija, postavljanje | WP-CLI kroz `~/bin/wp83`; skripta `bin/deploy.sh` |
| CI | GitHub Actions (PHP 8.1–8.4, MySQL servis) |

Razvojni alati se instaliraju Composerom samo u `~/src/bulkbird-helper/vendor` (izvan web mape, ne ulaze
u paket). Na server se ne instalira ništa sustavski.

## 10. Stara okolina (WP 6.4 / WC 8.0 / PHP 8.1)

**Odluka vlasnika: A + B.** Stranicu `teststore-old.bulkbird.app` (PHP 8.1) u CloudPanelu stvara vlasnik
kad krene razvoj; CC mu tada daje popis što treba.

| Mogućnost | Ocjena |
|---|---|
| A. CI matrica na GitHub Actions | automatski pri svakom pushu, ne dira server; pokriva integracijske testove i multisite, ali ne stvarni nginx / Cloudflare |
| B. Druga CloudPanel stranica na ovom serveru (npr. `teststore-old.bulkbird.app`, PHP 8.1, WP 6.4.x, WC 8.0.x, mala demo trgovina) | jedina daje stvarne HTTP testove autentikacije na staroj verziji; mora je stvoriti vlasnik u CloudPanelu (ovaj korisnik nema ta prava) |
| C. Kontejner na serveru | nije izvedivo: nema Dockera ni sudo prava |

A odmah, za svaki commit. B za scenarije poglavlja 1 i 6 na staroj verziji, jer se autentikacija
WooCommerce ključem između 8.0 i 11.3 bitno razlikuje i mora se dokazati stvarnim zahtjevima. Za B
treba: nova stranica i baza u CloudPanelu, PHP 8.1, pristup ovom korisniku ili zaseban SSH korisnik,
Cloudflare zapis (točan popis CC daje kad krene razvoj).

## 11. Odluke vlasnika projekta (14. 11. 2026.)

| # | Pitanje | Odluka |
|---|---|---|
| P1 | Podržati `?rest_route=` oblik adrese (trgovine bez lijepih poveznica)? | **Da**, ako prođe na obje verzije WooCommercea; inače dokumentirano ograničenje i Bulkbird koristi zamjenski put |
| P2 | `features` u 0.1.0 | **Prihvaćeno:** prazan popis; svaka faza dodaje svoju oznaku |
| P3 | „Vrijeme zadnjeg Bulkbird zahtjeva” (3.6) traži upis u bazu pri zahtjevima | **Prihvaćeno:** odgođeno do faze 3; tada najviše jedan upis u 5 minuta. U fazi 0 odjeljak Status ima samo verziju i `api_version` |
| P4 | WooCommerce neaktivan ili prestar | **Prihvaćeno:** plugin tiho ne radi ništa, bez obavijesti |
| P5 | Aktivacija na jednoj stranici unutar multisite mreže | **Prihvaćeno:** odbija se, kao i mrežna |
| P6 | Način brojanja upita i datoteka | **Prihvaćeno uz uvjete:** mjerni mu-plugin iz repozitorija (`tests/tools/`); postavlja se samo za vrijeme mjerenja i odmah uklanja; tajna vrijednost zaglavlja je u `test.env`, nikad u repozitoriju; svaki put se traži odobrenje vlasnika |
| P7 | „0 dodatnih učitanih datoteka” | **Prihvaćeno:** samo glavna datoteka plugina (WordPress je uvijek učitava), nijedna druga, nula CSS/JS |
| P8 | Nadogradnja plugina na serveru zamjenjuje stare datoteke | **Prihvaćeno:** stara verzija se premješta u `~/backups/plugin-releases/`, ne briše se |
| P9 | Razina PHPStana | **Razina 8;** svaka iznimka ide u `phpstan-baseline` s obrazloženjem |
| P10 | Ograničenje učestalosti (poglavlje 4 specifikacije) | **Prihvaćeno:** uvodi se u fazi 1, prije prve putanje koja vraća podatke trgovine |
| P11 | Gdje su zapisani testni ključevi | **Prihvaćeno uz uvjete:** `~/.config/bulkbird-helper/test.env` (dozvole 600, izvan repozitorija i web mape); samo ključevi testnih korisnika demo trgovina, nikad ključ stvarnog korisnika ili produkcije; datoteku ispunjava skripta bez ispisa ili vlasnik; ako ključ ikad dospije u log, razgovor ili repozitorij, odmah se opoziva, stvara se novi i javlja se vlasniku |
| P12 | Stvaranje testnih korisnika i ključeva mijenja podatke trgovine | **Prihvaćeno:** stvaraju se nakon poziva na izvedbu, zatim nova polazna kopija koja ih uključuje |
| P13 | Rezultati GitHub Actions se sa servera ne vide | **Zasad lokalno** (`composer check`); vlasnik javlja ako CI padne |
| — | Stara okolina | **A + B** (poglavlje 10) |
