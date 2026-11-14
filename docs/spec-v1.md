# Bulkbird Helper — specifikacija v1

Verzija dokumenta: 1 (14. 11. 2026.) · Status: na potvrdu
Odluke: zaseban repozitorij, WP 6.4+ / WC 8.0+ / PHP 8.1+, prva verzija = čitanje + prijenos slika.

## 1. Svrha i načela

Bulkbird Helper je mali, neobavezan WordPress plugin koji Bulkbirdu daje ono što WooCommerce REST API ne nudi. Bulkbird mora raditi i bez njega; plugin samo otključava dodatne mogućnosti.

Načela (čvrsta, za sve verzije):

1. **Neobavezan.** Bez plugina Bulkbird radi kao danas. Svaka funkcija ima zamjenski put.
2. **Bez sučelja.** Nema stranice postavki, izbornika, obavijesti ni widgeta. Samo redak u popisu pluginova (Aktiviraj / Deaktiviraj) i odjeljak u WooCommerce → Status.
3. **Nula opterećenja webshopa.** Nula CSS-a i JS-a (`wp_enqueue_*` se ne koristi). Na stranicama za kupce nijedan upit prema bazi. Plugin radi samo na REST zahtjevima za svoje putanje i na kukama spremanja/brisanja proizvoda.
4. **Ništa ne briše** u trgovini: ni proizvode, ni medije, ni narudžbe. Jedino brisanje je čišćenje vlastite tablice dnevnika i vlastitih zapisa pri deinstalaciji.
5. **Bez osobnih podataka.** Nijedan odgovor ne sadrži podatke kupaca, ID-eve narudžbi, adrese, e-mailove ni imena korisnika.
6. **Isti ključ.** Autentikacija istim WooCommerce REST ključem koji Bulkbird već koristi; lozinka aplikacije nije potrebna. Dozvola ključa (read / read_write) vrijedi i za plugin.
7. **Najmanja prava.** Svaka putanja provjerava sposobnost korisnika (capability); nijedna nema `permission_callback => __return_true`.

## 2. Okruženje i identitet

| Stavka | Vrijednost |
|---|---|
| Najniže verzije | WordPress 6.4, WooCommerce 8.0, PHP 8.1 |
| Testira se na | teststore1.bulkbird.app (najnovije: WP 7.1, WC 11.3, PHP 8.3) + jedna stara okolina (WP 6.4, WC 8.0, PHP 8.1) |
| Multisite | v1 ne podržava; aktivacija na mreži odbija se s porukom |
| HPOS | deklarirana kompatibilnost (`custom_order_tables`) |
| Slug / mapa | `bulkbird-helper` |
| PHP namespace | `Bulkbird\Helper` |
| REST namespace | `bulkbird/v1` |
| Verzija plugina | SemVer, prva `0.1.0` |
| Verzija API-ja | `api_version: 1`; mijenja se samo pri nekompatibilnoj promjeni |
| Licenca | GPLv2 ili novija (otvoreni kod; uvjet za WordPress.org, a gradi povjerenje). Bulkbird program ostaje zatvoren. |

**Autentikacija:** WooCommerce autentificira REST ključem samo svoje putanje. Plugin filterom `woocommerce_rest_is_request_to_rest_api` uključuje i `/wp-json/bulkbird/`. Time vrijede i provjera dozvole ključa po HTTP metodi (GET = read, POST = write) i ograničenja WooCommercea. Prijava kolačićem (WP admin u pregledniku) radi uz nonce kao i za ostali WordPress REST.

> **Napomena — za provjeru u fazi 0** (nalaz iz koda WooCommercea 11.3.0 na teststore1, 14. 11. 2026.; dodao CC na serveru):
>
> 1. **Opseg REST ključa u WooCommerceu 11.1+.** WooCommerce od verzije 11.1 ima dodatnu provjeru
>    (`reject_out_of_scope_route`) koja odbija REST ključ na putanjama izvan `wc/` i `wc-` (401,
>    `woocommerce_rest_authentication_error`), osim kad adresa zahtjeva imenuje istu putanju koju WordPress
>    i izvršava. Filter `woocommerce_rest_is_request_to_rest_api` bi prema kodu i dalje trebao raditi za
>    `/wp-json/bulkbird/…`, ali to nije dokazano stvarnim zahtjevom. Provjeriti na WC 8.0 i WC 11.3
>    (scenariji H0.1–H0.3, H0.14–H0.16, H0.91), uključujući oblik adrese `?rest_route=`.
> 2. **Nepoznat consumer key.** WooCommerce ključ koji ne postoji ne javlja kao grešku: zahtjev se
>    nastavlja kao neprijavljen. Zato 401 u tom slučaju mora vratiti sam plugin, u provjeri sposobnosti
>    (scenarij H0.6). Pogrešan secret uz postojeći ključ WooCommerce javlja sam (401).
>
> Ako se pokaže da filter na nekoj podržanoj verziji nije dovoljan, mehanizam autentikacije se mijenja
> samo uz odobrenje vlasnika projekta.

