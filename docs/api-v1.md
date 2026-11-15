# Bulkbird Helper — ugovor API-ja v1

Status: **faza 0 — odobreno** (razlike prema specifikaciji odobrio vlasnik projekta 15. 11. 2026.).
Faza 0 (`/info`, autentikacija) je potvrđena stvarnim HTTP zahtjevima na obje testne stranice: WordPress 6.4.14 /
WooCommerce 8.0.3 / PHP 8.1 i WordPress 7.1.4 / WooCommerce 11.3.0 / PHP 8.3.
**Poglavlja 6–8 (`/media`, ograničenje učestalosti) su nacrt paketa `0.2.0`** dok vlasnik ne pregleda izvještaj.
Jedini izvor istine za Bulkbird stranu. Mijenja se samo uz odobrenje vlasnika projekta.

- REST namespace: `bulkbird/v1` → adrese `https://<trgovina>/wp-json/bulkbird/v1/…`
- `api_version`: `1`
- Verzija plugina: `0.2.0-rc.2` (faza 0: `/info`; faze 1 i 2: `/media`)

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
| `features` | popis teksta | uključene funkcije. `0.1.0`: prazan popis. `0.2.0`: `media.read`, `media.upload`. Slijede: `changes` (faza 3), `sales` (faza 4) |
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

Primjer (teststore1; vrijednosti `memory_limit` i `max_execution_time` ovise o poslužitelju):

```json
{
    "plugin_version": "0.2.0-rc.2",
    "api_version": 1,
    "features": ["media.read", "media.upload"],
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

Primjeri stvarnih odgovora za Bulkbird testove: `fixtures/v1/info.json` (teststore1, najnovije verzije;
isti sadržaj kao `fixtures/v1/info.new.json`) i `fixtures/v1/info.old.json` (stara testna stranica).

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
| datoteka se nije mogla spremiti (disk, dozvole) | 500 | `bulkbird_upload_failed` |

Dva istovremena zahtjeva s istim `client_upload_id`: jedan dobiva 201, drugi pričeka i dobiva 200 s istom stavkom.

**Zahtjev koji odbije poslužitelj ispred WordPressa** (nginx, Cloudflare, ograničenje veličine tijela) vraća
413 **bez JSON tijela plugina**. Bulkbird mora podnijeti 413 i kad odgovor nije JSON.

Slika koja je trajno obrisana u adminu više ne postoji: isti `client_upload_id` tada stvara novu sliku (201).
Ista datoteka s **novim** `client_upload_id` namjerno stvara novu sliku; plugin ne sprječava duplikate po sadržaju.

## 8. Ograničenje učestalosti (od `0.2.0`)

Vrijedi za sve putanje `bulkbird/v1`, **po korisniku** kojem pripada ključ (svi ključevi istog korisnika dijele
brojač), u fiksnom prozoru od 60 sekundi:

- 120 zahtjeva u minuti (svi zahtjevi, i prijenosi);
- 30 prijenosa (`POST /media`) u minuti; broje se i neuspjeli prijenosi.

Kad je ograničenje prijeđeno: **429** `bulkbird_rate_limited`, zaglavlje `Retry-After` (sekunde do kraja
prozora) i isti broj u `data.retry_after`. Nakon toga zahtjevi opet prolaze.

Zahtjevi bez prijave (401) i zahtjevi korisnika bez prava (403) se ne broje. Trgovina može promijeniti
ograničenja filterom `bulkbird_helper_rate_limits`, pa se Bulkbird oslanja na `Retry-After`, ne na brojke.

## 9. Odobrene razlike prema prvoj inačici specifikacije

Odobrio vlasnik projekta 15. 11. 2026.; `docs/spec-v1.md` je usklađen.

| Tema | Prva inačica specifikacije | Ugovor | Razlog |
|---|---|---|---|
| `analytics_lookup` | potpune / u uvozu | `complete`, `importing`, `unknown` | stanje se čita iz funkcije WooCommercea koja nije dio javnog API-ja; ako je neka verzija nema, „ne znam” je poštenije od pogađanja |
| `memory_limit` | vrsta nije navedena | cijeli broj u bajtovima, `-1` bez ograničenja | isti oblik kao `max_upload_bytes`; Bulkbird ne mora tumačiti zapis `256M` |
| `POST /info` s ključem samo za čitanje | 404 ili 405 | 401 `woocommerce_rest_authentication_error` (s ključem za pisanje 404) | WooCommerce provjerava dozvolu ključa po metodi prije nego što WordPress traži putanju |
