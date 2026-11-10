# Vault eksploatavimas

## Aplinkos ir duomenys

`VAULT_ENV=local|test|stage|prod` parenka tapatybes ir konteinerius. Local duomenys yra `data/`, test – `app/.generated/test/data/`, stage/prod operatoriaus komandos numato `data/<env>/`. Serverio DDE `PROJECT_DATA_DIRECTORY` turi sutapti su šiuo keliu. `bin/vault install` automatiškai paruošia tik local/test. Stage/prod: paruoškite privatų DDE env pagal serverio setup, vykdykite `VAULT_ENV=prod bin/vault prepare`, `make -C app build ENV=prod`, `make -C app up ENV=prod`, Composer install konteineryje, `VAULT_ENV=prod bin/vault initialize` ir `php artisan migrate --force` PHP konteineryje. Docroot yra `/var/www/html/public`, ne Laravel projekto šaknis.

Privatūs aplinkų failai, runtime duomenys, atkūrimo raktai ir kopijos neversijuojami. `prepare` sukuria Laravel rašomus katalogus. Konteineriai naudoja host UID/GID; konteinerio paleidimo vartotojas turi galėti perskaityti 0600 failus. Išoriniams adresams privalomas galiojantis HTTPS sertifikatas; tarp servisų naudojamas privatus Docker tinklas. OpenBao ir brokeris neturi publikuotų portų. OpenBao po restarto gali būti užrakintas: `bin/vault initialize` atrakina originaliomis dalimis, jų nespausdindamas.

OpenBao AppRole token TTL – 5 min; WEB rašo/skaityto įrašus bei TOTP, brokeris tik skaito įrašus ir valdomas DB roles. Root token ir unseal dalys laikomi `~/.config/vault/<env>/openbao.json`, **ne konteinerių mount'uose**. Produkcijoje šią medžiagą laikykite saugiame operatoriaus kanale / offline kopijoje; turintis ją gali perimti Vault. Automatiniam maintenance operatoriaus host reikia jos prieigos. Visišką agento host administravimo teisę suteikti kitame host, ne čia.

## Kopija ir tikras atkūrimas

```bash
VAULT_ENV=local bin/vault backup
VAULT_ENV=local bin/vault restore-verify backups/<kopija>.vault
```

Kopija atsisako pradėti esant aktyvioms operacijoms, įjungia maintenance ir sustabdo PHP/brokerį, tada surenka SQL, OpenBao raft snapshot, aplikacijos raktą, AppRole ir connector konfigūraciją. Operacijos dar kartą patikrinamos po sustabdymo. Sodium secretstream autentifikuoja visą srautą, įskaitant pabaigą. Pasibaigus bandymui PHP/brokeris paleidžiami. Tai trumpa planinė WEB pertrauka; nepavykus komandai patikrinkite servisų būseną ir `artisan up`.

Atskirai nuo `.vault` kopijos būtina išsaugoti **abu**:

- `~/.config/vault/<env>/backup.key` – kopijos šifravimo raktas;
- `~/.config/vault/<env>/openbao.json` – originalios OpenBao atkūrimo dalys.

Be jų kopija neatkuriama. `restore-verify` naudoja atskirą OpenBao be tinklo ir laikiną SQL DB, patikrina įrašų versijas, MFA ir atkurtų agentų prieigos atšaukimą, po to išvalo fixture. Gyvi duomenys nekeičiami.

Tikram atkūrimui paruoškite **tuščios DB ir dar neinicijuoto OpenBao** konteinerius su tos pačios aplinkos vardu, tuo pačiu kodu ir Composer priklausomybėmis. Vykdykite `prepare` bei DDE build/up, tačiau **nevykdykite `initialize`, `install` ar migracijų**. Į originalią privataus operatoriaus vietą atskirai perkelkite abu atkūrimo failus, tada:

```bash
VAULT_ENV=local bin/vault restore /privatus/kelias/kopija.vault
```

Komanda atsisako perrašyti bet kokią esamą DB schemą arba inicijuotą OpenBao. Atkuria SQL, snapshot ir konfigūraciją, išvalo sesijas/cache, atšaukia visus machine token’us ir grants. Savininkų slaptažodžiai ir TOTP išlieka; agentams išduokite naujas tapatybes ir leidimus. Nesėkmės metu tikslas lieka sustabdytas, kad dalinis atkūrimas nebūtų naudojamas. Taisykite tuščią tikslą arba pradėkite naujame; nėra `--force` esamam Vault perrašyti. `tests/restore_empty.py` realiai patikrina šią eigą disposable konteineriuose ir laikinoje DB.