## 3. Putanje (API v1)

Svi odgovori su JSON. Greške u WordPress obliku (`code`, `message`, `data.status`), bez tragova stoga i putanja na disku. Paginacija kroz `page` / `per_page` i zaglavlja `X-WP-Total` / `X-WP-TotalPages`, osim gdje piše drukčije.

### 3.1 `GET /bulkbird/v1/info`

Sposobnost: `manage_woocommerce`.

Vraća: `plugin_version`, `api_version`, `features` (popis uključenih funkcija, npr. `media.read`, `media.upload`, `changes`, `sales`), verzije WordPressa, WooCommercea i PHP-a, HPOS da/ne, vremensku zonu i valutu trgovine, `max_upload_bytes`, `memory_limit`, `max_execution_time`, prepoznate pluginove važne Bulkbirdu (page builderi, SEO, WPML/Polylang, cache pluginovi, Omnibus) kao popis sluga i verzija, stanje Analytics lookup tablica (potpune / u uvozu).

Ne vraća: putanje na disku, tajne, korisnike, ništa iz wp-config.php.

### 3.2 `GET /bulkbird/v1/media`

Sposobnost: `upload_files`.

Parametri: `page`, `per_page` (1–100, zadano 50), `search` (naziv i ime datoteke), `modified_after` (GMT), `orderby` = `date` (zadano, najnovije prvo) ili `title`.

Samo slike: jpeg, png, webp, gif, avif. Vidi cijelu biblioteku (i slike vezane uz skice).

Stavka: `id`, `title`, `alt`, `filename`, `mime`, `width`, `height`, `filesize`, `date_gmt`, `modified_gmt`, `parent_id`, `urls` {`thumbnail`, `medium`, `full`}.

### 3.3 `POST /bulkbird/v1/media`

Sposobnost: `upload_files`; ključ mora imati dozvolu write.

Tijelo: `multipart/form-data` s jednom datotekom; neobavezno `title`, `alt`; **obavezno `client_upload_id`** (UUID koji generira Bulkbird).

Pravila:
- Vrsta se provjerava po sadržaju (`wp_check_filetype_and_ext`), ne po nastavku. Dopuštene samo vrste iz 3.2. SVG nikad.
- Veličina najviše `min(wp_max_upload_size(), 20 MB)`.
- **Idempotentnost:** `client_upload_id` se sprema u meta polje privitka `_bulkbird_upload_id`. Ponovljeni zahtjev s istim ID-em vraća postojeći privitak (status 200 umjesto 201) i ne stvara duplikat. To je nužno za Bulkbirdov oporavak prekinutog slanja.
- WordPress generira uobičajene veličine slike.
- Plugin sliku **ne dodjeljuje** proizvodu. Dodjelu radi Bulkbird kroz WooCommerce REST (`images: [{id}]` / `image: {id}`), kao običnu izmjenu s pregledom i poništavanjem.
- Poništavanje u Bulkbirdu uklanja dodjelu, ali prenesena slika ostaje u biblioteci (plugin ništa ne briše).

Odgovor: stavka kao u 3.2, status 201 (nova) ili 200 (već postoji).

### 3.4 `GET /bulkbird/v1/changes`

Sposobnost: `manage_woocommerce`.

Dnevnik promjena za brzu i točnu sinkronizaciju, uključujući **obrisane** proizvode, koje REST ne javlja.

Parametri: `cursor` (zadnji primljeni `id`; 0 = od početka zadržanih zapisa), `limit` (1–1000, zadano 500).

Stavka: `id` (rastući), `object_type` (`product`, `variation`, `product_cat`), `object_id`, `parent_id`, `action` (`created`, `updated`, `stock`, `trashed`, `untrashed`, `deleted`), `changed_at_gmt`. Bez sadržaja proizvoda: Bulkbird nakon toga dohvaća promijenjene proizvode običnim REST-om.

Odgovor: `items`, `next_cursor`, `has_more`, `oldest_cursor`. Ako je `cursor` stariji od najstarijeg zadržanog zapisa, greška `bulkbird_cursor_expired` (410), a Bulkbird radi punu sinkronizaciju.

