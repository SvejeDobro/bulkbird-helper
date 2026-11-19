# Bulkbird Helper — ugovor API-ja v1

Status: **faza 0 — odobreno** (razlike prema specifikaciji odobrio vlasnik projekta 15. 11. 2026.).
Faza 0 (`/info`, autentikacija) je potvrđena stvarnim HTTP zahtjevima na obje testne stranice: WordPress 6.4.14 /
WooCommerce 8.0.3 / PHP 8.1 i WordPress 7.1.4 / WooCommerce 11.3.0 / PHP 8.3.
**Poglavlja 6–8 (`/media`, ograničenje učestalosti) — konačno, verzija `0.2.0`** (odluke vlasnika 15. 11. 2026.); potvrđena
stvarnim HTTP zahtjevima na obje testne stranice.
**Poglavlje 9 (`GET /media/uploads/<client_upload_id>`) — konačno, verzija `0.3.0`** (odobrio vlasnik 15. 11. 2026.).
**Poglavlje 10 (`GET /terms/order`) — konačno, verzija `0.4.0`** (odobrio vlasnik 17. 11. 2026.).
**Poglavlje 10.1 (taksonomije globalnih atributa u `GET /terms/order`) — konačno, verzija `0.5.0`** (odobrio vlasnik 18. 11. 2026.).
**Verzija `0.5.1` (19. 11. 2026.) dodaje samo tekst licence (`license.txt`): ugovor je nepromijenjen od `0.5.0`**; razlikuje se
jedino `plugin_version` u `/info`.
Jedini izvor istine za Bulkbird stranu. Mijenja se samo uz odobrenje vlasnika projekta.

- REST namespace: `bulkbird/v1` → adrese `https://<trgovina>/wp-json/bulkbird/v1/…`
- `api_version`: `1`
- Verzija plugina: `0.4.0` (faza 0: `/info`; faze 1 i 2: `/media`; `0.3.0`: stanje prijenosa; `0.4.0`: redoslijed pojmova)
- **Ugovor je isti od `0.2.0`.** `0.2.1` mijenja samo redoslijed provjere: zahtjev bez prijave ili korisnika
  bez prava dobiva 401 odnosno 403 i kad mu je neki parametar neispravan (u `0.2.0` je tada dobivao 400).
  Kodovi i statusi se ne mijenjaju; tko smije, za neispravan parametar i dalje dobiva 400.

## 1. Otkrivanje

1. `GET /wp-json/` (bez prijave): ako popis `namespaces` sadrži `bulkbird/v1`, plugin je aktivan.
   Indeks ne otkriva verziju plugina.
2. `GET /wp-json/bulkbird/v1/info` (s ključem): verzija, `api_version`, `features`.

Plugin se ne javlja (nema namespacea) kad: nije aktivan, WooCommerce nije aktivan ili je stariji od 8.0,
ili je WordPress multisite. Bulkbird tada koristi zamjenski put, bez greške.

## 2. Autentikacija

Isti WooCommerce REST ključ koji Bulkbird već koristi, na oba načina koja podržava WooCommerce:

- zaglavlje `Authorization: Basic base64(consumer_key:consumer_secret)` (preporučeno),
- parametri `consumer_key` i `consumer_secret` u adresi.

Samo preko HTTPS-a. Dozvola ključa vrijedi i ovdje: `GET`/`HEAD` traže `read` ili `read_write`,
`POST`/`PUT`/`PATCH`/`DELETE` traže `write` ili `read_write`. Uz to svaka putanja traži sposobnost korisnika
kojem ključ pripada (za `/info`: `manage_woocommerce`, dakle Shop manager ili administrator).

Podržan je i oblik adrese `https://<trgovina>/?rest_route=/bulkbird/v1/info` (trgovine bez lijepih poveznica);
potvrđeno na WooCommerceu 8.0.3.

Prijava kolačićem (WP admin u pregledniku) radi uz zaglavlje `X-WP-Nonce`, kao i za ostali WordPress REST.

## 3. Greške

WordPress oblik, uvijek točno ova tri polja:

```json
{ "code": "bulkbird_unauthorized", "message": "Authentication is required.", "data": { "status": 401 } }
```

