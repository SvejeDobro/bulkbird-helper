# Nefiltrirani HTML: tko ga smije spremati i što WooCommerce REST radi s opisom

16. 11. 2026. · teststore1 (WordPress 7.1.4, WooCommerce 11.3.0) i teststoreold (WordPress 6.4.14,
WooCommerce 8.0.3) · na obje je Bulkbird Helper `0.3.0`. Mjereno alatom
`tests/tools/probe-unfiltered-html.php` na jednoj skici proizvoda po trgovini (`bbh-test-unfiltered-1`);
ostalo je pročitano iz baze i koda. Ništa drugo u trgovinama nije mijenjano.

## Glavni nalaz

**WooCommerce REST čisti opis proizvoda svakom korisniku, i onome koji ima `unfiltered_html`.**
Administrator (kojem WordPress kaže `unfiltered_html = true`) i Shop manager (`false`) dobili su
znak po znak isti ishod, na obje verzije WooCommercea.

Uzrok je u kodu WooCommercea, bez ikakve provjere sposobnosti:

```php
$product->set_description( wp_filter_post_kses( $request['description'] ) );
$product->set_short_description( wp_filter_post_kses( $request['short_description'] ) );
```

(WooCommerce 11.3.0: `class-wc-rest-products-controller.php` redci 782 i 787; WooCommerce 8.0.3: redci 407
i 412; isto u kontroleru verzije 2.) `wp_filter_post_kses()` uvijek primjenjuje popis dopuštenog HTML-a za
objave i pritom uklanja obrnute kose crte, jer očekuje podatke kakve šalje obrazac u adminu.

Posljedica za Bulkbird: polje `user.unfiltered_html` (paket `0.4.0`) **ne predviđa** što će WooCommerce REST
napraviti s opisom. Ono govori što bi WordPress dopustio tom korisniku u uređivaču u adminu i kroz
`wp/v2`. Kroz `wc/v3/products` ishod je isti za sve.

## Što se dogodi s opisom poslanim kroz `PUT /wc/v3/products/<id>`

Odgovor je u oba slučaja 200, a polje `description` u odgovoru jednako je onome što je spremljeno u bazu
(dakle klijent promjenu može uočiti usporedbom poslanog i vraćenog).

| Poslano | Spremljeno (Shop manager i administrator, obje trgovine) |
|---|---|
| `<p>C:\temp\novi</p>` | `<p>C:tempnovi</p>` — obrnute kose crte uklonjene |
| `<p>a\\b</p>` (dvije kose crte) | `<p>a\b</p>` — ostaje jedna |
| `<iframe src="https://example.com/embed" …></iframe>` | uklonjeno u cijelosti |
| `<script>window.bbhTest = 1;</script>` | oznake uklonjene, **tekst ostaje**: `window.bbhTest = 1;` |
| `<style>.bbh-test { color: red; }</style>` | oznake uklonjene, **tekst ostaje**: `.bbh-test { color: red; }` |
| `<p onclick="bbhTest()">klik</p>` | `<p>klik</p>` — atribut uklonjen |
| `<p style="color: red;">boja</p>` | `<p style="color: red">boja</p>` — sačuvano, bez završne točke-zareza |
| `<div data-bbh="1">podatak</div>` | sačuvano |
| `<h2>Naslov</h2><p><strong>tekst</strong></p>` | sačuvano |

Obrnuta kosa crta se, dakle, uklanja **i korisniku s `unfiltered_html`**.

## Tko ima `unfiltered_html`

| | teststore1 | teststoreold |
|---|---|---|
| Multisite | ne | ne |
| `DISALLOW_UNFILTERED_HTML` u `wp-config.php` | nije definirano | nije definirano |
| Uloge s tom sposobnošću | administrator, editor | administrator, editor |
| Uloge bez nje | shop_manager, author, customer | shop_manager, author, customer |
| Administrator (1 korisnik) | `true` | `true` |
| Shop manager (testni korisnik) | `false` | `false` |
| Vlasnik ključa `bulkbirdapp2` (administrator) | `true` | — (ključ ne postoji) |
| Vlasnik testnih ključeva K_READ, K_RW, K_WRITE (Shop manager) | `false` | `false` |

Zašto: to je zadano ponašanje WordPressa i WooCommercea. WordPress sposobnost daje ulogama administrator i
editor; WooCommerce je ulozi Shop manager ne dodaje. CloudPanel ovdje ne utječe: nije dodao nijednu
konstantu `DISALLOW_*` u `wp-config.php` i trgovine nisu multisite. Na multisiteu je ima samo super
administrator, a `DISALLOW_UNFILTERED_HTML` je oduzima svima; u oba slučaja `current_user_can()` to već
uračunava, pa plugin vraća ono što WordPress kaže.

## Što nije mjereno

- Spremanje opisa kroz `wp/v2` ili uređivač u adminu (ondje `unfiltered_html` doista odlučuje); nije bilo
  u zadatku i nije potrebno za zaključak o `wc/v3`.
- Ključ administratora nije stvaran (pravilo vlasnika); administrator je mjeren prijavom kolačićem, koja
  prolazi isti kod WooCommercea.
- Vrijednost `user.unfiltered_html` iz `/info` stvarnim HTTP-om: na trgovinama je `0.3.0`, pa polja još nema.
  Iste vrijednosti su pročitane izravno iz WordPressa (tablica gore).

Skice `bbh-test-unfiltered-1` (teststore1 ID 16951, teststoreold ID 5011) su ostale u trgovinama; brisanje
proizvoda nije pokriveno trajnim odobrenjem za testne slike.