Bilježenje (kuke): stvaranje i spremanje proizvoda i varijanti, promjena zalihe (`woocommerce_product_set_stock`, `woocommerce_variation_set_stock`, jer se zaliha iz narudžbi mijenja izravnim SQL-om), premještanje u smeće, vraćanje, trajno brisanje, stvaranje / uređivanje / brisanje `product_cat`. Uzastopni isti zapis za isti objekt unutar 2 s spaja se u jedan.

Tablica `{prefix}bulkbird_changes` s indeksom na `id` i `changed_at_gmt`. Zadržavanje 30 dana; čišćenje jednim dnevnim WP cron poslom (jedini pozadinski posao plugina).

### 3.5 `GET /bulkbird/v1/sales`

Sposobnost: `view_woocommerce_reports`.

Zbirna prodaja po proizvodu i varijanti, izračunata na serveru, tako da podaci iz narudžbi ne napuštaju trgovinu.

Parametri: `date_from`, `date_to` (GMT), `statuses` (zadano: isti skup statusa koji Bulkbird danas koristi), `page`, `per_page` (do 1000).

Stavka: `product_id`, `variation_id`, `qty`, `net_revenue`, `refund_qty`, `refunded_revenue`. Bez ID-eva narudžbi, kupaca i datuma pojedinih narudžbi.

Izvor: WooCommerce Analytics lookup tablice (`wc_order_product_lookup`, `wc_order_stats`). Ako uvoz lookup tablica nije dovršen, greška `bulkbird_lookup_incomplete` (409), a Bulkbird koristi postojeći način. Rezultat se drži u cacheu 5 minuta po skupu parametara.

**Prihvatni uvjet:** na teststore1 (2.000 narudžbi, 71 povrat, različiti statusi) rezultat mora biti jednak onome što Bulkbird danas izračuna iz narudžbi preko REST-a. Svaka razlika se objašnjava i odlučuje prije uključivanja.

### 3.6 WooCommerce → Status

Odjeljak „Bulkbird Helper” u izvješću o stanju sustava: verzija plugina, `api_version`, broj zapisa u dnevniku promjena i najstariji zapis, vrijeme zadnjeg Bulkbird zahtjeva. Bez nove stranice ili izbornika.

## 4. Sigurnost

- Svaki ulaz validiran i saniran kroz `args` sheme REST putanja; SQL samo kroz `$wpdb->prepare`.
- Ograničenje učestalosti po korisniku (npr. 120 zahtjeva / min, prijenos slika 30 / min) → 429 s `Retry-After`.
- Nikakvo zapisivanje sadržaja zahtjeva u log; greške samo s kodom i kratkim opisom.
- Opcije s `autoload = no`.
- `uninstall.php`: briše tablicu dnevnika i vlastite opcije; meta `_bulkbird_upload_id` na privicima ostaje (nije štetan, a brisanje bi diralo medije).
- Deaktivacija: uklanja cron posao; podaci ostaju do deinstalacije.
- Provjere: PHPStan (razina 6 ili viša), PHPCS s WordPress standardom (uključujući sigurnosna pravila), ručni pregled svakog `permission_callback`.

## 5. Učinkovitost (mjerljivo)

- Na stranici proizvoda i naslovnici za kupca: 0 dodatnih upita i 0 dodatnih učitanih datoteka (Query Monitor, prije i poslije aktivacije).
- Spremanje proizvoda: najviše 1 dodatni INSERT po događaju.
- `GET /changes` s 500 stavki i `GET /sales` za 12 mjeseci na teststore1: izmjeriti i zapisati vrijeme; cilj ispod 500 ms.
- Automatska provjera u CI-ju: pada ako se u kodu pojavi `wp_enqueue_script`, `wp_enqueue_style` ili kuka na frontend (`wp_head`, `wp_footer`, `template_redirect`, `init` s upitom prema bazi).

## 6. Strana Bulkbirda (program)

