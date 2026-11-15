# CLAUDE.md — bulkbird-helper

Repozitorij WordPress plugina **Bulkbird Helper** (`SvejeDobro/bulkbird-helper`).
Jedini izvor zahtjeva: `docs/spec-v1.md`. Ugovor API-ja: `docs/api-v1.md` (nastaje u fazi 0).
Ovaj CC je zadužen za stranu plugina. Bulkbird program (Windows) radi drugi CC i ovaj repozitorij ne mijenja.

## Pravila servera (vrijede uvijek; izvor: `~/NOTES-bulkbird.md`)

1. **Tajne nikad u razgovor ni u datoteku.** Lozinke, salt i ključevi iz `wp-config.php`, WooCommerce
   consumer key/secret, tokeni, privatni SSH ključ — ne ispisuju se i ne zapisuju. U repozitorij ne smije
   ući nijedna tajna (ni u testove, primjere odgovora, CI konfiguraciju, poruke commita).
2. **Osobni podaci kupaca se ne ispisuju — ni lažni/demo.** Iz baze samo brojevi i agregati.
   Primjeri odgovora (`fixtures/`) ne smiju sadržavati imena, e-mailove, adrese, ID-eve narudžbi.
3. **Brisanje samo uz izričito odobrenje vlasnika** (datoteke, tablice, retci, pluginovi, kopije, logovi).
4. **Polazna kopija se vraća samo na izričit zahtjev vlasnika** (`~/bin/restore-baseline.sh`).
5. **WP-CLI samo kroz `~/bin/wp83`** (PHP 8.3, isti kao web). Goli `wp` koristi PHP 8.4 — ne koristiti.
6. Bez odobrenja se ne mijenjaju pluginovi, tema, postavke WooCommercea ni podaci trgovine;
   ništa se ne instalira na server i ne restarta.

Iznimka od pravila 1 (odluka vlasnika P11, 14. 11. 2026.): ključevi **testnih** korisnika demo trgovina
smiju biti u `~/.config/bulkbird-helper/test.env` (dozvole 600, izvan repozitorija i web mape). Datoteku
ispunjava skripta bez ispisa ili vlasnik. Nikad ključ stvarnog korisnika ili produkcije. Ako ključ
dospije u log, razgovor ili repozitorij: odmah ga opozvati, stvoriti novi i javiti vlasniku.

## Brisanje testnih slika (trajno odobrenje vlasnika, 15. 11. 2026., odluka P17)

Jedino brisanje koje testovi smiju raditi bez posebnog pitanja:

- samo privitci kojima **naslov počinje s `bbh-test-`** I koji imaju meta **`_bulkbird_upload_id`** (oba uvjeta);
- samo na **teststore1** i **teststoreold**;
- samo kroz **WP-CLI** (`tests/tools/cleanup-test-media.php`);
- **prije brisanja ispisati broj i ID-jeve**.

Ništa drugo se ne briše bez izričitog odobrenja: ni drugi privitci, ni proizvodi, ni narudžbe, ni datoteke.

## CI (GitHub Actions)

- CI pratim sam: nakon svakog pusha `~/bin/ci-status --wait <commit|oznaka|grana>` i rezultat u izvještaj.
  Pali posao: popraviti, pushati, pričekati; ako je uzrok u pluginu (ne u testu ili pripremi), opisati ga.
- Token je u `~/.config/bulkbird-helper/github-ci-token` (600): fine-grained, samo ovaj repozitorij,
  **samo čitanje Actionsa** (i Metadata). Nikad se ne ispisuje, ne zapisuje u log, repozitorij ni bilješke
  i ne stavlja u argumente naredbi; čita se iz datoteke u zaglavlje zahtjeva.
- **Token istječe u utorak 16. 11. 2027.** Kad istekne ili prestane raditi (`ci-status` izlazi s 4),
  javiti vlasniku da napravi novi.
- Ako poslovi nisu pokrenuti zbog potrošenih minuta ili limita potrošnje, javiti vlasniku odmah.
- CI se pokreće na `main`, na granama `paket-*` i na oznakama `v*`.

## Stanje

Faza 0 (`0.1.0`) i faze 1 i 2 (`0.2.0`) su na grani `main` (oznake `v0.1.0`, `v0.2.0`); `0.2.0` je aktivan na
obje testne stranice. **Plugin miruje** (odluka vlasnika 16. 11. 2026.): faze 3 i 4 se ne počinju dok vlasnik
ne odluči. Bulkbird program koristi `0.2.0` prema konačnom ugovoru `docs/api-v1.md`; ugovor se mijenja samo
uz odobrenje vlasnika.

