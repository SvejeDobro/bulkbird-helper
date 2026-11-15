# Bulkbird Helper — scenariji, faze 1 i 2 (`GET /media` i `POST /media`)

Verzija dokumenta: 1 (15. 11. 2026.) · Status: **na potvrdu** · Izvor: `docs/spec-v1.md`, poglavlja 3.2, 3.3 i 4

Faze 1 i 2 idu kao jedan paket (verzija plugina `0.2.0`). Kod se piše tek nakon odobrenja ovih scenarija
i odluka iz poglavlja 9.

Oznake:

- **NOVA** — teststore1.bulkbird.app (WordPress 7.1, WooCommerce 11.3, PHP 8.3)
- **STARA** — teststoreold.bulkbird.app (WordPress 6.4.14, WooCommerce 8.0.3, PHP 8.1)
- **CI** — GitHub Actions (jedinični i integracijski testovi)
- Ključevi i korisnici kao u fazi 0: K-READ, K-RW, K-WRITE (`bulkbird-test-shopmgr`), K-CUST (`bulkbird-test-customer`).

Zatečeno stanje (provjereno 15. 11. 2026., samo čitanje):

| | NOVA | STARA |
|---|---|---|
| Slike u biblioteci | 716 (715 JPEG vezanih uz proizvode, 1 WebP bez roditelja) | 716 |
| Shop manager ima `upload_files` | da | da |
| WebP: dopušten prijenos / izrada umanjenih slika | da / da | da / da |
| AVIF: dopušten prijenos / izrada umanjenih slika | da / da | **ne / ne** (WordPress 6.4 ne podržava AVIF) |
| Najveći prijenos koji dopušta WordPress (web) | 64 MB | 64 MB |
| Zahtjev od 25 MB stiže do WordPressa (Cloudflare i nginx ga ne odbijaju) | da | nije provjereno |

Sve što se u scenarijima naziva „stavka” ima točno ova polja (specifikacija 3.2):
`id`, `title`, `alt`, `filename`, `mime`, `width`, `height`, `filesize`, `date_gmt`, `modified_gmt`,
`parent_id`, `urls` {`thumbnail`, `medium`, `full`}.

---

## 1. `GET /bulkbird/v1/media` — dozvole

| ID | Scenarij | Očekivano |
|---|---|---|
| H1.1 | K-READ (Shop manager), `GET /media` | 200, popis stavki, zaglavlja `X-WP-Total` i `X-WP-TotalPages` |
| H1.2 | K-RW | 200 |
| H1.3 | K-WRITE (samo pisanje) | 401 `woocommerce_rest_authentication_error` |
| H1.4 | Bez ključa; nepoznat ključ | 401 `bulkbird_unauthorized`; tijelo bez ijedne stavke |
| H1.5 | K-CUST (korisnik bez `upload_files`) | 403 `bulkbird_forbidden` |
| H1.6 | Kolačić Shop managera + nonce | 200; bez noncea 401 |
| H1.7 | Korisnik s `upload_files`, ali bez `manage_woocommerce` (uloga Author; samo integracijski test) | prema odluci P1 |
| H1.8 | Ključ u adresi (`consumer_key`…) i oblik `?rest_route=/bulkbird/v1/media` | 200, isti rezultat |
| H1.9 | Greške | WordPress oblik (`code`, `message`, `data.status`), bez putanja na disku |

## 2. `GET /media` — sadržaj, paginacija, pretraga