| Slučaj | HTTP | `code` | Tko javlja |
|---|---|---|---|
| Bez ključa i bez prijave | 401 | `bulkbird_unauthorized` | plugin |
| Ključ koji ne postoji | 401 | `bulkbird_unauthorized` | plugin (WooCommerce nepoznat ključ ne javlja kao grešku) |
| Postojeći ključ, pogrešan secret | 401 | `woocommerce_rest_authentication_error` | WooCommerce |
| Ključ bez dozvole za metodu (npr. `write` ključ na `GET`, `read` ključ na `POST`) | 401 | `woocommerce_rest_authentication_error` | WooCommerce |
| Valjan ključ, korisnik bez potrebne sposobnosti | 403 | `bulkbird_forbidden` | plugin |
| Nepostojeća putanja ili metoda | 404 | `rest_no_route` | WordPress |
| Neispravan parametar (npr. `per_page=101`, `client_upload_id` koji nije UUID) | 400 | `rest_invalid_param` | WordPress |
| Previše zahtjeva (poglavlje 7) | 429 | `bulkbird_rate_limited` | plugin; zaglavlje `Retry-After` |

`message` je namijenjen čovjeku i može se prevesti; Bulkbird se oslanja samo na HTTP status i `code`.
Odgovori grešaka ne sadrže putanje na disku ni trag stoga.

## 4. Zaglavlja odgovora

Svi odgovori putanja `bulkbird/v1` (i greške) nose `Cache-Control: no-store, private, max-age=0` i ne
postavljaju kolačiće. Posrednik ispred trgovine može to zaglavlje prepisati (npr. CloudPanelov Varnish
šalje `no-store, no-cache, must-revalidate, max-age=0`), ali odgovor ne smije doći iz cachea.

## 5. `GET /bulkbird/v1/info`

Sposobnost: `manage_woocommerce`. Bez parametara; nepoznati parametri se ignoriraju. `HEAD` vraća 200 bez tijela.
Ne upisuje ništa u bazu.

| Polje | Vrsta | Značenje |
|---|---|---|
| `plugin_version` | tekst | verzija plugina (SemVer), npr. `0.1.0` |
| `api_version` | cijeli broj | `1`; mijenja se samo pri nekompatibilnoj promjeni |
| `features` | popis teksta | uključene funkcije. `0.1.0`: prazan popis. `0.2.0`: `media.read`, `media.upload`. `0.3.0`: i `media.upload_status`. `0.4.0`: i `terms.order`. `0.5.0`: i `terms.order.attributes`. Slijede: `changes` (faza 3), `sales` (faza 4) |
| `wordpress_version` | tekst | npr. `7.1.4` |
| `woocommerce_version` | tekst | npr. `11.3.0` |
| `php_version` | tekst | `glavna.sporedna.izdanje`, bez dodataka distribucije, npr. `8.3.36` |
| `hpos_enabled` | logička | narudžbe su u WooCommerce tablicama (HPOS) |
| `timezone` | tekst | vremenska zona trgovine: ime (`Europe/Zagreb`) ili pomak (`+00:00`) |
| `currency` | tekst | ISO oznaka valute trgovine, npr. `EUR` |
| `max_upload_bytes` | cijeli broj | najveća veličina prijenosa koju WordPress dopušta, u bajtovima |
| `memory_limit` | cijeli broj | PHP `memory_limit` u bajtovima; `-1` = bez ograničenja |
| `max_execution_time` | cijeli broj | PHP `max_execution_time` u sekundama; `0` = bez ograničenja |
| `detected_plugins` | popis objekata `{slug, version}` | **samo** aktivni pluginovi s popisa važnih Bulkbirdu (page builderi, SEO, višejezičnost, cache, Omnibus), poredani po `slug`. Ostali pluginovi trgovine se ne navode. `version` može biti prazan tekst |
| `analytics_lookup` | tekst | stanje WooCommerce Analytics lookup tablica: `complete`, `importing` ili `unknown` (WooCommerce ne nudi podatak) |
| `media` | objekt | od `0.2.0`: `upload_mime_types` (popis vrsta koje `POST /media` prima **na ovoj trgovini**) i `upload_max_bytes` (najveća datoteka koju `POST /media` prima = manje od `max_upload_bytes` i 20 MB) |

Bulkbird mora ignorirati polja koja ne poznaje (nova se mogu dodati bez promjene `api_version`).

Popis slugova koje `detected_plugins` može vratiti je u `includes/class-environment.php` (`RELEVANT_PLUGINS`).

Odgovor nikad ne sadrži: putanje na disku, podatke baze, ključeve, salt, korisnike, e-mailove, ništa iz `wp-config.php`.

Primjer (testna trgovina A; vrijednosti `memory_limit` i `max_execution_time` ovise o poslužitelju):