- Grana `paket-0.2.1`: popravak redoslijeda (401/403 prije 400 za neispravan parametar). **Ne tagira se i
  ne spaja u main dok vlasnik ne odobri**; nije postavljena na stranice.
- Grana `prijedlog-0.3.0`: samo dokumenti (`docs/proposals/`). Izrada čeka odgovore vlasnika.

Aktivaciju plugina i mjerni mu-plugin treba tražiti od vlasnika posebno, svaki put i za svaku stranicu.

## Gdje je što

| Što | Putanja |
|---|---|
| Radna kopija repozitorija | `~/src/bulkbird-helper` (izvan web mape) |
| Demo trgovina | `~/htdocs/teststore1.bulkbird.app` — `https://teststore1.bulkbird.app` |
| Plugin na trgovini | `wp-content/plugins/bulkbird-helper` — **samo iz git oznake**, nikad uređivati na mjestu |
| Polazna kopija trgovine | `~/backups/baseline-20261114/` |
| WordPress debug log | `~/logs/wp-debug.log` |
| PHP / nginx logovi | `~/logs/php/error.log`, `~/logs/nginx/` (access log sadrži IP adrese — ne ispisivati) |
| Git udaljeni repozitorij | `git@github-bulkbird-helper:SvejeDobro/bulkbird-helper.git` (deploy ključ, alias u `~/.ssh/config`) |

## Način rada

- Faze iz specifikacije (poglavlje 9). Svaka faza: **scenariji → odobrenje → izvedba → testovi i
  namjerni kvarovi → izvještaj → odobrenje.** Kod se ne piše prije odobrenih scenarija.
- Scenariji su u `docs/scenarios-fazaN.md`, numerirani `HN.x`. Svaki test navodi ID scenarija.
- Promjena ugovora (`docs/api-v1.md`) samo uz odobrenje vlasnika projekta.
- Najniže verzije: WordPress 6.4, WooCommerce 8.0, PHP 8.1. Kod ne smije koristiti ništa novije.
- Identitet: slug `bulkbird-helper`, namespace `Bulkbird\Helper`, REST `bulkbird/v1`, SemVer, GPLv2+.
- Commit poruke kratke, na hrvatskom ili engleskom; bez tajni i bez osobnih podataka.

## Koraci koji mogu pasti (pravilo vlasnika, 16. 11. 2026.)

Testovi, provjere i izrada **nikad se ne ulančavaju tako da sljedeći korak ide i nakon greške**:

- koristiti `set -e` (uz `set -o pipefail` kad je u lancu cijev) ili `&&`, ili zasebne pozive s provjerom
  izlaznog koda;
- izlaz testa se ne provlači kroz `| tail` ili `| grep` ispred `&&`: tada vrijedi izlazni kod zadnje naredbe
  u cijevi, a ne testa;
- **oznaka i push nikad ne idu prije nego što su testovi stvarno prošli.**

Primjer greške: oznaka `v0.1.0` od 15. 11. 2026. Naredba `phpunit … | tail -2 && git commit … && git tag …
&& git push` poslala je oznaku iako je phpunit javio 42 greške (kriva naredba, bez bootstrapa). Testovi su
prošli tek naknadno.

## Ključevi u testovima (pravilo vlasnika, 16. 11. 2026.)

- **Ključ se šalje samo u zaglavlju (Basic), nikad u adresi.** Adresa s `consumer_key` i `consumer_secret`
  ostaje u zapisu nginxa zajedno sa secretom (dogodilo se 15. 11. 2026. s testnim ključem K_READ).
- To čuvaju: `TestHygieneTest` (pada ako ijedan test ili alat sastavlja adresu s ključem), provjera u
  `E2ECase` (takav zahtjev se ne šalje) i `ZLogE2ETest` (secret nijednog testnog ključa nije u zapisu nginxa).
- Zapise nginxa vodi CloudPanel; ne diraju se.
- Zamjena testnih ključeva: `tests/tools/rotate-test-keys.py <new|old> K_READ,K_RW` (secret se ne ispisuje).
- Scenariji H0.3 i dio H1.8 (ključ u adresi) se zato stvarnim HTTP-om više ne izvode.

## Čvrsta načela plugina (specifikacija, poglavlje 1)

1. **Neobavezan.** Bulkbird radi i bez plugina; svaka funkcija ima zamjenski put.
2. **Bez sučelja.** Nema stranice postavki, izbornika, obavijesti ni widgeta. Samo redak u popisu
   pluginova i odjeljak u WooCommerce → Status.
