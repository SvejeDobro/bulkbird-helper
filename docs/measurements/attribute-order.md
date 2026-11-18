# Poredak vrijednosti globalnih atributa (`pa_*`)

18. 11. 2026. · za Bulkbird paket 3c · teststore1 (WordPress 7.1.4, WooCommerce 11.3.0) i teststoreold
(WordPress 6.4.14, WooCommerce 8.0.3); obje MySQL 8.4.11 (Percona), tema Storefront 4.6.2.
Alati: `tests/tools/probe-attribute-order.php` (stvara atribut, vrijednosti i skicu proizvoda kroz WooCommerce
REST ključem Shop managera; mijenja postavku atributa; čita ključem samo za čitanje) i
`tests/probe/attribute-order.php` (čita bazu, `get_terms()` i kod za prikaz, kroz WP-CLI).
Prije mjerenja nijedna trgovina nije imala nijedan globalni atribut. Testni atribut `bbh-test-atribut`
(`pa_bbh-test-atribut`), njegovih osam vrijednosti i skica proizvoda `bbh-test-atribut-proizvod` nakon mjerenja
su obrisani (ID-jevi na kraju dokumenta). Ništa drugo u trgovinama nije mijenjano.

## Pravilo u jednoj rečenici

**Vrijednosti globalnog atributa trgovina reda prema postavci tog atributa („Default sort order”, u bazi
`attribute_orderby`, u REST-u `order_by`); zapis redoslijeda (meta polje pojma `order`, isto kao kod
kategorija) koristi se samo uz postavku `menu_order` („Custom ordering”), i tada vrijedi isto pravilo kao za
kategorije: vrijednosti bez zapisa prve, zatim brojčano uzlazno (negativni prije nule), isti broj po nazivu.**

| Postavka (`order_by`) | SQL koji WooCommerce šalje | WooCommerce 11.3.0 | WooCommerce 8.0.3 |
|---|---|---|---|
| `menu_order` (Custom ordering) | `LEFT JOIN wp_termmeta … meta_key='order'`, `ORDER BY meta_value+0 ASC, t.name ASC` | bez zapisa prvi, zatim po broju, isti broj po nazivu | isto, **ali vidi zamku s REST-om niže** |
| `name` (Name) | `ORDER BY t.name ASC` | po nazivu, kolacijom baze | isto |
| `name_num` (Name (numeric)) | `ORDER BY t.name+0 ASC` | po brojčanoj vrijednosti početka naziva | isto |
| `id` (Term ID) | 11.3.0: `ORDER BY t.term_id ASC`; 8.0.3: `ORDER BY t.name ASC` | po ID-ju pojma (redom stvaranja) | **po nazivu — postavka se ne provodi** |

Isti poredak daju (izmjereno uz svaku postavku, na obje trgovine): zadani `get_terms()` nad taksonomijom
atributa, `wc_get_product_terms()` (vrijednosti na proizvodu), tablica atributa na stranici proizvoda
(`wc_display_product_attributes()`) i polje `attributes[].options` proizvoda u `wc/v3/products/<id>`.
**Iznimka je REST popis vrijednosti** `wc/v3/products/attributes/<id>/terms`: on je zadano uvijek po nazivu, bez
obzira na postavku (kao i REST popis kategorija).

## 1. Testni podaci

Atribut `bbh-test-atribut`, vrsta `select`. Vrijednosti stvorene kroz REST ovim redom (prefiks `bbh-test-` je u
tablicama izostavljen):

| Naziv | Poslani `menu_order` | `menu_order` u REST odgovoru | Zapis na 11.3.0 | Zapis na 8.0.3 |
|---|---|---|---|---|
| `čaj` | 2 | 2 | `order` = `2` | `order_pa_bbh-test-atribut` = `2` |
| `Ćup` | 2 | 2 | `order` = `2` | `order_pa_bbh-test-atribut` = `2` |
| `cimet` | nije poslan | 0 | **nema zapisa** | `order_pa_bbh-test-atribut` = `NULL` |
| `đon` | nije poslan | 0 | **nema zapisa** | `order_pa_bbh-test-atribut` = `NULL` |
| `dar` | −1 | −1 | `order` = `-1` | `order_pa_bbh-test-atribut` = `-1` |
| `9` | 0 | 0 | `order` = `0` | `order_pa_bbh-test-atribut` = `0` |
| `10` | 5 | 5 | `order` = `5` | `order_pa_bbh-test-atribut` = `5` |
| `Zebra` | 1 | 1 | `order` = `1` | `order_pa_bbh-test-atribut` = `1` |

## 2. Gdje je zapis i dvojba „nema zapisa” ili 0

- **Zapis je term meta `order`** (tablica `wp_termmeta`), isti ključ kao za kategorije. Poredak trgovine čita
  samo taj ključ, na obje verzije. Povlačenje vrijednosti u administraciji (`wc_set_term_order()`) piše taj
  ključ, na obje verzije (pročitano iz koda).
