# Vault

Prisijungimų saugykla su lietuviška WEB sąsaja ir veiksmų įrankiais LLM klientams, pirmiausia **ai-os**. Žmogus peržiūri reikšmes po autentifikacijos; agentas gauna tik jam leistinus alias, veiksmų pavadinimus ir būsenas. Slaptažodį panaudoja atskiras vykdytojas.

Veikia workspace → projektas → aplinka struktūra, tipai ir žymos, paieška, versijos, importas, archyvas, sunaikinimas, keli savininkai, MFA, atskleidimo auditas, laikinos agentų teisės, žmogaus patvirtinimai, API, fiksuotas CLI receptas ir izoliuotos naršyklės prisijungimas su MFA. OpenBao saugo šifruotas reikšmes; MySQL – metaduomenis. Kopijos šifruojamos ir atkuriamos atskiroje aplinkoje.

## Paleidimas

Reikia Docker, Python 3.12+ ir bendro [setup](https://github.com/BigSant/DockerDevelopmentEnvironment), paruošto kaip kituose `~/Projects` projektuose. PHP 8.5, MySQL 8.4, Apache ir nginx-proxy parenka setup; papildomi servisai yra OpenBao, brokeris ir paskirčių proxy.

```bash
cd ~/Projects/vault
bin/vault install
```

`install` paruošia privačius failus, Docker, Composer priklausomybes, OpenBao tapatybes ir Laravel migracijas. Pakartotinis paleidimas nekeičia esamų paslapčių. `SETUP_DIRECTORY` galima nurodyti kitam setup katalogui. `install` skirtas local/test; serverio eiga – [runbook](docs/operations.md).

Atidarykite **https://vault.local/**. Pirmam savininkui reikia aktyvacijos kodo iš privataus `data/security/app/bootstrap.code` failo. Jo turinį įveskite tiesiai WEB, ne pokalbyje. Susikurkite slaptažodį ir užregistruokite TOTP autentifikatorių. Parengties adresas: `https://vault.local/health.php` (200 tik kai pasiekiama DB ir OpenBao atrakintas).

1. Sukurkite projektą: automatiškai atsiras local, test, stage ir prod aplinkos.
2. Pridėkite įrašą ir pasirinkite tipą. Alias yra agentui matomas vardas; jame nelaikykite jautrių duomenų.
3. „Prieigos ir veiksmai“ sukurkite agento tapatybę bei leidimus konkretiems įrašams ir veiksmams. Numatytoji connector konfigūracija tuščia – agentas neturi jokių veiksmų.
4. Prijunkite [ai-os arba MCP/CLI](docs/integrations.md). Konkrečius išorinius adresus ir saugius receptus operatorius aprašo privačioje konfigūracijoje.

## Patikros

```bash
docker exec -w /var/www/html vault-local-php-fpm php vendor/bin/phpunit
python3 -m unittest discover -s tests -p 'test_*.py' -v
VAULT_ENV=test bin/vault install
VAULT_ENV=test bin/vault database
python3 tests/integration.py
VAULT_ENV=test bin/vault backup
VAULT_ENV=test bin/vault restore-verify backups/<test-kopija>.vault
python3 tests/restore_empty.py backups/<test-kopija>.vault
```

Integraciniai testai naudoja tik `vault.test.local`, sintetinius prisijungimus ir izoliuotą fixture. Jie tikrina realų HTTPS, CSRF, TOTP, OpenBao, API/CLI, trumpalaikę DB prieigą, naršyklės MFA, neleistiną paskirtį, slaptažodžio atkartojimą ir atšaukimą. Atskiros patikros tikrina šifruotos kopijos sugadinimą, DNS adresų tikrinimą, teises ir klientų atsakymus. Po kodo pakeitimo test aplinką atnaujina `make -C app test-init refresh=1`, tada `make -C app up ENV=test`; brokerio pakeitimams dar reikia `make -C app build`.

## Pasitikėjimo ribos

**LLM, turintis host/root, Docker socket ar galintis keisti vykdytojo kodą, gali pasiekti paslaptis.** Produkcijoje Vault ir patikimas ai-os backend turi veikti agento neadministruojamame host/VM; agento sandbox neturi gauti šių diskų, konfigūracijos ar owner WEB sesijos. Vietinis Docker yra kūrimo aplinka, ne šios ribos įrodymas.

Nėra `get_secret`, savavališko shell, HTTP proxy, DOM, cookies, CDP ar ekrano nuotraukų agentui. API ir CLI receptas tikrina registruoto HTTPS adreso 200 būseną; naršyklė prisijungia ir patikrina operatoriaus nustatytą paskyros būseną. Tai konkretūs naudingi veiksmai, ne universalus naršymas. Naujam veiksmui reikia operatoriaus aprašo ir jo rezultatų patikros. CAPTCHA, WebAuthn/SSO specialūs srautai, SSH vykdymas ir savavališkų išorinių paskyrų automatinis slaptažodžio keitimas nepalaikomi. SSH raktus ir slaptus užrašus galima saugoti bei peržiūrėti.

Automatinė rotacija taikoma valdomiems AppRole ir OpenBao šifravimo raktams per operatoriaus maintenance laikmatį; dinaminės DB prieigos galioja iki 60 s. Kitų sistemų reikšmes žmogus pakeičia toje sistemoje ir tada Vault. `rotate_at` yra priminimas, ne išorinio slaptažodžio pakeitimo garantija.

- [Architektūra ir priėmimo sutartis](docs/architecture.md)
- [Diegimas, kopijos, atkūrimas, rotacija](docs/operations.md)
- [ai-os, MCP/CLI ir connector sutartis](docs/integrations.md)
- [Oficialių 1Password, Bitwarden, Infisical ir OpenBao sistemų tyrimas](research/secret-management.md)
