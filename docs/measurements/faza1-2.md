# Mjerenje — paket 0.2.0 (faze 1 i 2), scenariji H1.44, H1.45, H1.46

15. 11. 2026. · plugin `0.2.0-rc.3` (kod jednak verziji `0.2.0`, osim broja verzije) · mjerni mu-plugin
postavljen uz odobrenje vlasnika samo za vrijeme mjerenja i odmah uklonjen s obje stranice.
30 zahtjeva po retku; „PHP ms” je medijan vremena na serveru (od početka zahtjeva do kraja PHP-a).
Biblioteka na obje stranice: 716 slika.

## H1.44 i H1.45 — `GET /media` (cilj < 300 ms na serveru)

| Zahtjev | Stranica | Upiti | PHP ms (medijan) | Kroz Cloudflare (medijan, samo zapis) |
|---|---|---|---|---|
| `GET /media?per_page=100` | teststore1 (WP 7.1.4, WC 11.3.0, PHP 8.3) | 51 | **116** | 184 ms |
| `GET /media?per_page=100&search=a` | teststore1 | 51 | **122** | 186 ms |
| `GET /info` | teststore1 | 51 | 98 | 160 ms |
| `GET /media?per_page=100` | teststoreold (WP 6.4.14, WC 8.0.3, PHP 8.1) | 73 | **143** | 200 ms |
| `GET /media?per_page=100&search=a` | teststoreold | 73 | **149** | 213 ms |
| `GET /info` | teststoreold | 73 | 126 | 194 ms |

- Oba cilja su ispunjena na obje stranice. Najveća stranica popisa (100 stavki) stoji oko 18 ms više od `/info`.
- Broj upita je jednak za `/info` i za `/media` sa 100 stavki: popis ne radi upit po stavci (H1.43).
- `/info` sada radi 2 upita više nego u `0.1.0` (51 umjesto 49, odnosno 73 umjesto 71): upis i čitanje
  brojača učestalosti. Vrijeme `/info` je ostalo isto (98 prema 97–101 ms; 126 prema 124–129 ms).
- Pretraga `search=a` je najgori slučaj na ovoj biblioteci (pogađa većinu slika). Ograničenje ostaje
  dokumentirano: pretraga po dijelu teksta ne koristi indeks, pa na bibliotekama s desecima tisuća slika
  može biti sporija; to ovdje nije mjereno.

## H1.46 — stranice za kupce i tuđi REST zahtjevi

Plugin je aktivan cijelo vrijeme (nije deaktiviran za ovo mjerenje), pa je usporedba s brojkama
„plugin aktivan” i „bez plugina” iz faze 0 (`faza0.md`), izmjerenima istim alatom na istim stranicama.

| Stranica | Tko | teststore1: upiti 0.2.0 (faza 0 bez plugina) | datoteke plugina | PHP ms | teststoreold: upiti 0.2.0 (faza 0 bez plugina) | datoteke plugina | PHP ms |
|---|---|---|---|---|---|---|---|
| Naslovnica | neprijavljen | 54 (54) | samo glavna | 116,1 | 136 (136) | samo glavna | 134,6 |
| Naslovnica | Shop manager | 80, jednom 85 (80) | samo glavna | 144,4 | 142–151 | samo glavna | 139,0 |
| Trgovina | neprijavljen | 54 (54) | samo glavna | 115,5 | 136 (136) | samo glavna | 133,6 |
| Trgovina | Shop manager | 80 (80) | samo glavna | 145,6 | 142 | samo glavna | 136,9 |
| Proizvod | neprijavljen | 51, jednom 57 (51) | samo glavna | 110,7 | 139 (139) | samo glavna | 163,5 |
| Proizvod | Shop manager | 113 (113) | samo glavna | 189,6 | 145–148 | samo glavna | 166,2 |
| Košarica | neprijavljen | 48 (48) | samo glavna | 111,6 | 68 (68) | samo glavna | 97,1 |
| Košarica | Shop manager | 98–112 (98, jednom 105) | samo glavna | 164,1 | 74 | samo glavna | 98,9 |
| `GET /wc/v3/products` | ključ | 60 (60) | samo glavna | 80,7 | 83 (83) | samo glavna | 132,4 |

- **0 dodatnih upita:** uobičajeni broj upita jednak je onome bez plugina na svakoj stranici za koju postoji
  usporedba. Pojedinačna odstupanja kod prijavljenog korisnika (košarica, naslovnica) javljala su se i u fazi 0,
  prije aktivacije plugina.
- **Samo glavna datoteka:** na stranicama za kupce i na tuđem REST zahtjevu učitava se jedino
  `bulkbird-helper.php`; ukupan broj PHP datoteka jednak je onome iz faze 0 s aktivnim pluginom
  (npr. 1476, 1505, 1449 na teststore1; 1237, 1229 na teststoreold). Klase za `/media` i brojač učestalosti
  se učitavaju samo na zahtjevima prema `bulkbird/v1`.
- **Brojač učestalosti se ne upisuje izvan putanja plugina:** tuđi REST zahtjev ima isti broj upita kao bez plugina.
- Stara stranica u fazi 0 nije mjerena s kolačićem Shop managera, pa za te retke nema usporedbe; i ondje se
  učitava samo glavna datoteka.
- Vremena su unutar šuma mjerenja iz faze 0 (razlika dvaju mjerenja istog stanja ondje je bila do 6 ms).

## Ograničenja ovog mjerenja

- Jedno mjerenje po stranici (30 zahtjeva), bez izmjeničnog redoslijeda aktivan/neaktivan; za vrijeme
  se zato oslanja na usporedbu s fazom 0, ne na izravan par.
- Vrijeme `POST /media` nije mjereno (ovisi o veličini slike i izradi umanjenih slika u WordPressu).