```json
{
    "plugin_version": "0.3.0",
    "api_version": 1,
    "features": ["media.read", "media.upload", "media.upload_status"],
    "wordpress_version": "7.1.4",
    "woocommerce_version": "11.3.0",
    "php_version": "8.3.36",
    "hpos_enabled": true,
    "timezone": "+00:00",
    "currency": "EUR",
    "max_upload_bytes": 67108864,
    "memory_limit": 536870912,
    "max_execution_time": 60,
    "detected_plugins": [],
    "analytics_lookup": "complete",
    "media": {
        "upload_mime_types": ["image/jpeg", "image/png", "image/webp", "image/gif", "image/avif"],
        "upload_max_bytes": 20971520
    }
}
```

Primjeri stvarnih odgovora za Bulkbird testove: `fixtures/v1/info.json` (testna trgovina A, najnovije verzije;
isti sadržaj kao `fixtures/v1/info.new.json`) i `fixtures/v1/info.old.json` (stara testna stranica).

Primjeri za `/media` (testna trgovina A; svaki sadrži `status`, važna zaglavlja i `body`): `media-list.new.json`,
`media-list-empty.new.json`, `media-item-created.new.json` (201), `media-item-existing.new.json` (200) te
greške `media-error-*.new.json` (401, 401 za ključ samo za čitanje, 403, 400, 409, 415).

## 6. `GET /bulkbird/v1/media` (od `0.2.0`; `features` sadrži `media.read`)

Sposobnosti: `upload_files` **i** `manage_woocommerce` (obje). Ne mijenja ništa u trgovini.

Vraća samo slike vrsta `image/jpeg`, `image/png`, `image/webp`, `image/gif`, `image/avif`, iz cijele
biblioteke (i slike vezane uz skice i slike bez roditelja). Slike u smeću se ne vraćaju.

| Parametar | Vrsta | Zadano | Značenje |
|---|---|---|---|
| `page` | cijeli broj ≥ 1 | 1 | stranica. Stranica iza zadnje vraća 200 i prazan popis |
| `per_page` | cijeli broj 1–100 | 50 | stavki po stranici |
| `search` | tekst, do 200 znakova | — | dio naslova **ili imena datoteke** (bez mape), bez razlike velikih i malih slova. Prazan tekst se ignorira |
| `modified_after` | ISO 8601 (`2026-11-15T12:00:00`) | — | samo slike izmijenjene **strogo nakon** tog trenutka. Bez oznake zone = GMT; `Z` ili `+01:00` se preračunava |
| `orderby` | `date` ili `title` | `date` | `date`: najnovije prvo (pri istom datumu veći `id` prvi). `title`: uzlazno po naslovu (pri istom naslovu manji `id` prvi) |

Neispravan parametar: 400 `rest_invalid_param`. Nepoznati parametri se ignoriraju.

Zaglavlja odgovora: `X-WP-Total` (ukupan broj slika koje odgovaraju upitu), `X-WP-TotalPages`.
`HEAD` vraća ista zaglavlja bez tijela.

Odgovor je popis stavki. **Stavka:**

| Polje | Vrsta | Značenje |
|---|---|---|
| `id` | cijeli broj | ID privitka; koristi se u WooCommerce REST-u (`images: [{id}]`, `image: {id}`) |
| `title` | tekst | naslov, točno kako je spremljen (čisti tekst, nije HTML) |
| `alt` | tekst | alternativni tekst; prazan tekst ako ga nema |
| `filename` | tekst | samo ime datoteke, bez mape |
| `mime` | tekst | vrsta po sadržaju, npr. `image/jpeg` |
| `width`, `height` | cijeli broj ili `null` | dimenzije u pikselima; `null` ako WordPress nema podatak |
| `filesize` | cijeli broj ili `null` | veličina datoteke u bajtovima; `null` ako WordPress nema podatak |
| `date_gmt`, `modified_gmt` | tekst | `YYYY-MM-DDTHH:MM:SS`, GMT, bez oznake zone |
| `parent_id` | cijeli broj | zapis uz koji je slika vezana; `0` ako nije vezana |
| `urls` | objekt | `thumbnail`, `medium`, `full`. Ako neka veličina ne postoji, vraća se adresa najbliže postojeće veličine, u krajnjem slučaju `full`; nikad prazno |

Stavka ne sadrži autora ni putanje na disku.

Ograničenje: pretraga po dijelu teksta ne koristi indeks baze, pa na vrlo velikim bibliotekama (desetci
tisuća slika) može biti sporija.

