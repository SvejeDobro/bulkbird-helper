# Poredak pojmova i jednakost naziva (kategorije, oznake, brendovi)

17. 11. 2026. · za Bulkbird paket 3b · testna trgovina A (WordPress 7.1.4, WooCommerce 11.3.0) i testna trgovina B
(WordPress 6.4.14, WooCommerce 8.0.3); obje MySQL 8.4.11 (Percona), tema Storefront 4.6.2.
Alati: `tests/tools/probe-term-order.php` (stvara pojmove kroz WooCommerce REST, ključem Shop managera) i
`tests/probe/term-order.php` (čita bazu, `get_terms()` i kod za prikaz, kroz WP-CLI).
Svi pojmovi su se zvali `bbh-test-…` i nakon mjerenja su obrisani (testna trgovina A: 36, ID 173–208;
testna trgovina B: 25, ID 152–176; preostalo 0). Ništa drugo u trgovinama nije mijenjano.

## Pravilo u jednoj rečenici

**Podkategorije jedne razine WooCommerce reda ovako: najprije pojmovi koji uopće nemaju zapis redoslijeda,
zatim brojčano uzlazno po zapisu `order` (negativni prije nule), a unutar istog broja (i među onima bez
zapisa) po nazivu kolacijom `utf8mb4_unicode_520_ci`, tj. bez razlike velikih i malih slova i bez razlike
č/ć/c, đ/d, š/s, ž/z, sa znamenkama kao tekstom („10” prije „9”).**

To vrijedi za `get_terms()` nad `product_cat` (zadano) i za podkategorije na stranici roditelja, jednako na
WooCommerceu 8.0.3 i 11.3.0. **Ovisi o mjestu prikaza** (tablica niže): REST popis i kratki kôd
`[product_categories]` zadano redaju po nazivu, a widget kategorija pojmove bez zapisa uopće ne prikazuje.

Dvije zamke za klijenta koji to oponaša:

- **REST ne razlikuje „nema zapisa” od nule.** Polje `menu_order` je u odgovoru `0` i za pojam bez zapisa i
  za pojam sa zapisom `0`, a ta se dva redaju različito (bez zapisa ide ispred negativnih, nula iza njih).
- Pojam stvoren kroz REST **bez** `menu_order` ne dobiva nikakav zapis. Ako klijent `menu_order` šalje uvijek
  (i kad je 0), oba problema nestaju i poredak je čisto brojčan pa po nazivu.

## 1. Kolacija i jednakost naziva

`SHOW FULL COLUMNS` za `wp_terms`, obje trgovine jednako:

| Stupac | Vrsta | Kolacija |
|---|---|---|
| `name` | `varchar(200)` | `utf8mb4_unicode_520_ci` |
| `slug` | `varchar(200)` | `utf8mb4_unicode_520_ci` |

Veza prema bazi koristi istu kolaciju.

Smatra li baza nazive jednakima (`SELECT … WHERE name = …`)? **Da, sve parove:**

| Spremljeno | Traženo | Baza nalazi spremljeni redak |
|---|---|---|
| `Čaj` | `Caj` | da |
| `Čaj` | `Ćaj` | da |
| `Đak` | `Dak` | da |
| `šal` | `ŠAL` | da |
| `šal` | `sal` | da |

Odbija li WooCommerce REST stvaranje pojma čiji je naziv bazi „jednak” postojećem? Jednako za kategorije i
oznake, na obje verzije:

| Postoji | Šalje se | Odgovor |
|---|---|---|
| `Čaj` | `Caj` | **201, stvoren je novi pojam**; slug dobiva nastavak (`…-caj-2`) |
| `Đak` | `Dak` | **201, novi pojam** (`…-dak-2`) |
| `šal` | `ŠAL` | **201, novi pojam** (`…-sal-2`) |
| `Kava` | `KAVA` | **400 `term_exists`**, uz `data.resource_id` postojećeg pojma |

