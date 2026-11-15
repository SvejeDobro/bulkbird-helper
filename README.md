# Bulkbird Helper

Mali, neobavezan WordPress plugin koji programu Bulkbird daje ono što WooCommerce REST API ne nudi:
pregled cijele medijske biblioteke, prijenos slika, dnevnik promjena proizvoda (uključujući obrisane)
i zbirnu prodaju izračunatu na serveru. Bulkbird radi i bez njega; plugin samo otključava dodatne mogućnosti.

Načela: bez sučelja, nula CSS-a i JS-a, nula opterećenja stranica za kupce, ništa ne briše u trgovini,
bez osobnih podataka u odgovorima, autentikacija postojećim WooCommerce REST ključem.

**Status:** `0.1.0` (faza 0: autentikacija i `GET /info`) i `0.2.0` (faze 1 i 2: `GET` i `POST /media`,
ograničenje učestalosti) su izvedeni, na grani `main`, s oznakama `v0.1.0` i `v0.2.0`. Tekuća verzija je `0.3.0` (dodaje `GET /media/uploads/<client_upload_id>`, provjeru ishoda prijenosa čitanjem), aktivna na
obje testne stranice. Faze 3 i 4 čekaju odluku vlasnika.

Provjere (sve se pokreću i u CI-ju):

```
composer check    # sintaksa na PHP 8.1–8.4, PHPCS (WordPress standard), PHPStan razina 8, jedinični testovi, čuvari
composer faults   # namjerni kvarovi: svaki mora srušiti provjere
composer e2e      # stvarni HTTP zahtjevi prema testnoj stranici (BBH_TARGET=NEW|OLD; treba test.env)
bin/build-zip.sh v0.1.0          # paket iz oznake + SHA-256
bin/deploy.sh v0.1.0 new|old     # postavljanje samo iz oznake; ne aktivira plugin
```

| Dokument | Sadržaj |
|---|---|
| [docs/spec-v1.md](docs/spec-v1.md) | specifikacija v1 |
| [docs/scenarios-faza0.md](docs/scenarios-faza0.md) | scenariji faze 0 (H0.1–H0.91) i odluke |
| [docs/scenarios-faza1-2.md](docs/scenarios-faza1-2.md) | scenariji faza 1 i 2 (`GET` i `POST /media`) — na potvrdu |
| [docs/measurements/faza0.md](docs/measurements/faza0.md) | mjerenje učinka, faza 0 |
| [docs/api-v1.md](docs/api-v1.md) | ugovor API-ja v1 (jedini izvor istine za Bulkbird stranu) |
| [CLAUDE.md](CLAUDE.md) | pravila rada u ovom repozitoriju |

Najniže verzije: WordPress 6.4, WooCommerce 8.0, PHP 8.1. Licenca: GPLv2 ili novija.
