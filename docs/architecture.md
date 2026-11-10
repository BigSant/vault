# Vault architektūra

Įgyvendinta 2026-11-10 pagal [oficialių sistemų tyrimą](../research/secret-management.md). Tikslas – žmogui patogi saugykla ir LLM prieinami konkretūs veiksmai, kurių argumentuose bei rezultatuose nėra prisijungimo reikšmių. API/CLI ir naršyklė turi vienodą prioritetą. Pagrindinis klientas – ai-os.

## Duomenų modelis

**Workspace → projektas → aplinka → įrašas.** Tipas (`login`, `api_key`, `database`, `dynamic_database`, `ssh`, `secure_note`) ir žymos yra atributai bei filtrai. Projektas automatiškai gauna local/test/stage/prod; galima pridėti savo aplinkas. `shared` galima naudoti bendriems įrašams, bet tarp aplinkų nėra jokio fallback ar leidimų paveldėjimo.

MySQL laiko tapatybes, slaptažodžių Argon2id hash, struktūrą, įrašo UUID, alias, tipą, versijos nuorodą, būseną, datas, grants, operacijas ir auditą. OpenBao KV v2 laiko tikrą tipizuotą JSON turinį bei savininkų TOTP seed. Įrašai rašomi CAS būdu, saugoma iki 20 versijų. Duomenų bazėje nėra plaintext paslapčių indekso. Alias yra aiškiai agentui patvirtintas tekstas; pavadinimas, naudotojo vardas ir tiksliniai adresai agentui negrąžinami.

Owner valdo narius, tapatybes ir politiką; editor kuria, keičia ir atskleidžia įrašus; viewer mato metaduomenis. Kiekvienas serverio veiksmas iš naujo patikrina darbo sritį. Paskutinio owner pašalinti negalima. Žmogaus ir agento autentifikacija atskira: cookie + slaptažodis + TOTP, arba ribotas bearer token hash.

## Moduliai ir eiga

```text
Žmogaus WEB → Identity / Access / Catalog → OpenBao (reikšmės)
                                      └→ MySQL (struktūra, auditas)
LLM → ai-os patikimas backend / MCP → Operations → brokeris
                                    ↑              ├→ HTTPS API
                           reautorizacija          ├→ fiksuotas CLI receptas
                                                   ├→ Chromium + žmogaus MFA
                                                   └→ dinaminė DB lease
```

- `Secrets` slepia OpenBao AppRole, CAS ir versijų skaitymą. WEB sesijos šifruotos aplikacijos raktu.
- `Catalog` valdo tipizuotą turinį, paiešką, versijų grąžinimą, importą, archyvą ir sunaikinimą. Backend write ir SQL nėra viena paskirstyta transakcija: nesėkmė gali palikti nepasiekiamą backend orphan; nėra plaintext fallback.
- `Operations` susieja machine, entry, action, versiją, konkretų patvirtinimą ir penkių minučių terminą. `request_id` užtikrina idempotency vienos tapatybės ribose. Dispatch claim įrašomas prieš vykdymą: avarija tame intervale baigiasi saugia nesėkme, o ne pakartotu išoriniu veiksmu.
- Brokeris dar kartą tikrina leidimą prieš gavęs reikšmę, prieš naršyklės tinklo užklausas ir kas sekundę aktyvaus darbo metu. Nėra pažado atšaukti jau užbaigtą išorinį veiksmą. Atsakymai – tik serverio sugeneruotos būsenos/boolean.
- Auditas fiksuoja veiksmą, subjektą, aktorių, laiką ir baigtį. Reikšmės, HTTP payload, MFA, exception argumentai ir brokerio stdout/stderr nefiksuojami.

Būsenos: `pending` → `approved` → `running` → `requires_user_action` / `succeeded` / `failed` / `expired` / `revoked`. Naršyklės MFA teikia žmogus Vault WEB; kodas niekada neperduodamas agentui. Operacijos ID nėra teisė – būseną gauna tik ją sukūrusi machine tapatybė. ai-os papildomai pasirašo handle darbo srities, agento, projekto ir ticket kontekstu.

## Izoliacija