| ID | Scenarij | Očekivano |
|---|---|---|
| H1.10 | Bez parametara | prva stranica, 50 stavki, najnovije prvo (`date_gmt` silazno; pri istom datumu veći `id` prvi) |
| H1.11 | Polja stavke | točno 12 polja iz specifikacije; `urls` ima točno `thumbnail`, `medium`, `full` |
| H1.12 | Vrste podataka | `id`, `parent_id`, `width`, `height`, `filesize` cijeli brojevi; ostalo tekst; datumi `YYYY-MM-DDTHH:MM:SS` u GMT |
| H1.13 | Usporedba s bazom | za 20 nasumičnih stavki `title`, `alt`, `mime`, `width`, `height`, `parent_id` i datumi jednaki onome što vraća WP-CLI |
| H1.14 | `filename` | samo ime datoteke (npr. `mh01-black_main.jpg`), bez mape i bez putanje na disku |
| H1.15 | `urls` | svaka adresa vraća 200 i sliku; `full` je izvorna (ili WordPressova umanjena „-scaled”) slika |
| H1.16 | Slika manja od veličine `medium` ili bez izrađenih veličina | `thumbnail` / `medium` pokazuju na najbližu postojeću veličinu, u krajnjem slučaju na `full`; nikad prazno (odluka P2) |
| H1.17 | Slika bez metapodataka ili s datotekom koje više nema na disku | stavka se vraća; `width`, `height`, `filesize` su `null`; nema greške 500 (odluka P3) |
| H1.18 | `X-WP-Total` | jednak broju slika dopuštenih vrsta u biblioteci (NOVA: 716) |
| H1.19 | `per_page=1`, `per_page=100`, `page=2` | točan broj stavki; stranice se ne preklapaju i zajedno daju sve stavke točno jednom |
| H1.20 | `per_page=0`, `per_page=101`, `per_page=abc`, `page=0`, `page=-1` | 400 `rest_invalid_param` |
| H1.21 | `page` iza zadnje stranice | 200, prazan popis, ispravna zaglavlja ukupnog broja (odluka P4) |
| H1.22 | `orderby=title` | uzlazno po naslovu; pri istom naslovu manji `id` prvi |
| H1.23 | `orderby=nepoznato` | 400 `rest_invalid_param` |
| H1.24 | `search=hoodie` | stavke kojima naslov **ili ime datoteke** sadrži traženi tekst, bez razlike velikih i malih slova; `X-WP-Total` odgovara |
| H1.25 | `search` po dijelu imena datoteke koji nije u naslovu (npr. `_main`) | stavke se nalaze |
| H1.26 | `search` sa znakovima `%`, `_`, navodnicima, `' OR 1=1 --`, `<script>` | tretira se kao običan tekst; nema SQL greške; rezultat prazan ili točan |
| H1.27 | `search` prazan ili samo razmaci | kao da ga nema |
| H1.28 | `modified_after=2026-11-13T00:00:00` | samo stavke izmijenjene **strogo nakon** tog trenutka (GMT) |
| H1.29 | `modified_after` u budućnosti | prazan popis, `X-WP-Total: 0` |
| H1.30 | `modified_after` neispravan (`jučer`, `2026-13-40`) | 400 `rest_invalid_param` |
| H1.31 | `modified_after` s oznakom zone (`…Z`, `…+01:00`) | prihvaća se i preračunava u GMT (odluka P5) |
| H1.32 | Promjena samo alternativnog teksta slike u adminu, zatim `modified_after` od prije promjene | slika se vraća (ako WordPress pritom ne mijenja datum izmjene: dokumentirano ograničenje i odluka P6) |
| H1.33 | Kombinacija `search` + `modified_after` + `orderby=title` + paginacija | svi uvjeti vrijede zajedno |
| H1.34 | Vrste | vraćaju se samo jpeg, png, webp, gif, avif; SVG, PDF, video, BMP, TIFF, HEIC i ostalo iz biblioteke se ne vraćaju (u integracijskom testu se takvi privici dodaju) |
| H1.35 | Slika vezana uz skicu proizvoda, uz privatnu objavu i slika bez roditelja | sve se vraćaju („vidi cijelu biblioteku”) |
| H1.36 | Slika u smeću (ako trgovina koristi smeće za medije) | ne vraća se |
| H1.37 | Naslov i alt s HTML-om, navodnicima, emojijem, hrvatskim znakovima | vraćaju se kao čisti tekst, točno kako su spremljeni; JSON ispravan |
| H1.38 | Osobni podaci | stavka nema autora, e-mail ni ime korisnika; odgovor ne sadrži putanje na disku |
| H1.39 | Nepoznati parametri | ignoriraju se |
| H1.40 | `HEAD /media` | 200 bez tijela, sa zaglavljima ukupnog broja |
| H1.41 | Zaglavlja | `Cache-Control: no-store`; bez `Set-Cookie` |
| H1.42 | Upis u bazu | `GET /media` ne upisuje ništa osim brojača ograničenja učestalosti (poglavlje 7) |