Dakle: razlika samo u veličini **običnih** slova (A–Z) znači isti pojam; razlika u kvačici, ili u veličini
slova s kvačicom, znači novi pojam. Razlog: WordPress nakon upita u bazu nazive uspoređuje još jednom, strože
(bajt po bajt nakon pretvaranja samo običnih slova u mala), upravo zato što kolacija ne razlikuje naglaske.
Posljedica: u trgovini mogu postojati dvije kategorije koje baza smatra jednakima po nazivu; upit po nazivu
vraća obje, a njihov međusobni poredak pri sortiranju po nazivu nije određen.

## 2. Poredak na jednoj razini

Roditelj `bbh-test-poredak`, deset podkategorija (prefiks `bbh-test-` je u tablicama izostavljen):

| Naziv | Poslani `menu_order` | Zapis u bazi (`wp_termmeta`, ključ `order`) | `menu_order` u REST odgovoru |
|---|---|---|---|
| `čaj` | 2 | `2` | 2 |
| `Ćup` | 2 | `2` | 2 |
| `cimet` | nije poslan | **nema zapisa** | 0 |
| `đon` | nije poslan | **nema zapisa** | 0 |
| `dar` | −1 | `-1` | −1 |
| `9` | 0 | `0` | 0 |
| `10` | 5 | `5` | 5 |
| `Zebra` | 1 | `1` | 1 |
| `ananas` | 3 | `3` | 3 |
| `Breskva` | 4 | `4` | 4 |

**Gdje je zapisan redoslijed:** u meta polju pojma `order` (tablica `wp_termmeta`), i za `product_cat` i za
`product_brand`. Stupac `term_order` u `wp_terms` ne postoji. Pojam stvoren kroz REST bez `menu_order` nema
taj zapis (uklanjanje nije bilo potrebno).

**Kojim redom:**

| Gdje | Poredak | Što to znači |
|---|---|---|
| `get_terms( product_cat, parent=X )` zadano | cimet, đon, dar, 9, Zebra, čaj, Ćup, ananas, Breskva, 10 | bez zapisa prvi (po nazivu), zatim −1, 0, 1, 2, 2, 3, 4, 5; isti broj po nazivu |
| isto s `menu_order => true` ili `false` | isto | za `product_cat` argument ne mijenja ništa |
| isto s `orderby => name` | 10, 9, ananas, Breskva, čaj, cimet, Ćup, dar, đon, Zebra | samo naziv, kolacijom stupca |
| isto s `orderby => name, order => DESC` | Zebra, đon, dar, Ćup, cimet, čaj, Breskva, ananas, 9, 10 | obrnuto |
| isto s `menu_order => 'DESC'` | 10, Breskva, ananas, Ćup, čaj, Zebra, 9, dar, đon, cimet | sve obrnuto; bez zapisa na kraju |
| REST `wc/v3/products/categories?parent=X` zadano | 10, 9, ananas, Breskva, čaj, cimet, Ćup, dar, đon, Zebra | **po nazivu**, ne po redoslijedu |
| podkategorije na stranici roditelja (`woocommerce_get_product_subcategories`) | cimet, đon, dar, 9, Zebra, čaj, Ćup, ananas, Breskva, 10 | kao zadani `get_terms` |
| kratki kôd `[product_categories parent=X]` zadano | 10, 9, ananas, Breskva, čaj, cimet, Ćup, dar, đon, Zebra | **po nazivu** |
| kratki kôd s `orderby="menu_order"` | cimet, đon, dar, 9, Zebra, čaj, Ćup, ananas, Breskva, 10 | kao zadani `get_terms` |
| widget kategorija, zadano („redoslijed kategorija”) | dar, 9, Zebra, čaj, Ćup, ananas, Breskva, 10 | brojčano; **cimet i đon se ne prikazuju** |
| widget kategorija, „po nazivu” | 10, 9, ananas, Breskva, čaj, cimet, Ćup, dar, đon, Zebra | po nazivu, svi |

SQL koji WooCommerce šalje za zadani poredak:
`LEFT JOIN wp_termmeta ON ( t.term_id = wp_termmeta.term_id AND wp_termmeta.meta_key='order' )` i
`ORDER BY wp_termmeta.meta_value+0 ASC, t.name ASC`. Pojam bez zapisa daje `NULL`, a MySQL `NULL` u
uzlaznom poretku stavlja na početak.