Ograničenje `modified_after` (provjereno 15. 11. 2026.): `modified_gmt` se mijenja kad se slika uredi kroz
WordPress (medijska biblioteka u adminu, `wp/v2/media`), pa i kad se promijeni samo alternativni tekst.
**Ne mijenja se** kad netko alternativni tekst upiše izravno u meta polje, što radi i WooCommerce REST pri
spremanju slika proizvoda (`images: [{id, alt}]`). Takvu promjenu `modified_after` ne vidi; plugin namjerno
ne dodaje kuke koje bi mijenjale datume tuđih zapisa. Promjene koje je napravio sam Bulkbird su mu poznate.

## 7. `POST /bulkbird/v1/media` (od `0.2.0`; `features` sadrži `media.upload`)

Sposobnosti: `upload_files` **i** `manage_woocommerce`; ključ mora imati dozvolu `write` ili `read_write`.

Zahtjev: `multipart/form-data`.

| Polje | Obavezno | Značenje |
|---|---|---|
| `file` | da | točno jedna datoteka |
| `client_upload_id` | da | UUID koji generira Bulkbird (bilo koja inačica; velika i mala slova su ista vrijednost) |
| `title` | ne | do 255 znakova; sprema se kao čisti tekst. Ako ga nema: ime datoteke bez nastavka |
| `alt` | ne | do 1000 znakova; sprema se kao čisti tekst |

Što plugin radi: provjerava vrstu **po sadržaju** (ime i nastavak ne odlučuju), sprema datoteku pod saniranim
imenom i s nastavkom koji odgovara sadržaju, stvara privitak i uobičajene umanjene veličine. Sliku **ne
dodjeljuje** nijednom proizvodu (`parent_id` = 0); dodjelu radi Bulkbird kroz WooCommerce REST.

Što trgovina prima piše u `/info` → `media.upload_mime_types` i `media.upload_max_bytes`. SVG se ne prima nikad.

**Odgovor:** stavka kao u poglavlju 6.

| Status | Značenje |
|---|---|
| **201** | slika je spremljena |
| **200** | slika s tim `client_upload_id` već postoji i sadržaj je isti: vraća se postojeća stavka, **nepromijenjena** (ponavljanje ne uređuje `title` ni `alt`) |

**Ponavljanje je sigurno.** Nakon prekida veze Bulkbird ponavlja isti zahtjev (isti `client_upload_id`, ista
datoteka) koliko god puta treba; u biblioteci nastaje najviše jedna slika. Uz privitak se spremaju meta polja
`_bulkbird_upload_id` i `_bulkbird_upload_sha256`.

Greške (WordPress oblik, poglavlje 3):

| Slučaj | HTTP | `code` |
|---|---|---|
| nema polja `file`, ili tijelo nije `multipart/form-data` | 400 | `bulkbird_missing_file` |
| više od jedne datoteke | 400 | `bulkbird_single_file_only` |
| nema `client_upload_id` | 400 | `rest_missing_callback_param` |
| `client_upload_id` nije UUID; predug `title` ili `alt` | 400 | `rest_invalid_param` |
| datoteka je stigla nepotpuna | 400 | `bulkbird_upload_incomplete` |
| prazna ili nečitljiva slika | 400 | `bulkbird_invalid_image` |
| slika s više od 64 megapiksela | 400 | `bulkbird_image_too_large` |
| isti `client_upload_id`, **druga datoteka** | 409 | `bulkbird_upload_id_conflict` (postojeća slika ostaje nepromijenjena) |
| slika s tim `client_upload_id` je u smeću | 409 | `bulkbird_upload_trashed` |
| drugi zahtjev s istim `client_upload_id` još traje (dulje od 10 s) | 409 | `bulkbird_upload_in_progress`; zaglavlje `Retry-After: 5` |
| datoteka veća od `media.upload_max_bytes` | 413 | `bulkbird_file_too_large` |
| vrsta koju trgovina ne prima (i svaki SVG, PDF, PHP…) | 415 | `bulkbird_unsupported_type` |
| datoteka se nije mogla spremiti (disk, dozvole) | 500 | `bulkbird_upload_failed`; poruka bez putanje i bez pojedinosti |

Dva istovremena zahtjeva s istim `client_upload_id`: jedan dobiva 201, drugi pričeka i dobiva 200 s istom stavkom.