Brokeris prijungtas tik prie `internal` Docker tinklo. Egress proxy atskirai turi išorinį tinklą; leidžia tik operatoriaus registruotus host/port, tikrina visus DNS adresus ir jungiasi prie patikrinto IP. Privatūs adresai turi turėti aiškų CIDR leidimą. API/CLI neseka redirect; naršyklė leidžia tik registruotus origins, blokuoja WebSocket ir service workers. HTTP fixture išimtis egzistuoja tik test Compose aplinkoje. Išoriniams tikslams naudojamas HTTPS.

Chromium paleidžiamas su user namespace sandbox, cap-drop ALL, no-new-privileges, ribotais procesais ir atmintimi. Kiekvienai operacijai naujas kontekstas, baigiant ar atšaukiant jis uždaromas. Agentas negauna browser valdymo, DOM, cookies, screenshots, HAR, trace ar CDP. Brokerio fiksuotas CLI vykdomas jo paties patikimame procese; modelis negali nurodyti shell, programos, URL, headers ar kodo.

Tai nėra zero-knowledge sistema. WEB ir patikimas vykdytojas turi mokėti perskaityti reikšmes. Agentas su host/root/Docker ar backend kodo keitimo teisėmis šią ribą gali apeiti. Produkcinę ribą sudaro atskiras operatoriaus host/VM ir agento sandbox, o ne vien katalogo vardas ar maskavimo regex. Vidinis OpenBao HTTP ir MySQL srautas laikomas patikimo host tinklu; kelių host diegimui reikia atskiro TLS ir tinklo projekto.

## WEB

Lietuviška responsive sąsaja: darbo srities pasirinkimas, paieška, projekto/aplinkos/tipo/būsenos filtrai, puslapiuojamas sąrašas ir atskiras detalės vaizdas. Prod turi tekstinę žymą. Slapti duomenys neatsiunčiami pradiniame detalės puslapyje; atskleidžiami atskira POST užklausa po penkių minučių galiojimo slaptažodžio patvirtinimo ir audito. Po 30 s paslepiami. Clipboard yra žmogaus OS funkcija – jo istorijos išvalymo negarantuojame.

TOTP apsaugotas nuo to paties laiko kodo pakartojimo. Sesijos idle terminas 20 min, absoliutus – 8 h. Naudojami HttpOnly/Secure/SameSite strict cookies, CSRF, CSP, no-store, rate limits ir bendri klaidų pranešimai. Mašinos tapatybė nesuteikia WEB reveal prieigos. Sunaikinant reikalaujama aiškaus DELETE patvirtinimo, kopijų retencija lieka atskira.

## Etapai ir patikros

| Etapas | Įgyvendinimas | Įrodymas |
|---|---|---|
| Docker ir tyrimas | bendras DDE, vietinis HTTPS, oficialių sistemų tyrimas | `health.php`, setup patikros |
| Savininko saugykla | MFA, versijuotas backend, CRUD, paieška, auditas, kopijos/atkūrimas | PHPUnit, realus HTTPS e2e, isolated ir empty-target restore |
| Abi LLM kryptys | MCP/API, fiksuotas CLI, naršyklė su MFA, allowlist ir grants | sintetinis echo, redirect, uždrausta paskirtis, MFA cancel |
| Plėtra | keli savininkai, importas, laikinos teisės, dinaminė DB, valdomų raktų rotacija | application testai, DB lease e2e, operatoriaus komandos |
| ai-os | patikimo backend integracija, scope config, ticket handle, išjungtas plaintext kelias įjungus external mode | izoliuotas kliento kontrakto testas ir native MCP testai |

Nuotolinių tikrų paskyrų konfigūravimas priklauso nuo operatoriaus įvestų adresų ir reikšmių. Tokio prisijungimo nelaikome patikrintu vien todėl, kad veikia fixture. Automatinė bet kokios svetainės slaptažodžio rotacija, CAPTCHA/WebAuthn bei laisvas naršymas nėra pažadėti šios versijos veiksmai. Tolimesni connector papildymai turi išlaikyti tą pačią reikšmių neišleidimo sutartį ir turėti savo neigiamus testus.
