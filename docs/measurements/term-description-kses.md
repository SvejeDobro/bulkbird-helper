# Opis pojma kroz WooCommerce REST: što se spremi i ovisi li o `unfiltered_html`

17. 11. 2026. · za Bulkbird paket 3b · teststore1 (WordPress 7.1.4, WooCommerce 11.3.0) i teststoreold
(WordPress 6.4.14, WooCommerce 8.0.3). Mjereno alatom `tests/tools/probe-term-description.php`: za svaku
taksonomiju i korisnika stvoren je pojam `bbh-test-…` (`POST`), zatim izmijenjen istim opisom (`PUT`) i
pročitan (`GET`); spremljeno je čitano ravno iz baze. Nakon mjerenja svi su testni pojmovi obrisani
(teststore1: 6, ID 167–172; teststoreold: 4, ID 148–151). Ništa drugo u trgovinama nije mijenjano.

Korisnici: **Shop manager** (ključ K_RW; `unfiltered_html = false`) i **administrator** (prijava kolačićem;
`unfiltered_html = true`). Putanje: `wc/v3/products/categories`, `/tags`, `/brands`.
`/brands` na WooCommerceu 8.0.3 ne postoji (404 `rest_no_route`); brendovi su u jezgri tek od novijih verzija.

## Glavni nalaz

**Ishod ne ovisi o `unfiltered_html`.** Shop manager i administrator dobili su znak po znak isti spremljeni
opis, u svim taksonomijama, pri stvaranju i pri izmjeni. Razlika postoji samo između verzija WordPressa, i
to u jednoj sitnici (atributi `target` i `rel` na poveznici).

Opis pojma se čisti **strože nego opis proizvoda**: ostaju samo oznake za tekst u retku
(`a`, `abbr`, `acronym`, `b`, `blockquote`, `cite`, `code`, `del`, `em`, `i`, `q`, `s`, `strike`, `strong`).
Blokovske oznake (`p`, `h2`, `ul`, `li`), slike i `iframe` se uklanjaju.

Uzrok je u WordPressu, ne u WooCommerceu: na `pre_term_description` je uvijek filter `wp_filter_kses`
(`wp-includes/default-filters.php`), za svakog korisnika. WooCommerce REST opis samo prosljeđuje WordPressu
i taj filter ne uklanja. `wp_filter_kses()` pritom uklanja i obrnute kose crte.

## Što se spremi (isto za oba korisnika i sve taksonomije)

| Poslano | Spremljeno |
|---|---|
| `<p>Odlomak teksta.</p>` | `Odlomak teksta.` — oznaka uklonjena, tekst ostaje |
| `<h2>Podnaslov</h2>` | `Podnaslov` |
| `<strong>naglašeno</strong>` | isto |
| `<a href="https://example.com/stranica?a=1&amp;b=2" title="naslov" target="_blank" rel="noopener">poveznica</a>` | **WordPress 7.1.4:** isto. **WordPress 6.4.14:** bez `target` i `rel` (ostaju `href` i `title`) |
| `<img src="…" alt="opis slike" width="10" height="10">` | uklonjeno u cijelosti |
| `<ul><li>prva</li><li>druga</li></ul>` | `prvadruga` — oznake uklonjene, stavke se spoje bez razmaka |
| `<iframe src="…"></iframe>` | uklonjeno u cijelosti |
| `<script>window.bbhTest = 1;</script>` | `window.bbhTest = 1;` — oznake uklonjene, **tekst ostaje** |
| `<style>.bbh-test { color: red; }</style>` | `.bbh-test { color: red; }` — oznake uklonjene, **tekst ostaje** |
| `<p onclick="bbhTest()">klik</p>` | `klik` |
| `C:\temp\novi i dvije: a\\b` | `C:tempnovi i dvije: a\b` — jedna kosa crta nestaje, od dvije ostaje jedna |
| `Sol & papar, entitet &amp; i &lt;znak&gt;` | `Sol &amp; papar, entitet &amp; i &lt;znak&gt;` — goli `&` postaje `&amp;`, entiteti ostaju |
| `Dvostruki "navodnici" i jednostruki 'navodnici'` | isto |
| `Čaša, žlica, šećer, đak, ćup — ČŽŠĐĆ` | isto |
| `[embed]https://example.com/video[/embed]` | isto (kratki kôd se sprema kao tekst) |

Prijelazi u novi red između dijelova su sačuvani.

## Odgovori na pitanja

1. **Što se spremi i je li razlika `unfiltered_html`?** Tablica gore. Razlike između korisnika nema, pa
   ishod nije funkcija `unfiltered_html`.
   **Isto na WooCommerceu 8.0.3 i 11.3.0?** Da, osim atributa `target` i `rel` na poveznici, a ta razlika
   dolazi od verzije WordPressa: noviji WordPress u opisu pojma dopušta `a[rel]` i `a[target]`
   (`wp_kses_allowed_html()`, kontekst `pre_term_description`), a 6.4 samo `a[href]` i `a[title]`.
2. **Vraća li odgovor ono što je spremljeno?** Da. Polje `description` u odgovoru na `POST`, na `PUT` i na
   `GET` jednako je znak po znak onome u bazi, u svih 10 izmjerenih slučajeva. Statusi su 201 i 200;
   čišćenje se ne javlja nikakvom greškom ni upozorenjem.
3. **Je li `user.unfiltered_html` dovoljan?** Pitanje otpada: pretpostavka da ishod ovisi o
   `unfiltered_html` nije potvrđena. Polje s grane `paket-0.4.0` za opis pojma ne bi reklo ništa, kao ni
   za opis proizvoda (`docs/measurements/unfiltered-html.md`).

## Prijedlog (ništa nije izvedeno)

- **U ugovor plugina ne treba ništa dodati.** Klijent ishod pouzdano vidi usporedbom poslanog opisa i
  polja `description` u odgovoru.
- Za Bulkbird stranu, pravilo koje vrijedi na obje verzije: u opis pojma slati samo tekst i oznake
  `a` (`href`, `title`), `strong`, `em`, `b`, `i`, `code`, `blockquote`, `cite`, `del`, `abbr`, `q`, `s`;
  bez odlomaka, naslova, popisa i slika; bez obrnutih kosih crta; `&` pisati kao `&amp;`.
  `target` i `rel` na poveznici ne preživljavaju na WordPressu 6.4.
- Popis dopuštenih oznaka trgovina može promijeniti (filter `wp_kses_allowed_html`), a neke teme i dodaci
  uklanjaju filter s `pre_term_description` da bi opisi kategorija primali puni HTML. Zato se klijent ne
  bi trebao oslanjati na fiksni popis, nego na usporedbu s odgovorom.
- Ako se ikad pokaže da Bulkbird mora unaprijed znati što trgovina dopušta, moguće je polje u `/info`
  s popisom oznaka koje WordPress te trgovine dopušta u opisu pojma. To bi bila aditivna izmjena ugovora
  i zasebna odluka; ne predlažem je sada.

## Što nije mjereno

- Spremanje opisa pojma kroz uređivač u adminu ili `wp/v2`. WooCommerce u adminu ima vlastito ponašanje
  za opise kategorija; ono nije put kojim Bulkbird sprema.
- Ključ administratora nije stvaran (pravilo vlasnika); administrator je mjeren prijavom kolačićem, koja
  prolazi isti kod.
- Trgovina s temom ili dodatkom koji mijenja filtre opisa pojma. Obje testne trgovine su zadane
  (Storefront; Elementor i Beaver Builder na teststore1 nisu promijenili ishod).