- **Ista dvojba vrijedi i ovdje.** Na 11.3.0 vrijednost stvorena kroz REST bez `menu_order` nema zapis, REST za
  nju vraća `menu_order: 0`, a vrijednost sa zapisom `0` također vraća 0. Redaju se različito: bez zapisa ide
  ispred negativnih, nula iza njih.
- **Dvojba je važna samo uz postavku `menu_order`.** Uz `name`, `name_num` i `id` zapis postoji, ali se ne čita.

### Zamka: WooCommerce 8.0.3 kroz REST piše u ključ koji poredak ne čita

Na 8.0.3 REST za vrijednosti atributa čita i piše `menu_order` pod ključem **`order_<taksonomija>`**
(`order_pa_bbh-test-atribut`), a poredak trgovine čita ključ **`order`**. Posljedice, izmjerene:

- `menu_order` poslan kroz REST na 8.0.3 **ne mijenja poredak u trgovini**. Uz postavku `menu_order` sve
  vrijednosti stvorene kroz REST nemaju zapis `order`, pa su poredane po nazivu:
  `10, 9, čaj, cimet, Ćup, dar, đon, Zebra`.
- REST pritom vraća `menu_order` koji je sam zapisao (2, 2, 0, 0, −1, 0, 5, 1), pa klijent ne može iz REST-a
  vidjeti da taj broj ne vrijedi.
- REST na 8.0.3 stvara zapis pod svojim ključem i kad `menu_order` nije poslan (vrijednost `NULL`).
- Kad se isti brojevi upišu i pod ključ `order` (u mjerenju: `BBH_PREPARE=1`, ono što radi povlačenje u
  administraciji), 8.0.3 reda jednako kao 11.3.0: `cimet, đon, dar, 9, Zebra, čaj, Ćup, 10`.
- 11.3.0 čita i piše `order`. (Nadogradnja na WooCommerce 3.6 preimenovala je stare ključeve `order_pa_…` u
  `order`; REST kontroler je stari ključ nastavio koristiti.)

#### U kojoj je verziji WooCommercea REST ispravljen (utvrđeno 18. 11. 2026.)

**Prva ispravna verzija: WooCommerce 10.7.0** (već od `10.7.0-beta.1`). Posljednja neispravna: 10.6.2.

- Datoteka: `plugins/woocommerce/includes/rest-api/Controllers/Version1/class-wc-rest-product-attribute-terms-v1-controller.php`
  (u paketu: `includes/rest-api/Controllers/Version1/…`), klasa `WC_REST_Product_Attribute_Terms_V1_Controller`
  (nasljeđuju je kontroleri v2 i v3).
- Funkcije: `prepare_item_for_response()` (čitanje `menu_order`) i `update_term_meta_fields()` (pisanje).
  Do 10.6.2 obje koriste `'order_' . $this->taxonomy`, od 10.7.0 `'order'`.
- Commit: <https://github.com/woocommerce/woocommerce/commit/73fee4f9705bd1d1109bae56abfbc043c5a2a276>
  („Fix: Attribute terms REST API writes menu_order to wrong meta key”, 20. 3. 2026.), PR
  <https://github.com/woocommerce/woocommerce/pull/63390>.
- Isti commit dodaje `isset( $request['menu_order'] )`: od 10.7.0 se zapis piše samo kad je `menu_order`
  poslan. Do 10.6.2 se piše uvijek, i `NULL` kad nije poslan (izmjereno gore na 8.0.3).
- **Commit ne seli stare zapise.** Što je starija verzija kroz REST upisala pod `order_<taksonomija>`, nakon
  nadogradnje ostaje ondje: poredak to i dalje ne čita, a REST od 10.7.0 čita `order`, kojeg za takvu
  vrijednost nema.

Kako je utvrđeno: čitanjem te datoteke na oznakama izdanja u repozitoriju `woocommerce/woocommerce` na
GitHubu, bez instalacije. Grubo 8.0.3, 8.1.0, 8.5.0, 9.0.0, 9.5.0, 10.0.0, 10.5.0 (stari ključ) i 11.0.0,
11.3.0 (novi), zatim svih 45 oznaka 10.5.x–11.0.x: sve do 10.6.2 imaju stari ključ, sve od `10.7.0-beta.1`
novi. Popis commita te datoteke na GitHubu između 2021. i tog commita ne pokazuje izmjenu tih redaka.
Ponašanje je izmjereno samo na 8.0.3 i 11.3.0; za verzije između vrijedi ono što piše u kodu.

Za klijenta: na starijem WooCommerceu se redoslijed vrijednosti atributa kroz WooCommerce REST ne može ni
pouzdano pročitati ni postaviti. `GET /bulkbird/v1/terms/order` vraća ono što poredak stvarno čita (ključ
`order`), na obje verzije.