**Zahtjev koji odbije poslužitelj ispred WordPressa** (nginx, Cloudflare, ograničenje veličine tijela) vraća
413 **bez JSON tijela plugina**. Bulkbird mora podnijeti 413 i kad odgovor nije JSON.

**Kad spremanje ne uspije (500), u trgovini ne ostaje ništa polovično.** Neupisivu mapu za prijenose plugin
prepozna prije premještanja datoteke. Ako WordPress odbije stvoriti privitak nakon što je datoteka već
premještena, plugin uklanja tu svoju netom spremljenu datoteku (jedino brisanje datoteka u pluginu; ništa
postojeće se ne dira). Isti `client_upload_id` se nakon 500 smije ponoviti.

Slika koja je trajno obrisana u adminu više ne postoji: isti `client_upload_id` tada stvara novu sliku (201).
Ista datoteka s **novim** `client_upload_id` namjerno stvara novu sliku; plugin ne sprječava duplikate po sadržaju.

## 8. Ograničenje učestalosti (od `0.2.0`)

Vrijedi za sve putanje `bulkbird/v1`, **po korisniku** kojem pripada ključ (svi ključevi istog korisnika dijele
brojač), u fiksnom prozoru od 60 sekundi:

- 120 zahtjeva u minuti (svi zahtjevi, i prijenosi);
- 30 prijenosa (`POST /media`) u minuti; broje se i neuspjeli prijenosi.

Kad je ograničenje prijeđeno: **429** `bulkbird_rate_limited`, zaglavlje `Retry-After` (sekunde do kraja
prozora) i isti broj u `data.retry_after`. Nakon toga zahtjevi opet prolaze.

Primjer odgovora: `fixtures/v1/error-429.json` (broj sekundi se mijenja).

**Brojač koji se ne može spremiti pušta zahtjev (odluka vlasnika, 15. 11. 2026.).** Ako upis brojača u bazu ili
object cache ne uspije, zahtjev prolazi nebrojen: kvar brojača ne smije srušiti sinkronizaciju. Ograničenje je
zaštita trgovine od pretjeranog prometa, ne sigurnosna granica; provjere ključa i prava vrijede uvijek.

Zahtjevi bez prijave (401) i zahtjevi korisnika bez prava (403) se ne broje. Trgovina može promijeniti
ograničenja filterom `bulkbird_helper_rate_limits`, pa se Bulkbird oslanja na `Retry-After`, ne na brojke.

## 9. `GET /bulkbird/v1/media/uploads/<client_upload_id>` (od `0.3.0`; `features` sadrži `media.upload_status`)

Provjera ishoda prijenosa čitanjem, bez ponovnog slanja datoteke. Namjena: veza se prekinula prije
odgovora na `POST /media`, klijent se srušio usred niza prijenosa ili je odgovor bio nejasan (413 bez
JSON-a, 502, 504, istek vremena).

- Sposobnosti kao `GET /media`: `upload_files` **i** `manage_woocommerce`. Radi i s ključem **samo za čitanje**.
- Ne mijenja ništa u trgovini i ne čeka prijenos koji traje.
- `<client_upload_id>` je UUID, bez razlike velikih i malih slova (isto pravilo kao u `POST /media`).
  U odgovoru je uvijek malim slovima.
- Vidi samo slike prenesene kroz `POST /media` (one imaju `client_upload_id`).
- **Ograničenje učestalosti:** broji se u 120 zahtjeva u minuti, **ne broji se u 30 prijenosa**.
- Otkrivanje: prije poziva pogledati `features` u `/info`. Na trgovini s pluginom starijim od `0.3.0`
  putanja ne postoji i WordPress vraća 404 `rest_no_route`.

Odgovor 200:

| Polje | Vrsta | Značenje |
|---|---|---|
| `client_upload_id` | tekst | traženi ID, malim slovima |
| `state` | tekst | `stored`, `trashed` ili `in_progress` |
| `sha256` | tekst ili `null` | SHA-256 spremljene datoteke (64 heksadecimalna znaka, mala slova). `null` uz `in_progress` i ako sažetak nije zabilježen |
| `item` | objekt ili `null` | uz `stored`: stavka, ista kao u `GET /media` i u odgovoru na `POST /media`. Inače `null` |

