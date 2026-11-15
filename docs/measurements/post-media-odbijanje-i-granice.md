# `POST /media`: kad stiže odbijanje i koja je stvarna granica veličine

16. 11. 2026. · teststore1.bulkbird.app · plugin `0.2.0` · pitanja Bulkbird strane za paket 2b (slike).
Ugovor (`docs/api-v1.md`) nije mijenjan. Na trgovini nije ništa mijenjano; poslano je 12 malih zahtjeva
(najviše 300 kB), svi odbijeni, bez ijedne spremljene slike. Prevelike datoteke nisu slane.

## 1. Dolazi li 401/403 prije nego što se primi tijelo zahtjeva?

**Ne.** Odbijanje stiže tek nakon što je cijelo tijelo preneseno. Plugin na to ne može utjecati.

Zašto (čitanjem koda i lanca): PHP počinje izvršavati WordPress tek kad je poslužitelj ispred njega primio
cijelo tijelo, a PHP ga raščlanio u privremenu datoteku. Provjera ključa (WooCommerce) i provjera prava
(plugin) su PHP kod, pa se ne mogu izvršiti ranije. Kad odbijanje nastupi, PHP privremenu datoteku sam
obriše; plugin je ne otvara, ne računa sažetak i ništa ne sprema.

Mjerenje: tijelo od 300 kB poslano brzinom ograničenom na 100 kB/s.

| Zahtjev | Odgovor | Vrijeme do odgovora | Poslano |
|---|---|---|---|
| bez prijave | 401 `bulkbird_unauthorized` | 2,93 s | 300 359 B |
| ključ samo za čitanje | 401 `woocommerce_rest_authentication_error` | 2,93 s | 300 359 B |
| ključ korisnika bez prava | 403 `bulkbird_forbidden` | 2,93 s | 300 359 B |
| bez prijave, uz `Expect: 100-continue` (HTTP/1.1) | 401 `bulkbird_unauthorized` | 2,93 s | 300 359 B |

Isti zahtjevi s tijelom od 360 B vraćaju odgovor za 0,16–0,38 s. Dakle odgovor čeka kraj prijenosa, a
`Expect: 100-continue` ne pomaže (poslužitelj ispred WordPressa sam odobri slanje, ne pitajući WordPress).

**Što se može bez izmjene ugovora — na strani klijenta:**

- **Provjera prava zahtjevom bez tijela.** `POST /media` bez tijela, istim ključem, stoji oko 0,2 s i ništa ne sprema:
  - 400 `rest_missing_callback_param` → ključ smije pisati i korisnik ima obje sposobnosti;
  - 401 `woocommerce_rest_authentication_error` → ključ je samo za čitanje (ili je neispravan);
  - 403 `bulkbird_forbidden` → korisniku nedostaje `upload_files` ili `manage_woocommerce`;
  - 401 `bulkbird_unauthorized` → ključ nije prepoznat.
  Dovoljno je jednom po sesiji, prije prve slike. Odgovori 401 i 403 se ne broje u ograničenje učestalosti;
  odgovor 400 se broji kao jedan zahtjev i kao jedan prijenos (od 30 u minuti).
- `GET /media?per_page=1` traži iste sposobnosti kao `POST`, ali ne otkriva je li ključ samo za čitanje.

**Na strani plugina:** ništa što bi skratilo prijenos. Jedino što je uočeno, a moglo bi se popraviti bez
izmjene ugovora (nije napravljeno; čeka odluku vlasnika):

- **Neispravan parametar ima prednost pred 401/403.** WordPress provjerava parametre putanje prije provjere
  prava. Zato zahtjev **bez prijave** ili **korisnika bez prava** s neispravnim `client_upload_id` dobiva
  400 `rest_invalid_param`, a ne 401/403 (izmjereno). Ključ samo za čitanje i dalje dobiva 401, jer to
  odbija WooCommerce ranije. Ništa se pritom ne otkriva osim pravila o obliku parametra, koje je javno u
  indeksu REST-a. Ako se želi da 401/403 uvijek ima prednost, provjera oblika parametara se može preseliti
  u kod iza provjere prava; kodovi i statusi ostaju isti.

## 2. 429 na `POST /media`: je li datoteka tada već primljena?

**Da.** Vrijedi isto kao u točki 1: brojač je PHP kod i izvršava se tek kad je cijelo tijelo primljeno.
Klijent plaća prijenos i za odbijeni zahtjev. Plugin datoteku pritom ne otvara i ništa ne sprema.

Što iz koda slijedi za klijenta:

- Brojač se povećava za svaki zahtjev koji prođe provjeru prava, **i kad je odbijen s 429**. Ponavljanje
  unutar istog prozora ne pomaže; treba čekati `Retry-After` (najviše 60 s).
- Prozor je fiksan (60 s) i po korisniku, zajednički za sve ključeve tog korisnika.
- U ograničenje od 30 prijenosa ulazi svaki `POST /media` koji prođe provjeru prava: uspješan, neuspješan
  (400, 409, 413, 415) i ponovljeni s istim `client_upload_id` (200).
- Najjeftinije je da klijent sam ne prelazi 30 `POST` zahtjeva u 60 s po trgovini (i 120 zahtjeva ukupno)
  te da nakon 429 ne šalje ništa do isteka `Retry-After`. Trgovina može ograničenja promijeniti filterom,
  pa je `Retry-After` mjerodavan, ne brojke.

Ovo nije mjereno slanjem (bilo bi potrebno 31 prijenos); slijedi iz koda (`rest_guard()` →
`Rate_Limiter::check()` u `permission_callback`) i iz mjerenja u točki 1, koje pokazuje da nijedan
`permission_callback` ne odgovara prije kraja prijenosa. Odgovor 429 na 31. prijenos pokrivaju testovi
stvarnim HTTP-om (H2.53).

## 3. Stvarna granica veličine na teststore1 (prema konfiguraciji, ne slanjem)

| Sloj | Granica | Izvor | Što klijent dobije iznad granice |
|---|---|---|---|
| Plugin | **20 971 520 B (20 MB) po datoteci**; uz to najviše 64 megapiksela | kod; `/info` → `media.upload_max_bytes` | 413 `bulkbird_file_too_large` (JSON), nakon cijelog prijenosa |
| PHP (FPM 8.3 ove stranice) | 67 108 864 B (64 MB): manja od `upload_max_filesize` i `post_max_size` | `/info` → `max_upload_bytes`, kako ga vidi PHP weba | 413 `bulkbird_file_too_large` (JSON plugina), ako zahtjev uopće stigne do PHP-a |
| nginx (vhost stranice) | **nepoznata točna vrijednost**: datoteka vhosta se ovim korisnikom ne može čitati. Sigurno je između 20 MB i 71 MB | zapis nginxa o odbijanju tijela od 71 303 653 B (raniji test H2.26); zahtjev s datotekom od točno 20 MB je prošao | 413 **bez JSON-a** (izmjereno ranije, H2.26) |
| Cloudflare | ovisi o planu zone (100 MB na Free i Pro); s poslužitelja se ne vidi | — (vlasnik može potvrditi u Cloudflare sučelju) | 413 bez JSON-a |

- **Stvarna granica za Bulkbird je granica plugina: 20 MB po datoteci.** Sve ostale granice su više.
  Klijent je čita iz `/info` (`media.upload_max_bytes`) i veću datoteku ne treba ni slati.
- PHP-ov `php.ini` navodi 100 MB, ali PHP weba vidi 64 MB: vrijednost dolazi iz postavki PHP-a stranice u
  CloudPanelu. Ostale vrijednosti koje vidi PHP weba: `memory_limit` 512 MB, `max_execution_time` 60 s.
  Iz `php.ini`: `max_input_time` 90 s, `max_file_uploads` 20.
- Tijelo zahtjeva je nešto veće od datoteke (omotnica `multipart/form-data`, nekoliko stotina bajtova), pa
  datoteka od točno 20 MB prolazi sve slojeve.
- Točnu vrijednost `client_max_body_size` može pročitati vlasnik u CloudPanelu (Vhost stranice). Za ugovor
  nije potrebna: ponašanje iznad nje je već opisano (413 bez JSON tijela).

## Usputni nalaz: nginx bilježi consumer key, a uz ključ u adresi i secret

Pri čitanju zapisa nginxa za točku 3 pokazalo se:

- nginx u `access.log` kao korisničko ime bilježi **consumer key** svakog zahtjeva s Basic autentikacijom
  (to vrijedi za sve klijente, i za Bulkbird program). Secret se pritom ne bilježi.
- Kad se ključ pošalje **u adresi** (`?consumer_key=…&consumer_secret=…`), u `access.log` ulazi i **secret**.
  To rade dva testa stvarnim HTTP-om (H0.3 i jedan u poglavlju `/media`), pa je secret testnog ključa samo
  za čitanje u zapisu nginxa: 9 redaka na teststore1 i 13 na teststoreold.
- Preporuka za Bulkbird stranu (ugovor to već dopušta): ključ slati samo u zaglavlju, nikad u adresi.

Vrijednosti ključeva nisu zapisane u ovaj dokument.