## 3. `GET /media` — učinkovitost

| ID | Scenarij | Očekivano |
|---|---|---|
| H1.43 | Broj upita za `per_page=100` | ne raste s brojem stavki (bez upita po stavci): najviše 10 upita plugina po zahtjevu |
| H1.44 | Vrijeme na serveru, `per_page=100`, NOVA i STARA (716 slika) | izmjeriti i zapisati; cilj < 300 ms |
| H1.45 | `search` na 716 slika | izmjeriti; cilj < 300 ms. Ograničenje se dokumentira: pretraga po dijelu teksta ne koristi indeks, pa na vrlo velikim bibliotekama (desetci tisuća slika) može biti sporija |
| H1.46 | Stranice za kupce i tuđi REST zahtjevi nakon `0.2.0` | i dalje 0 dodatnih upita i samo glavna datoteka (ponavlja se mjerenje iz faze 0, uz odobrenje) |

## 4. `POST /bulkbird/v1/media` — dozvole i oblik zahtjeva

Zahtjev: `multipart/form-data`, polje datoteke `file`, neobavezno `title`, `alt`, obavezno `client_upload_id`.

| ID | Scenarij | Očekivano |
|---|---|---|
| H2.1 | K-RW, ispravan JPEG, novi `client_upload_id` | **201**, stavka kao u `GET /media`; slika je u biblioteci |
| H2.2 | K-WRITE (samo pisanje) | 201 |
| H2.3 | K-READ | 401 `woocommerce_rest_authentication_error`; ništa nije spremljeno |
| H2.4 | Bez ključa; nepoznat ključ | 401 `bulkbird_unauthorized`; ništa nije spremljeno |
| H2.5 | K-CUST | 403 `bulkbird_forbidden`; ništa nije spremljeno |
| H2.6 | Kolačić Shop managera + nonce | 201 |
| H2.7 | Bez polja `file` | 400 `bulkbird_missing_file` |
| H2.8 | Dvije datoteke u zahtjevu (`file` i još jedna, ili `file[]`) | 400 `bulkbird_single_file_only`; ništa nije spremljeno |
| H2.9 | Bez `client_upload_id` | 400 `rest_missing_callback_param` |
| H2.10 | `client_upload_id` koji nije UUID (`123`, prazan, predug, s razmacima) | 400 `rest_invalid_param` |
| H2.11 | UUID velikim slovima | prihvaća se; uspoređuje se bez razlike velikih i malih slova |
| H2.12 | Tijelo nije `multipart/form-data` (npr. JSON ili sirovi bajtovi) | 400 `bulkbird_missing_file` |
| H2.13 | Prekinut prijenos (PHP javlja djelomičnu datoteku) | 400 `bulkbird_upload_incomplete`; ništa nije spremljeno |

## 5. `POST /media` — vrsta, veličina, sadržaj