| `state` | Značenje | Što klijent radi |
|---|---|---|
| `stored` | slika s tim `client_upload_id` postoji | usporedi `sha256` sa svojom datotekom. Jednak: prijenos je uspio, koristi `item.id`. Različit: taj je ID već upotrijebljen za drugu datoteku (`POST` bi vratio 409 `bulkbird_upload_id_conflict`); treba novi `client_upload_id` |
| `trashed` | slika postoji, ali je u smeću | `POST` s tim ID-jem bi vratio 409 `bulkbird_upload_trashed`. Čitanje se ne odbija: odgovor je 200 |
| `in_progress` | zahtjev `POST` s tim ID-jem upravo traje | pričekati i ponoviti provjeru; odgovor nosi zaglavlje `Retry-After: 5` |

`in_progress` ima prednost pred ostalim stanjima: privitak nastaje prije nego što su umanjene slike gotove,
pa dok zahtjev traje odgovor nije `stored`.

| Slučaj | HTTP | `code` |
|---|---|---|
| trgovina nema sliku s tim `client_upload_id` (nikad spremljena ili trajno obrisana) | 404 | `bulkbird_upload_not_found` |
| `client_upload_id` nije UUID | 400 | `rest_invalid_param` |
| bez prijave / bez prava | 401 / 403 | kao u poglavlju 3 (imaju prednost pred 400) |
| previše zahtjeva | 429 | `bulkbird_rate_limited` |
| druga metoda (`POST`, `PUT`, `PATCH`, `DELETE`) | 404 | `rest_no_route` |

404 `bulkbird_upload_not_found` znači da će `POST /media` s tim `client_upload_id` stvoriti sliku.
Klijent razlikuje dva moguća 404 po polju `code`.

Primjeri odgovora u `fixtures/v1/`: `media-upload-status-stored.json`, `-trashed.json`, `-not-found.json`,
`-invalid-id.json`, `-unauthorized.json`, `-forbidden.json` su stvarni odgovori snimljeni 15. 11. 2026. na
testna trgovina A s `0.3.0`. `-in-progress.json` je sastavljen ručno (stanje traje samo dok traje zahtjev `POST`), a
njegov oblik potvrđuje integracijski test.

Nije dio `0.3.0` (odluke vlasnika 15. 11. 2026.): skupna provjera više ID-jeva odjednom i izmjena naslova ili
alternativnog teksta (`PATCH /media/<id>`); opisane su samo u `docs/proposals/0.3.0-client-upload-id.md`.

## 10. `GET /bulkbird/v1/terms/order` (`0.4.0`; `features` sadrži `terms.order`)

Zapis redoslijeda pojmova, onako kako ga trgovina ima. Namjena: WooCommerce REST u polju `menu_order` vraća
`0` i za pojam koji **nema** zapis redoslijeda i za pojam sa zapisom `0`, a ta se dva redaju različito i pojam
bez zapisa se ne prikazuje u widgetu kategorija (`docs/measurements/term-order.md`). Ova putanja ih razlikuje.

- Sposobnost: `manage_woocommerce` (kao `/info`). Radi i s ključem **samo za čitanje**.
- Ne mijenja ništa u trgovini.
- Ograničenje učestalosti: broji se u 120 zahtjeva u minuti.

| Parametar | Vrsta | Zadano | Značenje |
|---|---|---|---|
| `taxonomy` | `product_cat` ili `product_brand` | — (obavezan) | taksonomija |
| `page` | cijeli broj ≥ 1 | 1 | stranica. Stranica iza zadnje vraća 200 i prazan popis |
| `per_page` | cijeli broj 1–1000 | 100 | stavki po stranici |

Odgovor 200 je popis **svih** pojmova te taksonomije (i praznih, i svih razina). **Stavka ima točno tri polja:**

| Polje | Vrsta | Značenje |
|---|---|---|
| `id` | cijeli broj | ID pojma (isti kao u `wc/v3/products/categories` odnosno `brands`) |
| `parent` | cijeli broj | ID roditelja; `0` za pojam na vrhu |
| `order` | cijeli broj ili `null` | zapis redoslijeda (meta polje pojma `order`). **`null` znači da zapisa nema.** Zapis koji nije broj vraća se kao `0`, jer ga tako reda i trgovina |

Naziv, opis, slug i broj proizvoda se ne vraćaju; za njih postoji WooCommerce REST.

**Redoslijed stavki u odgovoru** je onaj kojim trgovina sama vraća pojmove te taksonomije (zadani
`get_terms`), preko svih razina zajedno:

- `product_cat`: poredak trgovine — pojmovi bez zapisa prvi, zatim brojčano uzlazno po `order`, pri istom
  broju po nazivu (kolacijom baze). Redoslijed unutar jedne razine klijent dobiva tako da iz odgovora izdvoji
  stavke s istim `parent`, ne mijenjajući im međusobni poredak;