**Pojmovi bez zapisa redoslijeda:** na **početku** (ispred negativnih brojeva) u `get_terms`, na stranici
roditelja i u kratkom kodu s `orderby="menu_order"`; na **kraju** kad je poredak silazan; u widgetu
kategorija sa zadanim poretkom **ih nema**.

**Widget kategorija izostavlja pojmove bez zapisa.** Widget za „redoslijed kategorija” traži
`orderby => meta_value_num, meta_key => order`, što WordPress pretvara u `INNER JOIN` na taj zapis. Provjereno
i brojem: na testnoj trgovini B tijekom mjerenja 151 kategorija, od toga 8 sa zapisom (sve testne), widget
prikazuje 8; na testnoj trgovini A 152 kategorije, 140 sa zapisom, widget prikazuje 140. Stvarne kategorije stare
trgovine (uvezene CSV-om) nemaju zapis `order`, pa ih taj widget u zadanom načinu ne bi prikazao. Isti kod je
u obje verzije WooCommercea.

### Brendovi (`product_brand`, samo WooCommerce 11.3.0)

- Redoslijed se zapisuje jednako (meta `order`), REST ga prima i vraća kao `menu_order`.
- **Ali `product_brand` nije među taksonomijama koje WooCommerce sam reda** (popis je samo `product_cat`):
  zadani `get_terms( product_brand )` vraća **po nazivu**. Redoslijed se primjenjuje samo kad pozivatelj
  izričito traži `menu_order => true`; tada je poredak isti kao za kategorije (bez zapisa prvi).
- REST `wc/v3/products/brands` zadano: po nazivu. Kratki kôd `[product_brand_list]`: po nazivu.
- Na WooCommerceu 8.0.3 taksonomija i putanja `/brands` ne postoje.

### Razlike između verzija

Za kategorije nema nijedne: svi poretci u tablici su znak po znak isti na WooCommerceu 8.0.3 i 11.3.0.
Jedina razlika je postojanje brendova.

## Što poredak po nazivu znači s hrvatskim slovima

Iz izmjerenog poretka `10, 9, ananas, Breskva, čaj, cimet, Ćup, dar, đon, Zebra`:

- velika i mala slova se ne razlikuju (`ananas` prije `Breskva`, `Zebra` na kraju);
- `č`, `ć` i `c` vrijede kao isto slovo: `čaj` < `cimet` < `Ćup` jer odlučuje sljedeće slovo (a, i, u);
- `đ` vrijedi kao `d`: `dar` < `đon`;
- to **nije hrvatski abecedni red** (u njemu bi `č` i `ć` došli nakon svih `c`, a `đ` nakon svih `d`);
- znamenke se uspoređuju kao tekst: `10` prije `9`.

## Što nije mjereno

- Prikaz je mjeren pozivom koda koji trgovina koristi (kratki kôd, widget, funkcija za podkategorije) kroz
  WP-CLI, s isključenim skrivanjem praznih kategorija, jer su testne kategorije bez proizvoda. Stvarna
  stranica u pregledniku nije gledana (testne kategorije bi trebale proizvode, a oni se ne diraju).
- Blokovi WooCommercea („Product Categories List” i slični) i druge teme. Tema ili dodatak mogu zadati
  vlastiti poredak; Storefront ga ne mijenja.
- Kratki kodovi brendova sa sličicama (ne ispisuju naziv kao tekst) i widgeti brendova.
- Druge kolacije baze. Trgovina na starijem MySQL-u ili s `utf8mb4_general_ci` / `utf8mb4_0900_ai_ci` može
  drukčije poredati nazive sa znakovima izvan engleske abecede; pravilo „brojčano po `order`” ostaje isto.
- Međusobni poredak dvaju pojmova čiji su nazivi po kolaciji jednaki (npr. `Čaj` i `Caj`) uz isti redoslijed:
  SQL ga ne određuje.