| ID | Scenarij | Očekivano |
|---|---|---|
| H2.14 | JPEG, PNG, WebP, GIF (i animirani GIF) | 201; `mime` odgovara stvarnom sadržaju |
| H2.15 | AVIF na NOVA | 201 |
| H2.16 | AVIF na STARA (WordPress 6.4 ga ne podržava) | 415 `bulkbird_unsupported_type`; ništa nije spremljeno; `/info` unaprijed kaže koje vrste trgovina prima (odluka P7) |
| H2.17 | SVG (i SVG preimenovan u `.jpg` ili `.png`) | 415 `bulkbird_unsupported_type` — nikad |
| H2.18 | PHP datoteka preimenovana u `slika.jpg` | 415; ništa nije spremljeno |
| H2.19 | PDF, ZIP, HTML, tekst, BMP, TIFF, HEIC, ICO | 415 |
| H2.20 | Ispravna PNG slika s imenom `slika.jpg` (nastavak ne odgovara sadržaju) | 201; spremljena s nastavkom koji odgovara sadržaju (`.png`), `mime` = `image/png` |
| H2.21 | Ime datoteke `napad.php.jpg`, `../../x.jpg`, `C:\x.jpg`, s razmacima i hrvatskim znakovima, vrlo dugo | 201; ime je sanirano, datoteka je u mapi za prijenose, nema `.php` u imenu koje bi server izvršio |
| H2.22 | Ispravan JPEG kojem je na kraj dodan PHP kod | 201 kao slika; datoteka ima nastavak slike; izravan poziv njezine adrese vraća sliku, ne izvršava kod |
| H2.23 | Oštećena slika (zaglavlje JPEG-a, ostatak smeće) ili datoteka od 0 bajtova | 415 ili 400 `bulkbird_invalid_image`; ništa nije spremljeno |
| H2.24 | Datoteka veća od `min(najveći prijenos WordPressa, 20 MB)` (npr. 21 MB) | 413 `bulkbird_file_too_large`; ništa nije spremljeno |
| H2.25 | Datoteka točno na granici i 1 bajt iznad | na granici 201, iznad 413 |
| H2.26 | Zahtjev veći od PHP `post_max_size` (PHP tada ne preda ni polja ni datoteku) | 413 `bulkbird_file_too_large`, ne zbunjujuća „nedostaje datoteka” |
| H2.27 | Zahtjev koji odbije nginx ili Cloudflare prije WordPressa | odgovor nije JSON plugina; dokumentira se u `docs/api-v1.md` da Bulkbird mora podnijeti 413 bez JSON tijela |
| H2.28 | Slika golemih dimenzija u maloj datoteci (npr. 20 000 × 20 000 px) | 400 `bulkbird_image_too_large` prije izrade umanjenih slika (odluka P8) |
| H2.29 | Trgovina koja je sama zabranila neku vrstu (npr. WebP kroz filter `upload_mimes`) | 415 — poštuje se postavka trgovine (odluka P9) |
| H2.30 | Mapa za prijenose nije upisiva ili je disk pun (integracijski test s lažnim kvarom) | 500 `bulkbird_upload_failed`, poruka bez putanje; ništa polovično u biblioteci |

## 6. `POST /media` — rezultat i idempotentnost

| ID | Scenarij | Očekivano |
|---|---|---|
| H2.31 | Stavka u odgovoru | ista polja i vrijednosti kao kad se ta slika zatim dohvati kroz `GET /media` |
| H2.32 | Umanjene veličine | WordPress je izradio uobičajene veličine (`thumbnail`, `medium`, WooCommerce veličine); `urls` pokazuju na njih |
| H2.33 | `title` i `alt` poslani | spremljeni kao čisti tekst (HTML uklonjen); `alt` je u polju alternativnog teksta |
| H2.34 | `title` izostavljen | naslov iz imena datoteke (kao pri ručnom prijenosu u WordPress) |
| H2.35 | Vrlo dug `title` / `alt` (10 000 znakova) | 400 `rest_invalid_param` (odluka P10: najviše 255 za naslov, 1000 za alt) |
| H2.36 | Dodjela | `parent_id` = 0; slika nije dodijeljena nijednom proizvodu; nijedan proizvod nije promijenjen (broj i datumi izmjene proizvoda isti) |
| H2.37 | Vlasnik privitka | korisnik kojem pripada ključ (ne ispisuje se u odgovoru) |
| H2.38 | Meta polje | privitak ima `_bulkbird_upload_id` = poslani UUID (malim slovima) |
| H2.39 | **Ponovljeni zahtjev** s istim `client_upload_id` i istom datotekom | **200**, ista stavka (isti `id`); u biblioteci je i dalje jedna slika; na disku nema druge kopije |
| H2.40 | Ponovljeni zahtjev 10 puta zaredom | uvijek 200 i isti `id`; ukupan broj slika se povećao točno za 1 |
| H2.41 | **Dva istovremena zahtjeva** s istim `client_upload_id` (prekid i ponovni pokušaj dok prvi još traje) | jedan 201 i jedan 200 s istim `id` (ili drugi 409 `bulkbird_upload_in_progress` s `Retry-After`); nikad dvije slike (odluka P11) |
| H2.42 | Isti `client_upload_id`, **druga datoteka** | 409 `bulkbird_upload_id_conflict`; postojeća slika nepromijenjena, nova nije spremljena (odluka P12) |
| H2.43 | Isti `client_upload_id`, ista datoteka, drugi `title` / `alt` | 200, postojeća stavka **nepromijenjena** (ponavljanje ne uređuje) |
| H2.44 | Ista datoteka, novi `client_upload_id` | 201, nova slika (WordPress imenu dodaje `-1`); to je namjerno — duplikate po sadržaju plugin ne sprječava |
| H2.45 | Slika prenesena pa trajno obrisana u adminu, zatim isti `client_upload_id` | 201, nova slika (odluka P13) |
| H2.46 | Slika prenesena pa premještena u smeće (ako trgovina koristi smeće za medije), zatim isti `client_upload_id` | prema odluci P13 |
| H2.47 | Prekid usred prijenosa pa ponovni zahtjev (stvarni Bulkbirdov slučaj): veza prekinuta nakon što je server spremio sliku, a prije nego što je klijent dobio odgovor | ponovni zahtjev vraća 200 i istu stavku |
| H2.48 | `GET /media?search=<ime>` nakon prijenosa | nova slika je u popisu; `X-WP-Total` veći za 1 |
| H2.49 | `/info` | `features` sadrži `media.read` i `media.upload`; `plugin_version` = `0.2.0` |
| H2.50 | Plugin ništa ne briše | nakon svih testova nijedan raniji privitak nije obrisan ni izmijenjen (usporedba broja i najvećeg `modified_gmt` ranijih privitaka) |
| H2.51 | Log | u `wp-debug.log` nema imena datoteka, sadržaja zahtjeva ni ključeva; greške samo s kodom |
| H2.52 | Zaglavlja | `Cache-Control: no-store`; 201 nosi `Location` na stavku ako se uvede `GET /media/<id>` (odluka P14) |