DDE lentelių registre visos lentelės `empty skip`: bendri dataset ir SQL eksportai sąmoningai neperkelia tapatybių, katalogo ar audito. **Vault atkūrimui naudokite tik pilną šifruotą Vault kopiją**, o ne atskirą SQL dump. Schemos snapshot versijuojamas `app/database/schema/`; schemą keičia Laravel migracijos (`DATABASE_MIGRATIONS=off` yra DDE migracijų, ne Laravel išjungimas).

## Rotacija, prieigos ir priežiūra

```bash
bin/vault rotate-service-credentials
bin/vault rotate-encryption-key
bin/vault maintenance
```

Pirma komanda išduoda naujus AppRole SecretID ir atšaukia senus; jau išduoti token’ai baigs galioti per 5 min. Antra rotuoja OpenBao šifravimo raktą, išlaikydama senų įrašų skaitomumą. `maintenance` pašalina pasibaigusių sesijų/cache būsenas, pažymi pasibaigusias operacijas ir kas 30 dienų vykdo abi rotacijas. Pavyzdinis systemd laikmatis yra `ops/`: kopijuojant nurodykite absoliutų checkout kelią ir `VAULT_ENV`; įjunkite operatoriaus user systemd. Timer neįjungiamas vien klonuojant repozitoriją.

`VAULT_ENV=test bin/vault database` provisionina sintetinį MySQL `vault-probe` dinaminį prisijungimą (tik SELECT `vault_probe`, max 60 s). Tai nėra teisė skaityti Vault katalogo DB. Brokeris po veiksmo atšaukia lease token’ą; avarijos atveju lieka OpenBao TTL. Išoriniam DB reikia atskirai operatoriaus sukonfigūruoto OpenBao connection/role ir tinklo politikos; neplėskite `vault_lease_admin` teisių į katalogą.

Praradus savininko prieigą, patikimas operatorius gali vykdyti `bin/vault recover-user owner@example.com`. Naujas atsitiktinis slaptažodis įrašomas tik į `~/.config/vault/<env>/recovered-user.json`; senos WEB sesijos atšaukiamos, kitą kartą būtinas naujas MFA užregistravimas. Žmogus perskaito failą tiesiai, prisijungia, WEB pasikeičia slaptažodį ir pašalina laikino atkūrimo failą. Nesiųskite jo LLM. Tai operatoriaus galia, ne naudotojui prieinamas MFA apėjimas.

WEB archyvavimas atšaukia grants. Grąžinimas iš archyvo jų neatnaujina. Sunaikinimas leidžiamas tik owner, tik archyvuotam įrašui ir įrašius DELETE; jis pašalina visas veikiančio backend versijas, tačiau anksčiau padarytoms kopijoms taikoma atskira jų saugojimo politika. Metaduomenų ir audito istorija lieka. Istorinė versija grąžinama kaip nauja CAS versija. Išorinio API rakto ar svetainės slaptažodžio tai automatiškai nekeičia.

## Atnaujinimas ir incidentas

Prieš atnaujinimą sukurkite ir patikrinkite kopiją. Įdiekite užrakintas Composer priklausomybes, perkurkite brokerio image, pritaikykite Laravel migracijas, paleiskite sveikatos ir testines patikras. Seccomp failo turinio pakeitimas reikalauja perkurti brokerio konteinerį; vien Compose path nepasikeičia. `broker/seccomp.json` remiasi Moby profiles default, commit `6fe7deb1b9fb7c0397a4593480d7d22b9ee8caef`, pridėta clone/setns/unshare/chroot Chromium user namespace sandbox; sandbox išjungti negalima.

Įtarus nuotėkį: WEB atšaukite machine/grants, sustabdykite brokerį, pakeiskite tikslinių sistemų reikšmes bei AppRole, patikrinkite auditą ir host prieigas. Auditą saugo SQL; jis nepriklausomas nuo slaptų reikšmių, tačiau nėra apsaugotas nuo paties host/DB administratoriaus pakeitimų. Serverio sesijos išorinėje svetainėje gali išlikti iki jos TTL net uždarius vietinį Chromium kontekstą.