## 3. Poredak uz svaku postavku

Poredak zadanog `get_terms()`, jednak na proizvodu i na stranici proizvoda:

| Postavka | WooCommerce 11.3.0 | WooCommerce 8.0.3 (vrijednosti stvorene kroz REST, bez ključa `order`) |
|---|---|---|
| `menu_order` | cimet, đon, dar, 9, Zebra, čaj, Ćup, 10 | 10, 9, čaj, cimet, Ćup, dar, đon, Zebra (po nazivu; nakon upisa ključa `order` kao 11.3.0) |
| `name` | 10, 9, čaj, cimet, Ćup, dar, đon, Zebra | isto |
| `name_num` | čaj, Ćup, cimet, đon, dar, 9, 10, Zebra | isto |
| `id` | čaj, Ćup, cimet, đon, dar, 9, 10, Zebra | 10, 9, čaj, cimet, Ćup, dar, đon, Zebra (po nazivu) |

- **`name`**: kolacija `utf8mb4_unicode_520_ci`, kao kod kategorija (bez razlike velikih i malih slova i
  č/ć/c, đ/d; „10” prije „9”). Vidi `term-order.md`.
- **`name_num`**: `ORDER BY t.name+0`, bez drugog kriterija. MySQL iz naziva uzima broj s početka (`1 kg` je 1,
  `2.5` je 2,5, naziv bez broja na početku je 0). **Vrijednosti s istim brojem nemaju određen međusobni
  poredak.** U mjerenju na trgovinama svi nazivi počinju s `bbh-test-`, dakle svi su 0, i izašli su redom ID-ja;
  na to se ne treba oslanjati. Pravo brojčano sortiranje (`-3, 1 kg, 2.5, 9, 10`) potvrđuje integracijski test
  u CI-ju na WooCommerceu 8.0.3 i na najnovijem (`AttributeTermsIntegrationTest::test_h2_102_name_numeric`),
  jer u trgovinama ne stvaram pojmove bez prefiksa `bbh-test-`.
- **`id`**: 11.3.0 reda po ID-ju pojma. 8.0.3 tu postavku sprema i vraća, ali je ne provodi: u kodu koji
  postavlja zadani poredak nema slučaja `id`, pa ostaje zadani poredak WordPressa, po nazivu.

## 4. Može li se postavka pročitati kroz REST

**Da.** `GET wc/v3/products/attributes` (popis) i `GET wc/v3/products/attributes/<id>` vraćaju polje `order_by`
s vrijednostima `menu_order`, `name`, `name_num`, `id`, na obje verzije, i ključem **samo za čitanje** (K_READ,
Shop manager). Nakon svake promjene postavke REST je vratio novu vrijednost, jednaku stupcu
`attribute_orderby` u bazi i onome što vraća `wc_attribute_orderby()`.

Zato **oblik odgovora `GET /terms/order` nije mijenjan**: postavka se ne dodaje u odgovor. Što klijent mora
znati uz nju:

- postavka `id` na 8.0.3 znači „po nazivu”;
- poredak iz `GET /terms/order` je uvijek onaj koji trgovina stvarno daje, pa ga klijent može koristiti i bez
  tumačenja postavke.

Ako se ipak želi sve u jednom zahtjevu, prijedlog (nije izveden, mijenja ugovor): zaglavlje odgovora
`X-Bulkbird-Order-By` s vrijednošću postavke, samo za taksonomije atributa. Zaglavlje ne mijenja tijelo
odgovora, pa postojeći klijenti ostaju ispravni.

## 5. Obrisano nakon mjerenja

Ispis skripte `tests/tools/cleanup-test-attributes.php`:

```
[teststore1.bulkbird.app] testnih skica proizvoda za brisanje: 1; ID: 17068
[teststore1.bulkbird.app] testni atribut za brisanje: ID 1 (pa_bbh-test-atribut); vrijednosti: 8; ID: 228, 229, 230, 231, 232, 233, 234, 235
[teststore1.bulkbird.app] obrisano: skica 1, vrijednosti 8, atributa 1; nije uspjelo: 0
[teststoreold.bulkbird.app] testnih skica proizvoda za brisanje: 1; ID: 5033
[teststoreold.bulkbird.app] testni atribut za brisanje: ID 1 (pa_bbh-test-atribut); vrijednosti: 8; ID: 177, 178, 179, 180, 181, 182, 183, 184
[teststoreold.bulkbird.app] obrisano: skica 1, vrijednosti 8, atributa 1; nije uspjelo: 0
```

Nakon brisanja na obje trgovine: 0 globalnih atributa, 0 pojmova `pa_…`, 0 pojmova i 0 proizvoda `bbh-test-`,
0 zapisa `order_pa_…`, 0 meta zapisa bez pojma (stanje kao prije mjerenja).
