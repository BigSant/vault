# Vault

Asmeninė prisijungimų saugykla su žmogui skirta WEB peržiūra ir kontroliuojamu prisijungimų naudojimu iš LLM įrankių.

**Dabartinė būsena: paruošimo etapas.** Veikia Docker kūrimo aplinka, pradinis informacinis puslapis ir DB pasiekiamumo patikra. Slaptažodžių saugojimas, autentifikacija, WEB valdymas ir LLM įrankiai dar neįgyvendinti. Tikrų prisijungimų į šį pagrindą nekelti.

## Paleidimas

Naudojamas bendras [setup](https://github.com/BigSant/DockerDevelopmentEnvironment), kaip `cliproxy` ir `zarskis` projektuose. Reikia veikiančio Docker ir vieną kartą pagal setup instrukcijas paruošto kompiuterio.

```bash
git clone git@github.com:BigSant/vault.git ~/Projects/vault
cd ~/Projects/vault/app
make setup
```

Setup turi būti `~/Projects/setup`; kitur esantį galima nurodyti `make setup SETUP_DIRECTORY=/kelias/iki/setup`. Bootstrap sugeneruoja vietinius DB slaptažodžius, pasirenka laisvus portus, paruošia DNS ir TLS. Pakartotinis paleidimas išsaugo duomenis. `make setup` neimportuoja DB ir nekuria aplikacijos lentelių.

- [Vietinis puslapis](https://vault.local/)
- [Parengties patikra](https://vault.local/health.php): HTTP 200 tik kai pasiekiama DB ir įkeltas `sodium`; kitu atveju HTTP 503 be klaidos detalių. Tai infrastruktūros, ne būsimos saugyklos veikimo patikra.

```bash
make -C app doctor
make -C app ps
php tests/health-unavailable.php
```

`doctor` yra bendro setup infrastruktūros patikra, ne Vault saugumo auditas. Privatūs `app/env/local.env`, `app/.generated/` ir `data/` neversijuojami. `local.env.example` turi bootstrap pakeičiamą žymeklį, ne bendrą slaptažodį. Projekto Docker portai publikuojami tik į `127.0.0.1`; kompiuterio Nginx maršruto pasiekiamumą papildomai lemia host konfigūracija ir ugniasienė.

## Struktūra

```text
app/
  Makefile                 bendro setup komandos
  env/                     bendri nustatymai ir privataus env pavyzdys
  config/php/              PHP nustatymų papildymas
  public/                  pradinis puslapis ir health.php
docs/architecture.md       siūlomas duomenų modelis, WEB ir LLM veikimo sutartis
research/secret-management.md  oficialių sistemų dokumentacijos tyrimas
tests/                     parengties klaidos atsakymo patikra
```

Pagrindas: PHP 8.5, MySQL 8.4, Apache ir nginx-proxy. Atskiras Compose ar nukopijuotas CORE Dockerfile nereikalingas: juos parenka bendras setup. Papildomų servisų nėra; cache ir Xdebug išjungti. `DATABASE_AS_CODE=off`, kol nėra aplikacijos schemos. Pirma migracija turi kartu įjungti sutartą schemos apskaitą.

CI tikrina PHP sintaksę ir saugų parengties klaidos atsakymą. Pilnas Docker paleidimas tikrinamas vietoje. Produkcinis deploy šiuo etapu nekonfigūruotas.

## Tolesnis įgyvendinimas

Pradėti nuo [architektūros pasiūlymo](docs/architecture.md) ir [tyrimo](research/secret-management.md). Pagrindinis principas: LLM gauna įrašo nuorodą ir leidžiamo veiksmo rezultatą; tikrą prisijungimą panaudoja atskirtas vykdymo modulis. Vien `get_secret` įrankis ar rezultatų maskavimas šio reikalavimo neįvykdo.