- `product_brand`: po nazivu (WooCommerce brendove sam ne reda po `order`).

Zaglavlja odgovora: `X-WP-Total` (ukupan broj pojmova), `X-WP-TotalPages`. Stranice se režu iz jednog,
cjelovitog poretka.

| Slučaj | HTTP | `code` |
|---|---|---|
| `taxonomy` nije `product_cat` ni `product_brand`, ili nedostaje | 400 | `rest_invalid_param` (odnosno `rest_missing_callback_param` kad nedostaje) |
| `product_brand` na trgovini čiji WooCommerce nema brendove | 404 | `bulkbird_taxonomy_not_found` |
| neispravan `page` ili `per_page` (npr. `per_page=1001`) | 400 | `rest_invalid_param` |
| bez prijave / bez prava | 401 / 403 | kao u poglavlju 3 (imaju prednost pred 400) |
| previše zahtjeva | 429 | `bulkbird_rate_limited` |

Primjeri odgovora u `fixtures/v1/`:

- snimljeni stvarnim HTTP-om s verzije `0.4.0` (17. 11. 2026., ključ samo za čitanje; polje `request` kaže koji je
  zahtjev poslan): `terms-order-product-cat.new.json` i `terms-order-product-cat.old.json` (prvih 10 pojmova),
  `terms-order-product-brand.new.json`, `terms-order-product-brand.old.json` (404 na WooCommerceu bez brendova),
  `terms-order-invalid-taxonomy.new.json` i `terms-order-missing-taxonomy.new.json` (400);
- sastavljeni ručno: `terms-order.json` (svi slučajevi vrijednosti `order` na jednom mjestu: bez zapisa, negativan,
  `0`, isti broj) i `terms-order-taxonomy-not-found.json`. Oblik im je jednak snimljenima (404 je jednak znak po
  znak) i potvrđuje ga integracijski test.

### 10.1 Vrijednosti globalnih atributa (`0.5.0`; `features` sadrži `terms.order.attributes`)

Aditivna izmjena: `taxonomy` smije biti i taksonomija globalnog atributa trgovine (`pa_…`). Sve ostalo iz
poglavlja 10 vrijedi nepromijenjeno (sposobnost, stranice, zaglavlja, ograničenje učestalosti, samo čitanje).
`api_version` ostaje `1`. Klijent prije poziva provjerava značajku `terms.order.attributes`; plugin `0.4.0` za
`pa_…` vraća 400.

- **Koje taksonomije:** točno one koje WooCommerce navodi kao globalne atribute
  (`wc_get_attribute_taxonomy_names()`), tj. `pa_` + `slug` atributa iz `wc/v3/products/attributes` (polje `slug`
  ondje već sadrži prefiks `pa_`). Taksonomija s prefiksom `pa_` koja nije atribut WooCommercea, nepostojeća
  taksonomija i sve druge taksonomije i dalje vraćaju **400** `rest_invalid_param`.
- **Oblik odgovora je isti:** popis stavki s točno tri polja `id`, `parent`, `order`. `id` je ID vrijednosti (isti
  kao u `wc/v3/products/attributes/<id>/terms`); `parent` je uvijek `0`; `order` je zapis redoslijeda (meta
  polje pojma `order`) ili `null` kad zapisa nema.
- **Redoslijed stavki** je onaj kojim trgovina prikazuje vrijednosti tog atributa (zadani `get_terms`; isti je
  na proizvodu i na stranici proizvoda). Određuje ga postavka atributa „Default sort order”, koju klijent čita
  iz WooCommerce REST-a (`wc/v3/products/attributes`, polje `order_by`; radi i ključem samo za čitanje):

| `order_by` | Redoslijed |
|---|---|
| `menu_order` | kao kategorije: bez zapisa prvi, zatim brojčano uzlazno po `order`, isti broj po nazivu |
| `name` | po nazivu (kolacijom baze) |
| `name_num` | po brojčanoj vrijednosti početka naziva; vrijednosti s istim brojem nemaju određen međusobni poredak |
| `id` | po ID-ju vrijednosti. **WooCommerce 8.0.3 ovu postavku ne provodi: redoslijed je po nazivu** |