## 7. Ograničenje učestalosti (specifikacija 4, odluka P10 iz faze 0)

Vrijedi za sve putanje plugina, po korisniku kojem pripada ključ.

| ID | Scenarij | Očekivano |
|---|---|---|
| H1.47 | 120 zahtjeva u minuti na `GET /media` ili `/info`, zatim 121. | prvih 120 uspijeva; 121. je **429** `bulkbird_rate_limited` sa zaglavljem `Retry-After` (sekunde do kraja prozora) |
| H1.48 | Nakon isteka `Retry-After` | zahtjev opet uspijeva |
| H2.53 | 30 prijenosa u minuti, zatim 31. | 31. je 429 s `Retry-After`; ništa nije spremljeno |
| H2.54 | Neuspjeli prijenosi (400, 413, 415) | broje se u ograničenje prijenosa (inače bi se moglo beskonačno slati neispravne datoteke) |
| H1.49 | Dva korisnika (dva ključa različitih korisnika) | brojači su odvojeni |
| H1.50 | Dva ključa istog korisnika (K-READ i K-RW) | dijele brojač (ograničenje je po korisniku) |
| H1.51 | Zahtjevi bez prijave (401) | ne broje se i ne upisuju ništa u bazu (odluka P15) |
| H1.52 | Odgovor 429 | WordPress oblik greške; `Cache-Control: no-store` |
| H1.53 | Istovremeni zahtjevi oko granice | broj propuštenih ne prelazi ograničenje za više od nekoliko zahtjeva (brojanje jednim atomskim upitom; odluka P16) |
| H1.54 | Trošak | najviše 1 dodatni upit upisa po zahtjevu prema putanjama plugina; **nijedan** na stranicama za kupce i tuđim REST zahtjevima |
| H1.55 | Ograničenja se mogu promijeniti filterom (za trgovine s drugačijim potrebama i za testove) | integracijski test postavlja niže vrijednosti i provjerava ponašanje |
| H1.56 | Čišćenje | brojači ne rastu neograničeno: stari se prepisuju, ne gomilaju se retci |

## 8. Aktivacija, nadogradnja, deinstalacija, stara verzija, provjere