- **Otkrivanje:** iz REST indeksa trgovine (namespace `bulkbird/v1`), zatim `GET /info`. Rezultat se pamti po trgovini i osvježava pri sinkronizaciji.
- **Uključivanje funkcija** samo prema `features` i `api_version`. Nepoznata ili starija verzija → zamjenski put, bez greške.
- **Postavke trgovine:** redak stanja, npr. „Bulkbird Helper: aktivan, 0.1.0” ili „nije instaliran — otključava cijelu medijsku biblioteku, prijenos slika i bržu sinkronizaciju” s gumbom „Otvori instalaciju u WP adminu” (dok plugin nije na WordPress.org: uputa za instalaciju iz zip datoteke).
- **Odabir slike (tri razine):** Helper → cijela biblioteka; bez njega javni `/wp/v2/media`; ako je i to blokirano → slike koje Bulkbird već zna iz proizvoda. Program jasno piše koja razina radi.
- **Prijenos slika:** samo uz Helper i ključ s dozvolom write; kroz postojeći mehanizam slanja (dnevnik, oporavak, idempotentni `client_upload_id`).
- **Sinkronizacija:** `GET /changes` za brzu sinkronizaciju i otkrivanje obrisanih; povremena puna sinkronizacija ostaje kao sigurnosna mreža. `cursor_expired` → puna sinkronizacija.
- **Prodaja:** `GET /sales` kad je dostupan i lookup potpun; inače postojeći način.
- **Neovisno o pluginu — cache:** Bulkbird na REST odgovorima pri sinkronizaciji provjerava znakove cachea (`Age` > 0, `X-Cache: HIT`, `cf-cache-status: HIT`, `X-Varnish` s dva ID-a) i upozorava korisnika jer podaci mogu biti zastarjeli. Test: na teststore1 privremeno uključiti Varnish (samo uz odobrenje, s povratkom na polaznu kopiju).

## 7. Testiranje i isporuka plugina

- **Integracijski testovi** kroz REST i WP-CLI (`wp83`) na teststore1, uz mogućnost vraćanja polazne kopije; za svaku putanju: uspjeh, krivi ključ, ključ samo za čitanje na POST, korisnik bez sposobnosti, krivi parametri, ograničenje učestalosti.
- **Stara okolina** (WP 6.4 / WC 8.0 / PHP 8.1): druga stranica na istom serveru ili kontejner; prijedlog i odobrenje prije izrade.
- **Namjerni kvarovi** kao u Bulkbirdu (npr. putanja bez provjere sposobnosti, prijenos bez provjere vrste, dnevnik bez kuke za zalihu) moraju srušiti testove.
- **Ugovor:** `docs/api-v1.md` u repozitoriju bulkbird-helper je jedini izvor istine. Testovi plugina proizvode primjere JSON odgovora (`fixtures/`), a Bulkbird ih kopira uz broj verzije i ima test koji provjerava da ih razumije. Promjena ugovora samo uz odobrenje vlasnika projekta.
- **Isporuka:** zip iz git oznake, s SHA-256. Za beta testere ručno (Drive); WordPress.org kasnije, kao zasebna odluka.
- **Na serveru:** plugin u `wp-content/plugins/bulkbird-helper` postavlja se iz git oznake (ne uređuje se izravno na serveru).

## 8. Podjela posla

| Tko | Što |
|---|---|
| Vlasnik projekta | stvara privatni repozitorij SvejeDobro/bulkbird-helper, dodaje deploy ključ servera, odobrava scenarije, ugovor i svaku fazu |
| CC na serveru | repozitorij bulkbird-helper: kod plugina, testovi, `docs/api-v1.md`, primjeri odgovora, postavljanje na teststore1 |
| CC za Bulkbird | strana programa (poglavlje 6), scenariji i testovi u Bulkbird repozitoriju; plugin ne mijenja |

## 9. Faze

Svaka faza: scenariji → odobrenje → izvedba → testovi i namjerni kvarovi → izvještaj (i snimke, kad ima sučelja u Bulkbirdu) → odobrenje.

| Faza | Plugin (server) | Bulkbird (Windows) |
|---|---|---|
| 0 | repozitorij, kostur, autentikacija, `GET /info`, PHPStan/PHPCS/CI, postavljanje na teststore1, mjerenje učinka prije/poslije | otkrivanje plugina, redak stanja u Postavkama trgovine, provjera cachea na REST odgovorima |
| 1 | `GET /media` | odabir slike (tri razine) i dodjela postojećih slika proizvodima i varijantama |
| 2 | `POST /media` | prijenos slika uz idempotentnost i oporavak |
| 3 | `GET /changes` | brza sinkronizacija i otkrivanje obrisanih |
| 4 | `GET /sales` | prodaja iz plugina uz usporedbu s postojećim izračunom |

## 10. Otvorena pitanja

1. Ime za WordPress.org i zaštitni znak „Bulkbird” (kad plugin ide javno).
2. Gdje stoji stara okolina za testove (druga CloudPanel stranica ili kontejner).
3. Treba li `GET /changes` bilježiti i promjene atributa (`pa_*` pojmova) — prijedlog: u v1 ne, osim ako Bulkbird to već treba za generator varijanti.