3. **Nula opterećenja webshopa.** Nula CSS-a i JS-a (`wp_enqueue_*` se ne koristi). Na stranicama za
   kupce nijedan upit prema bazi. Plugin radi samo na REST zahtjevima za svoje putanje i na kukama
   spremanja/brisanja proizvoda.
4. **Ništa ne briše** u trgovini (proizvodi, mediji, narudžbe). Jedino brisanje u bazi: vlastite tablice
   i vlastiti zapisi pri deinstalaciji. **Jedino brisanje datoteka u kodu plugina** (prihvatio vlasnik
   15. 11. 2026., odluka P22): u `Media_Controller::create_attachment()`, kad stvaranje privitka padne
   nakon što je datoteka već premještena, plugin poziva `wp_delete_file()` nad tom svojom netom
   spremljenom datotekom. Nijedno drugo brisanje datoteka se ne dodaje bez odobrenja vlasnika.
   Pokrivaju ga testovi H2.69 (jedinični i integracijski) i namjerni kvar H2.69.
5. **Bez osobnih podataka** u bilo kojem odgovoru (kupci, ID-evi narudžbi, adrese, e-mailovi, imena korisnika).
6. **Isti ključ.** Autentikacija WooCommerce REST ključem; dozvola ključa (read / read_write) vrijedi i za plugin.
7. **Najmanja prava.** Svaka putanja provjerava sposobnost korisnika; nijedna nema
   `permission_callback => __return_true`.

## Sigurnost (specifikacija, poglavlje 4)

- Svaki ulaz validiran i saniran kroz `args` sheme REST putanja; SQL samo kroz `$wpdb->prepare`.
- Ograničenje učestalosti po korisniku (npr. 120 zahtjeva/min, prijenos slika 30/min) → 429 s `Retry-After`.
  Brojač koji se ne može spremiti pušta zahtjev nebrojen (odluka P21, prihvatio vlasnik 15. 11. 2026.).
- Sadržaj zahtjeva se ne zapisuje u log; greške samo s kodom i kratkim opisom.
  Odgovori grešaka bez tragova stoga i bez putanja na disku.
- Opcije s `autoload = no`.
- `uninstall.php`: briše tablicu dnevnika i vlastite opcije; meta `_bulkbird_upload_id` na privicima ostaje.
- Deaktivacija: uklanja cron posao; podaci ostaju do deinstalacije.
- Provjere: PHPStan (razina 6 ili viša), PHPCS s WordPress standardom (uključujući sigurnosna pravila),
  ručni pregled svakog `permission_callback`.

## Učinkovitost (specifikacija, poglavlje 5)

- Naslovnica i stranica proizvoda za kupca: 0 dodatnih upita i 0 dodatnih učitanih datoteka
  (mjeri se prije i poslije aktivacije).
- Spremanje proizvoda: najviše 1 dodatni INSERT po događaju.
- `GET /changes` (500 stavki) i `GET /sales` (12 mjeseci) na teststore1: izmjeriti i zapisati; cilj < 500 ms.
- CI pada ako se u kodu pojavi `wp_enqueue_script`, `wp_enqueue_style` ili kuka na frontend
  (`wp_head`, `wp_footer`, `template_redirect`, `init` s upitom prema bazi).

## Poznate činjenice o okruženju (provjereno 14. 11. 2026.)

- teststore1: WordPress 7.1.4, WooCommerce 11.3.0, PHP-FPM 8.3, Percona 8.4, nginx, Cloudflare ispred.
  HPOS uključen. Izravan pristup originu mimo Cloudflarea vraća 403.
- WooCommerce 11.1+ odbija REST ključ na putanjama izvan `wc/` i `wc-` (`reject_out_of_scope_route`),
  osim kad adresa zahtjeva imenuje istu putanju koju WordPress i izvršava. Filter
  `woocommerce_rest_is_request_to_rest_api` zato mora strogo provjeriti prefiks `bulkbird/`, a
  ponašanje se testira i na WC 8.0 i na WC 11.3.
- Nepoznat consumer key WooCommerce ne javlja kao grešku: zahtjev se nastavlja kao neprijavljen.
- Varnish je za stranicu isključen; ako se uključi, VCL ne zaobilazi `/wp-json/` ni `Authorization: Basic`.
- Na serveru nema Dockera ni sudo prava; postoje PHP 8.1–8.5 CLI, Composer, Node, git.