| ID | Scenarij | Očekivano |
|---|---|---|
| H1.57 | Nadogradnja s `0.1.0` na `0.2.0` zamjenom datoteka (bez ponovne aktivacije) | putanje `/media` rade odmah; ako nova verzija treba vlastitu tablicu ili opciju (brojači), stvara je pri prvom zahtjevu prema putanji plugina, nikad na stranicama za kupce |
| H1.58 | Nova aktivacija `0.2.0` | isto stanje kao nakon nadogradnje |
| H1.59 | Deinstalacija | briše vlastite opcije i (ako postoji) tablicu brojača; **privici i meta `_bulkbird_upload_id` ostaju**; broj slika nepromijenjen |
| H1.60 | STARA: svi scenariji poglavlja 1–7 | isto ponašanje kao na NOVA, osim AVIF-a (H2.16) |
| H1.61 | STARA: `filesize` i veličine | ispravni i na WordPressu 6.4 |
| H1.62 | Trgovina bez WooCommercea ili na multisiteu | putanje `/media` ne postoje (kao u fazi 0) |
| H1.63 | CI | sintaksa PHP 8.1–8.4, PHPCS, PHPStan razina 8, jedinični i integracijski testovi (matrica iz faze 0), čuvari |
| H1.64 | Čuvar dozvola | svaka nova putanja i metoda ima `permission_callback`; `POST` traži `upload_files` |
| H1.65 | Ugovor i primjeri | `docs/api-v1.md` dopunjen za `/media`; `fixtures/v1/media-list.json`, `media-item-created.json`, `media-item-existing.json`, primjeri grešaka |
| H1.66 | Postavljanje | `0.2.0` na obje stranice samo iz git oznake; prethodna verzija premještena, ne obrisana |

Namjerni kvarovi (svaki mora srušiti testove):

| ID | Kvar |
|---|---|
| H2.55 | `GET /media` bez provjere sposobnosti |
| H2.56 | `POST /media` traži samo prijavu, ne `upload_files` |
| H2.57 | Vrsta se provjerava po nastavku, ne po sadržaju |
| H2.58 | SVG dodan na popis dopuštenih vrsta |
| H2.59 | Bez provjere veličine |
| H2.60 | Bez provjere `client_upload_id` (ponovljeni zahtjev stvara duplikat) |
| H2.61 | Isti `client_upload_id` s drugom datotekom prepisuje ili vraća 200 |
| H2.62 | Plugin dodjeljuje sliku proizvodu (`post_parent` ili meta proizvoda) |
| H2.63 | `search` spojen u SQL bez `prepare` |
| H2.64 | `GET /media` vraća i privitke koji nisu slike |
| H2.65 | `filename` vraća punu putanju na disku |
| H2.66 | Bez ograničenja učestalosti; ili brojač po IP adresi umjesto po korisniku |
| H2.67 | Upit po stavci u `GET /media` (N+1) |
| H2.68 | Brojač učestalosti se upisuje i na stranicama za kupce |

Testni podaci: prijenosi u testovima stvaraju stvarne privitke na testnim stranicama. Vidi odluku P17.

## 9. Odluke koje trebam