- `order` se vraća uz svaku postavku, ali ga trgovina koristi samo uz `menu_order`.
- **WooCommerce stariji od 10.7.0** (izmjereno na 8.0.3; granica utvrđena čitanjem koda WooCommercea, ispravak je
  PR woocommerce/woocommerce#63390): `menu_order` koji je za vrijednost atributa poslan kroz WooCommerce REST
  zapisuje se pod drugi ključ (`order_<taksonomija>`), koji poredak trgovine ne čita. Za takvu vrijednost ova
  putanja vraća `order: null` (to je ono što trgovina vidi), iako WooCommerce REST za nju vraća poslani
  `menu_order`. Mjerenje: `docs/measurements/attribute-order.md`.
- **Napomena za klijenta:** verziju WooCommercea čitaj iz `GET /bulkbird/v1/info` (`woocommerce_version`).
  - Na WooCommerceu **10.7.0 ili novijem** `menu_order` vrijednosti atributa poslan kroz
    `wc/v3/products/attributes/<id>/terms` mijenja poredak u trgovini (uz postavku `menu_order`).
  - Na **starijem od 10.7.0** ga ne mijenja, a WooCommerce REST svejedno vraća poslani broj. Ondje redoslijed
    vrijednosti atributa kroz WooCommerce REST nije moguće ni postaviti ni pouzdano pročitati; `order` iz ove
    putanje je jedini pouzdan podatak, a postaviti ga može samo trgovac u administraciji.
  - Nadogradnja WooCommercea ne seli stare zapise: vrijednost upisana kroz REST prije 10.7.0 i nakon nadogradnje
    ovdje ima `order: null`, dok se `menu_order` ne pošalje ponovno.
- **Tijelo greške 400 za nedopuštenu taksonomiju** ima isti oblik kao u `0.4.0` (`code`, `message`,
  `data.status`, `data.params.taxonomy`, `data.details.taxonomy`), ali drugi tekst poruke:
  `taxonomy is not product_cat, product_brand or the taxonomy of a global product attribute.` (prije:
  `taxonomy is not one of product_cat and product_brand.`). Dopuštene taksonomije se više ne nabrajaju ni u
  poruci ni u javnom opisu putanje (`/wp-json/bulkbird/v1`), jer nazivi atributa trgovine nisu javni.
  `code` i status su isti.
- Atribut koji WooCommerce navodi, a čija taksonomija nije registrirana: 404 `bulkbird_taxonomy_not_found`
  (isto kao brendovi na trgovini bez brendova). U redovnom radu se ne događa.

Primjeri odgovora u `fixtures/v1/` (snimljeni 18. 11. 2026. stvarnim HTTP-om s plugina `0.5.0`,
`tests/tools/write-attribute-fixtures.php`; polja `attribute_order_by` i `note` opisuju slučaj; prema ranijim
primjerima, snimljenima kroz WP-CLI kodom s grane, razlikuju se samo ID-jevi vrijednosti):

- testna trgovina A (WooCommerce 11.3.0): `terms-order-attribute-menu-order.new.json`, `-name.new.json`,
  `-name-num.new.json`, `-id.new.json`;
- testna trgovina B (WooCommerce 8.0.3): `terms-order-attribute-menu-order-rest-written.old.json` (sve `null`, po
  nazivu), `terms-order-attribute-menu-order.old.json` (nakon upisa ključa `order`),
  `terms-order-attribute-id.old.json` (po nazivu);
- `terms-order-invalid-taxonomy-0.5.0.json`: tijelo greške 400 (sastavljeno ručno; integracijski test ga
  uspoređuje znak po znak, a stvarni odgovor obje trgovine mu je jednak).

## 11. Odobrene razlike prema prvoj inačici specifikacije

Odobrio vlasnik projekta 15. 11. 2026.; `docs/spec-v1.md` je usklađen.

| Tema | Prva inačica specifikacije | Ugovor | Razlog |
|---|---|---|---|
| `analytics_lookup` | potpune / u uvozu | `complete`, `importing`, `unknown` | stanje se čita iz funkcije WooCommercea koja nije dio javnog API-ja; ako je neka verzija nema, „ne znam” je poštenije od pogađanja |
| `memory_limit` | vrsta nije navedena | cijeli broj u bajtovima, `-1` bez ograničenja | isti oblik kao `max_upload_bytes`; Bulkbird ne mora tumačiti zapis `256M` |
| `POST /info` s ključem samo za čitanje | 404 ili 405 | 401 `woocommerce_rest_authentication_error` (s ključem za pisanje 404) | WooCommerce provjerava dozvolu ključa po metodi prije nego što WordPress traži putanju |
