# Bulkbird Helper — ugovor API-ja v1

Status: **faza 0 — odobreno** (razlike prema specifikaciji odobrio vlasnik projekta 15. 11. 2026.).
Potvrđeno stvarnim HTTP zahtjevima na obje testne stranice: WordPress 6.4.14 / WooCommerce 8.0.3 / PHP 8.1
i WordPress 7.1.4 / WooCommerce 11.3.0 / PHP 8.3 (45 testova na svakoj).
Jedini izvor istine za Bulkbird stranu. Mijenja se samo uz odobrenje vlasnika projekta.

- REST namespace: `bulkbird/v1` → adrese `https://<trgovina>/wp-json/bulkbird/v1/…`
- `api_version`: `1`
- Verzija plugina u ovom nacrtu: `0.1.0`

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
| `features` | popis teksta | uključene funkcije. U fazi 0 prazan popis. Slijede: `media.read` (faza 1), `media.upload` (faza 2), `changes` (faza 3), `sales` (faza 4) |
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

Bulkbird mora ignorirati polja koja ne poznaje (nova se mogu dodati bez promjene `api_version`).

Popis slugova koje `detected_plugins` može vratiti je u `includes/class-environment.php` (`RELEVANT_PLUGINS`).

Odgovor nikad ne sadrži: putanje na disku, podatke baze, ključeve, salt, korisnike, e-mailove, ništa iz `wp-config.php`.

Primjer (teststore1; vrijednosti `memory_limit` i `max_execution_time` ovise o poslužitelju):

```json
{
    "plugin_version": "0.1.0",
    "api_version": 1,
    "features": [],
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
    "analytics_lookup": "complete"
}
```

Primjeri stvarnih odgovora za Bulkbird testove: `fixtures/v1/info.json` (teststore1, najnovije verzije;
isti sadržaj kao `fixtures/v1/info.new.json`) i `fixtures/v1/info.old.json` (stara testna stranica).

## 6. Odobrene razlike prema prvoj inačici specifikacije

Odobrio vlasnik projekta 15. 11. 2026.; `docs/spec-v1.md` je usklađen.

| Tema | Prva inačica specifikacije | Ugovor | Razlog |
|---|---|---|---|
| `analytics_lookup` | potpune / u uvozu | `complete`, `importing`, `unknown` | stanje se čita iz funkcije WooCommercea koja nije dio javnog API-ja; ako je neka verzija nema, „ne znam” je poštenije od pogađanja |
| `memory_limit` | vrsta nije navedena | cijeli broj u bajtovima, `-1` bez ograničenja | isti oblik kao `max_upload_bytes`; Bulkbird ne mora tumačiti zapis `256M` |
| `POST /info` s ključem samo za čitanje | 404 ili 405 | 401 `woocommerce_rest_authentication_error` (s ključem za pisanje 404) | WooCommerce provjerava dozvolu ključa po metodi prije nego što WordPress traži putanju |