| # | Pitanje | Prijedlog |
|---|---|---|
| P1 | Smije li `GET /media` korisnik koji ima `upload_files`, ali nije Shop manager ni administrator (npr. uloga Author)? Specifikacija traži samo `upload_files`. | Da, kako piše u specifikaciji: WordPress takvim korisnicima ionako pokazuje cijelu biblioteku. Stroža mogućnost: tražiti i `manage_woocommerce`. |
| P2 | Što vratiti u `urls.thumbnail` / `urls.medium` kad ta veličina ne postoji? | Adresu najbliže postojeće veličine, u krajnjem slučaju `full`; nikad `null`, da Bulkbird uvijek ima što prikazati. |
| P3 | `width`, `height`, `filesize` kad podatka nema | `null` (ne 0), a stavka se ipak vraća. |
| P4 | `page` iza zadnje stranice | 200 i prazan popis (WordPressove vlastite putanje vraćaju 400; prazan popis je jednostavniji za petlju u Bulkbirdu). |
| P5 | Oblik `modified_after` | ISO 8601; bez oznake zone znači GMT; s oznakom se preračunava. Usporedba je stroga (`>`). |
| P6 | Ako WordPress pri promjeni samo alternativnog teksta ne mijenja datum izmjene slike, `modified_after` takvu promjenu ne vidi. | Najprije izmjeriti (H1.32). Ako se pokaže: dokumentirati kao ograničenje u v1; plugin ne dodaje kuke koje bi mijenjale datume tuđih zapisa. |
| P7 | Kako Bulkbird unaprijed zna koje vrste i koju veličinu trgovina prima (AVIF na starim trgovinama, granica 20 MB)? | Dodati u `/info` objekt `media` s poljima `upload_mime_types` (popis) i `upload_max_bytes` (= `min(WordPress, 20 MB)`). To je dodavanje polja, bez promjene `api_version`. Postojeće `max_upload_bytes` ostaje. |
| P8 | Slike golemih dimenzija mogu potrošiti svu memoriju pri izradi umanjenih slika. | Odbiti iznad 64 megapiksela (npr. 8000 × 8000) s `bulkbird_image_too_large`. Bez ograničenja je druga mogućnost, uz rizik pada zahtjeva. |
| P9 | Ako trgovina sama zabrani neku vrstu (filter `upload_mimes`) | Poštovati trgovinu: dopušteno = popis iz specifikacije ∩ ono što trgovina dopušta. |
| P10 | Najveća duljina `title` i `alt` | 255 i 1000 znakova; dulje je 400. |
| P11 | Dva istovremena zahtjeva s istim `client_upload_id` | Zaključavanje po UUID-u u bazi (drugi zahtjev čeka do 10 s, zatim dobije postojeću stavku s 200). Ako prvi još traje nakon 10 s: 409 `bulkbird_upload_in_progress` s `Retry-After: 5`. |
| P12 | Isti `client_upload_id`, a druga datoteka | 409 `bulkbird_upload_id_conflict`. Za to plugin uz privitak sprema i SHA-256 sadržaja (`_bulkbird_upload_sha256`). Jednostavnija mogućnost: uvijek vratiti postojeću stavku s 200, bez usporedbe sadržaja. |
| P13 | Isti `client_upload_id` nakon što je slika obrisana ili je u smeću | Trajno obrisana: 201, nova slika. U smeću: 409 `bulkbird_upload_trashed` (ne vraćati iz smeća i ne stvarati drugu). |
| P14 | Treba li i `GET /media/<id>` (jedna stavka)? Nije u specifikaciji. | Ne u ovom paketu; Bulkbird stavku ima u odgovoru `POST`-a, a popis kroz `GET /media`. |
| P15 | Ograničenje učestalosti za zahtjeve bez prijave | Ne brojati (nema korisnika; brojanje po IP adresi značilo bi spremanje osobnog podatka). |
| P16 | Gdje držati brojače učestalosti | Mala vlastita tablica `{prefix}bulkbird_rate` (korisnik, skupina, početak prozora, broj) s jednim atomskim upitom po zahtjevu; ako trgovina ima trajni object cache, koristi se on i tablica se ne dira. Jednostavnija mogućnost: WordPress transijenti (dva upisa po zahtjevu i netočno brojanje pri istovremenim zahtjevima). |
| P17 | Testovi prijenosa ostavljaju slike na testnim stranicama, a plugin ih ne briše. | Sve testne slike imaju naslov koji počinje s `bbh-test-`. Tražim trajno odobrenje da ih testovi nakon sebe brišu kroz WP-CLI (samo privitke s tim prefiksom i s `_bulkbird_upload_id`), na obje testne stranice. Inače se nakon svakog kruga testova vraća polazna kopija, na tvoj zahtjev. |
| P18 | Prozor ograničenja | Fiksni prozor od 60 s (jednostavno i jeftino) umjesto kliznog; u najgorem slučaju propušta do dvostruke vrijednosti na prijelazu dvaju prozora. |
| P19 | Ograničenja 120 / 30 u minuti | Kako piše u specifikaciji; trgovina ih može promijeniti filterom `bulkbird_helper_rate_limits`. |
| P20 | Mjerenje za H1.46 i H1.44 traži mjerni mu-plugin na obje stranice. | Tražit ću odobrenje kad dođe red, kao i dosad. |
