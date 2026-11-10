# Prisijungimų saugykla žmonėms ir LLM: tyrimas ir siūloma kryptis

Patikrinta 2026-11-10. Kontekstas: `BigSant/vault`, planuojama PHP 8.5 ir MySQL 8.4 aplikacija. Tai dokumentacijos tyrimas ir architektūros pasiūlymas, ne įdiegtos sistemos saugumo auditas ar konkrečių versijų suderinamumo bandymas. Realios paslaptys neskaitytos, niekas nediegta.

**Pagrindinė rekomendacija:** kurti žmogui patogų paslapčių katalogą ir ribotų veiksmų brokerį virš patikimo paslapčių saugojimo backend. LLM gauna įrašų identifikatorius, leistinus metaduomenis ir veiksmų rezultatus; tik patikimas vykdytojas gauna tikrąją reikšmę. LLM skirtame API neturi būti `get_secret`, universalaus shell ar nevaržomo autentifikuoto HTTP tarpininko.

Naudotojas patikslino, kad **API/CLI ir naršyklės prisijungimai yra vienodai svarbūs**. Toliau pateiktos mūsų rekomendacijos suderintos su šiuo prioritetu: abi kryptys įtrauktos į pirmą LLM etapą. Konkreti vykdymo sutartis ir priėmimo scenarijai aprašyti [architektūros pasiūlyme](../docs/architecture.md). Tiekėjų dokumentacijos faktai dėl šio prioriteto nesikeičia.

Toliau skyriuje „Dokumentacijos faktai“ pateikti tiekėjų dokumentuoti mechanizmai. Likę skyriai yra **mūsų rekomendacijos ir grėsmių modelio išvados**, o ne šaltinių žadamos garantijos.

## Dokumentacijos faktai ir pritaikymas

Penkios pagrindinės oficialios nuorodos; papildomi patikrinti puslapiai prie atitinkamo punkto pagrindžia atskiras funkcijas.

### 1. 1Password: nuorodos ir `op run`

Šaltinis: [Load secrets into the environment](https://www.1password.dev/cli/secrets-environment-variables). Senasis `developer.1password.com/docs/cli/secrets-environment-variables/` tyrimo metu nukreipė į šį adresą.

**Faktai.** Secret reference identifikuoja vault, item, section ir field vardais arba ID; pavyzdinė forma yra `op://vault/item/field`. `op run` išsprendžia nuorodas ir paleidžia procesą su tikrosiomis reikšmėmis aplinkos kintamuosiuose. Dokumentacija perspėja: „You should assume that processes on your computer can access the environment of other processes run by the same user.“

**Mūsų pritaikymas.** Repo ir agento kontekste naudoti stabilius identifikatorius. Nuoroda yra adresas, ne leidimas: ją išsprendžiantis komponentas privalo patikrinti teises. `op run` tinkamas patikimai programai paleisti, tačiau LLM valdoma programa, turinti jos aplinką, gali perskaityti paslaptį. Tai nėra izoliacija nuo tos programos.

### 2. 1Password Connect: backend integravimo pavyzdys

Šaltinis: [1Password Connect](https://www.1password.dev/connect).

**Faktai.** Connect suteikia privatų REST API programoms pasiekti 1Password įrašus ir vault. Jis talpina duomenų kopiją organizacijos infrastruktūroje; dokumentacija tai įvardija kaip „cache your data in your infrastructure“.

**Mūsų pritaikymas.** Backend adapteris gali skaityti paslaptis per Connect, jeigu 1Password jau naudojamas. Connect API ir jo prieigos duomenis pasiekia brokeris, o ne LLM įrankis. Vietinė kopija reiškia papildomą saugotiną komponentą. Privatus REST API savaime nepaverčia grąžinamos paslapties nematoma klientui.

### 3. Bitwarden Secrets Manager: projektai ir mašininės tapatybės

Šaltinis: [Machine Accounts](https://bitwarden.com/help/machine-accounts/). Papildomai: [Projects](https://bitwarden.com/help/projects/).

**Faktai.** Machine account atstovauja ne žmogui, o programai ar vykdymo procesui. Jai priskiriami projektai ir prieigos teisės, o access token leidžia programinę prieigą prie paslapčių. `Can read` leidžia nuskaityti projektų paslaptis; tai nėra vien teisė jas panaudoti slepiant reikšmę. Projektai grupuoja paslaptis, žmones ir machine accounts; pagal patikrintą puslapį viena paslaptis vienu metu priklauso vienam projektui.

**Mūsų pritaikymas.** Atskiros agentų tapatybės ir atskiros brokerio backend tapatybės. Agentui suteikti `metadata:read` ir konkrečius `action:execute`, brokeriui – tik reikiamos aplinkos paslapčių skaitymą. Nesuteikti vieno visiems projektams bendro token. Mūsų `use` teisė būtų papildoma brokerio semantika, ne Bitwarden `read` pervadinimas.

### 4. Infisical: aplinkos, keliai ir dinaminės paslaptys

Šaltinis: [Access control in Secrets Management](https://infisical.com/docs/documentation/platform/secrets-mgmt/concepts/access-control). Papildomai: [Dynamic secrets](https://infisical.com/docs/documentation/platform/dynamic-secrets/overview).

**Faktai.** Paslaptys susiejamos su aplinka ir keliu; aplinkos atskiria `development`, `staging`, `production`, o keliai grupuoja, pavyzdžiui, `/redis` ar `/checkout-service`. Projektų rolės riboja pasiekiamas aplinkas ir kelius. Dinaminės paslaptys generuojamos pagal poreikį, numatytas jų atšaukimas. Tyrimo metu dinaminių paslapčių puslapis nurodė „Advanced“ planą ir licencijos poreikį savarankiškam diegimui.

**Mūsų pritaikymas.** Aplinka turi būti privaloma saugumo riba, folder – papildomas grupavimas. `prod` negali būti automatinė trūkstamo `dev` įrašo alternatyva. Dinaminius prisijungimus planuoti vėlesniam etapui, įvertinus licenciją ir tikslinių paslaugų galimybes. Trumpas galiojimas sumažina panaudojimo langą, bet nepašalina nutekėjimo ar piktnaudžiavimo galiojančiu prisijungimu.

### 5. OpenBao: response wrapping ir dinaminiai DB prisijungimai

Šaltinis: [Response wrapping](https://openbao.org/docs/concepts/response-wrapping/). Papildomai: [Databases](https://openbao.org/docs/secrets/databases/). Atvertuose puslapiuose buvo nurodyta dokumentacijos versija 2.7.x.

**Faktai.** Response wrapping pakeičia tikrą API atsakymą vienkartiniu token; atsakymas atgaunamas per `unwrap`. Token galiojimo laikas yra atskiras nuo perduodamos paslapties galiojimo. DB secrets engine pagal roles generuoja unikalius prisijungimus kiekvienai užklausai ir naudoja lease bei atšaukimo mechanizmą. Dokumentacija kalba apie atšaukimą per pagrįstą laiką pasibaigus lease, todėl tai nėra garantuotas momentinis visų aktyvių sesijų nutraukimas.

**Mūsų pritaikymas.** Wrapping naudingas perduodant atsakymą tarp patikimų komponentų. LLM jo token neduoti: kas gali token išpakuoti, tas gali gauti paslaptį. DB veiksmui brokeris ateityje galėtų išduoti trumpalaikį, tik tam veiksmui tinkamą prisijungimą savo vykdytojui, po veiksmo jį atšaukti ir stebėti nesėkmingus atšaukimus. Dinaminio MySQL 8.4 vartotojo kūrimą, teises, atšaukimą ir aktyvias sesijas reikės patikrinti integraciniu bandymu.

## Pasitikėjimo ribos ir API

Tikslas – sumažinti galimybę tikrosioms reikšmėms patekti į modelio įvestį, įrankių atsakymus, pokalbio istoriją ir agento skaitomus artefaktus. Modelio raginimas „neatskleisk slaptažodžio“ nėra techninė prieigos kontrolė.

Rekomenduojamas srautas:

```text
Žmogus → atskiras WEB prisijungimas → katalogas / įvedimas / atskleidimas pagal teises
LLM → autentifikuotas veiksmų API → politika → patikimas brokeris → tikslinė paslauga
                                                    ↓
                                           paslapčių backend
LLM ← tik apibrėžtas rezultatas ← išvesties schema ← brokeris
```

`get_secret(id) → plaintext` tiesiogiai prieštarauja tikslui, jeigu atsakymą gauna LLM įrankis. Galimas vidinis backend skaitymo metodas, kurį kviečia tik patikimas brokeris. Žmogaus atskleidimo funkcija turi atskirą autentifikaciją ir leidimą; jos negali pasiekti agento tapatybė.

Siūlomas agento API:

- `list_entries(workspace_id, project_id, environment_id, type)` grąžina tik leidžiamus metaduomenis ir galimus veiksmus.
- `execute_action(entry_id, action_id, parameters, request_id)` leidžia tik serverio apibrėžtą veiksmą ir griežtą parametrų schemą.
- `get_action_result(operation_id)` grąžina ribotą rezultatą; patikrinama operacijos priklausomybė kvietėjui ir workspace.

Pirmas konkretus veiksmas galėtų būti `database.check_connection`: serveris pasirenka registruotą MySQL tikslą, prisijungia ir įvykdo fiksuotą `SELECT 1`, o grąžina tik `ok`, bendrą klaidos kodą bei `operation_id`. Nei connection string, nei slaptažodis, nei neapdorota DB klaida negrąžinami. LLM negali pateikti SQL, shell, host, proxy, URL ar savavališkų HTTP antraščių.

Brokerio vykdomas kodas, veiksmų registras, paskirties adresai ir politika turi būti neprieinami agento rašymui. Jeigu agentas gali pakeisti vykdytojo skriptą prieš paleidimą, leistinų veiksmų sąrašas nebesaugo. Gamybinį brokerį diegti iš peržiūrėto artefakto, ne iš agento redaguojamo bind mount.

## Išnešimo rizikos ir valdymas

**Šis skyrius – mūsų grėsmių analizė.** Kontrolės riboja konkrečius kanalus, tačiau nėra visų galimų nutekėjimų nebuvimo įrodymas.

| Kanalas | Kaip paslaptis gali patekti agentui arba pašaliniam gavėjui | Rekomenduojama riba |
| --- | --- | --- |
| Arbitrary shell / procesas | Agentas pasirenka kodą, kuris nuskaito jam įduotą paslaptį, pakeičia koduotę ir išsiunčia ją. | Veiksmų API nepriima komandų ar vykdomų failų. Vykdytojas nekrauna agento valdomo kodo, įskiepių ar konfigūracijos. |
| `stdout`, `stderr`, klaidos | SDK, HTTP ar DB klaida, diagnostika arba pats procesas išspausdina reikšmę. | Fiksuota rezultatų schema, bendri klaidų kodai, jokių žalių išvesčių. Maskavimas – tik papildomas sluoksnis; transformuotos ir dalinės reikšmės gali jo išvengti. |
| `env`, argumentai, failai, atmintis | Procesų aplinka, argumentų sąrašai, `/proc`, laikini failai, core dump ar bendri volume atskleidžia reikšmę. | Atskira tapatybė ir izoliacija, be bendro PID namespace ar agentui skaitomų volume. Slaptų argumentų vengti; reikšmes laikyti kuo trumpiau. Aplinkos kintamieji nėra nematomumo garantija. |
| Browser DOM ir screenshot | `type=password` maskuoja vaizdą, tačiau neapsaugo nuo DOM / JS skaitymo; „rodyti“ mygtukas ar automatinė kopija atskleidžia reikšmę. | Žmogaus saugyklos puslapis ir sesija nepasiekiami LLM naršyklei. Paslapties nesiųsti į DOM iki autorizuoto atskleidimo. Autentifikuotą naršyklės vykdytoją izoliuoti atskirai ir tikrinti tame pačiame pirmajame LLM etape kaip API/CLI. |
| Trace, HAR, video, log, telemetrija | Užklausų kūnai, Authorization, cookies, tinklo įrašai, session replay ir ekrano video gali išsaugoti prisijungimus. | Paslapčių keliuose išjungti turinio įrašymą ir session replay; loginti tik leidžiamus laukus. Trace ir klaidų eksportavimą tikrinti su fiktyviomis paslaptimis. |
| Prompt injection | Svetainė, README ar paslapties aprašymas liepia atskleisti reikšmę ar pakeisti paskirtį. | Metaduomenys ir išoriniai atsakymai laikomi nepatikimais duomenimis. Tapatybę, leidimą, parametrus ir paskirtį tikrina serveris nepriklausomai nuo prompt. Agentas negali pats išplėsti politikos. |
| SSRF ir HTTP nukreipimai | LLM pakeičia URL, nukreipimą, DNS ar proxy ir nusiunčia autentifikaciją svetimam serveriui arba pasiekia metaduomenų tarnybą. | Tik iš anksto registruotos paskirtys, serverio parinkta schema / host / port / kelias. Išjungti nukreipimus, tikrinti TLS, riboti egress tinklo lygiu, užkirsti DNS rebinding; vidinius adresus leisti tik konkrečioms registruotoms paslaugoms. |
| Autentifikuotas rezultatas | Net ir patikima paskirtis gali atkartoti token arba grąžinti jautrius verslo duomenis. | Nesuteikti universalaus proxy. Kiekvienas veiksmas apibrėžia grąžinamus laukus, dydį ir leidžiamą turinį; tikrinti ir atsakymą, ne tik užklausą. |
| Sesijos ir deleguoti token | Slaptažodis lieka paslėptas, tačiau cookie, bearer token ar wrapping token suteikia panašias galias. | Juos taip pat laikyti paslaptimis. LLM perduoti tik brokerio operacijos ID, nesuteikiant tiesioginės tikslinės sesijos. |

Jeigu agentas turi brokerio host `root`, Docker socket, debugger / `ptrace`, saugyklos raktus arba gali vykdyti arbitrary process brokerio tapatybe, **absoliučios paslapčių nepasiekiamumo garantijos nėra**. Vien kitas konteineris tame pačiame agento administruojamame host šios ribos nesukuria. Patikimą brokerį ir backend laikyti agento neadministruojamoje VM ar serveryje; vietinis Docker pagrindas gali būti kūrimo aplinka su fiktyviais duomenimis.

Net neatskleidžiant reikšmės, `execute` teisė leidžia veikti kitos paskyros vardu. Reikia riboti veiksmų apimtį, dažnį ir trukmę, atšaukti prieigą bei audituoti. Destruktyviems ar jautriems gamybiniams veiksmams numatyti atskirą žmogaus patvirtinimo politiką.

## Siūlomas organizavimo modelis

Pagrindinė hierarchija: `workspace → project → environment → entry`. `type` yra įrašo tipas ir filtras, o pasirenkamas `folder` – grupavimas aplinkos viduje. Žmogaus navigacija gali rodyti `workspace / project / environment / type`, tačiau nereikia dubliuoti įrašo kiekvienam filtrui.

- **Workspace:** organizacijos, kliento ar asmeninės erdvės izoliavimo riba; atskira narystė.
- **Project:** produktas arba sistema; priklauso vienam workspace.
- **Environment:** `dev`, `staging`, `prod` arba aiškiai pavadinta `shared`; priklauso projektui. Jokio tylaus perėjimo tarp aplinkų.
- **Entry:** stabilus UUID, pavadinimas, tipas (`login`, `database`, `api_token`, `ssh_key`, `certificate`), aplinka, folder, savininkas, žymos, rotacijos būsena ir backend nuoroda. Slaptos reikšmės – backende. Vartotojo vardas, URL ir pastabos irgi gali būti jautrūs, todėl jų viešumas nėra numanomas.
- **Machine identity:** konkretaus agento / integracijos tapatybė; atskiri galiojimai ir atšaukimas.
- **Action definition / grant:** įrašo ir leistino veiksmo susiejimas su parametrais, paskirtimi, principal, galiojimu ir patvirtinimo taisyklėmis.
- **Audit event:** kas, kada, kurioje aplinkoje ir kokį veiksmą atliko, politikos versija, rezultato kodas, operacijos ID; be paslapčių ir neapdorotų parametrų.

MySQL ryšiais ir užklausomis privaloma užtikrinti, kad projektas, aplinka ir įrašas priklauso tam pačiam workspace. UUID žinojimas nesuteikia prieigos. Teisės tikrinamos sąrašuose, vieno įrašo užklausose, veiksmuose ir rezultate. Įrašo perkėlimas į kitą aplinką turi iš naujo įvertinti suteiktas teises.

## Žmogaus WEB navigacija

Viršuje – workspace parinkimas. Kairėje – projektai, „Visi įrašai“, „Mašininės tapatybės“, „Prieigos“, „Auditas“. Projekto viduje – aiškus aplinkos perjungiklis, aplinkos folder medis ir tipų filtrai. `prod` matoma tekstu ir spalva; aplinka išlieka matoma atveriant įrašą ar vykdant veiksmą.

Sąraše rodyti pavadinimą, tipą, savininką, atnaujinimą, rotacijos būseną ir leistinus veiksmus. Slapta reikšmė nepatenka į pradinį HTML, JSON ar paieškos indeksą. Įrašo kortelėje: „Apžvalga“, „Prieigos“, „Veiksmai“, „Istorija“. „Kopijuoti nuorodą“ atskirta nuo „Atskleisti / kopijuoti paslaptį“; pastarajam taikoma žmogaus teisė, prireikus pakartotinis tapatybės patvirtinimas ir auditas. Atskleidimo atsakymams nenaudoti cache. Įvedimo srautui taip pat išjungti request body logus.

Pirma versija gali nukreipti žmogų į esamo backend saugią įvedimo ir atskleidimo sąsają. Jei būtinas vientisas mūsų WEB redagavimas, jo slaptų duomenų kelias turi likti atskirtas nuo agento API, o laikinos reikšmės neturi patekti į aplikacijos eilės žinutes, sesijų saugyklą ar telemetriją.

## Esamas backend prieš savą kriptografiją

Rekomenduojama pradėti nuo vieno backend adapterio. Jei tinkamas backend jau eksploatuojamas, naudoti jį; jei viskas kuriama nuo pradžių ir prioritetas yra savarankiškas valdymas bei būsimi dinaminiai DB prisijungimai, pirmiausia vertinti OpenBao. 1Password Connect prasmingas turint 1Password; Bitwarden suteikia aiškų projektų ir machine account modelį; Infisical artimas pageidaujamam aplinkų / folder organizavimui, bet funkcijų licencijas reikia vertinti atskirai.

PHP 8.5 aplikacija ir MySQL 8.4 saugotų metaduomenis, politikas bei auditą, o backend – tikrąsias reikšmes ir jų versijas. Backend pasirinkimas nebūtinai naudos tą pačią MySQL infrastruktūrą. Įvesti siaurą adapterio sąsają, tačiau pirmame etape nekurti visų tiekėjų palaikymo. Duomenų tarp katalogo ir backend neatitikimams numatyti aiškias būsenas ir pakartojamą suderinimą: išorinės paslapties rašymas ir MySQL transakcija nėra viena atominė operacija.

Nerekomenduojama pradėti nuo lentelės su užšifruotu `password` ir bendru raktu aplikacijos `.env`. Net naudojant patikimą šifravimo biblioteką lieka raktų saugojimas, rotacija, atsarginės kopijos, vientisumas, versijavimas, autorizacija ir atkūrimas. Backend nepanaikina šių operacinių pareigų: reikia TLS, ribotų brokerio kredencialų, jų pradinio saugaus suteikimo, raktų atkūrimo plano, atnaujinimų ir išbandytų backup. Negalima brokerio paleidimo paslapties laikyti tik tame backende, kuriam atrakinti jos reikia.

## Pirmas įgyvendinimo etapas ir priėmimo kriterijai

1. **Docker pagrindas su fiktyviais duomenimis.** PHP 8.5 ir MySQL 8.4, aiškiai apibrėžtos tinklo / failų prieigos. Šis etapas dar neturi teigti, kad apsaugo paslaptis nuo host administruojančio agento.
2. **Katalogas ir autorizacija.** Workspace, project, environment, entry, type; žmogaus autentifikacija; atskira agento tapatybė. Pagal nutylėjimą viskas uždrausta. API pateikia tik leidžiamus metaduomenis.
3. **Vienas backend adapteris.** Susieti katalogo įrašą su paslaptimi, išbandyti su sintetine reikšme. Agentui neduoti backend token ar skaitymo endpoint. Žmogaus įvedimas / atskleidimas per backend WEB arba atskirai apsaugotą mūsų srautą.
4. **Abiejų krypčių brokerio veiksmai.** API/CLI kelyje – vienas API skaitymo veiksmas ir vienas fiksuotas CLI receptas; `database.check_connection` lieka galimo riboto veiksmo pavyzdys. Naršyklės kelyje – prisijungimas prie registruotos svetainės ir vienas leidžiamas skaitymo veiksmas, su izoliuota sesija ir žmogaus MFA perdavimu už LLM konteksto ribų. Tikslinės sistemos dar neparinktos; pradėti nuo kontroliuojamų testinių sistemų. Auditas ir agento API grąžina tik apibrėžtą rezultatą. Universalaus shell, SQL, HTTP proxy, DOM, JS ir naršyklės sesijos eksporto nėra.
5. **Saugumo priėmimo bandymai.** Svetimo workspace ID, neleistina `prod`, išjungta tapatybė ir netinkami parametrai atmetami. Fiktyvi paslaptis nepatenka į API atsakymus, logus, trace ar pradinį WEB DOM. Bandyti backend klaidas, timeout, paskirties pakeitimą, prompt injection metaduomenyse ir agento bandymą keisti vykdytojo kodą. Patikrinti, kad agentas nepasiekia backend tinklo, kredencialų ir vykdytojo failų. Naršyklėje papildomai tikrinti cookies, token, CSRF reikšmių ir artefaktų neprieinamumą, MFA tęsinį, kontekstų atskyrimą, redirect / iframe / popup politiką, sesijos terminą ir atšaukimą. Abiejų krypčių patikros privalomos. Vien teksto paieška loguose nėra pakankamas saugumo įrodymas.

Vėliau pridėti dinaminius DB prisijungimus, lease atšaukimą ir atšaukimo nesėkmių stebėseną, jautrių veiksmų patvirtinimus bei daugiau konkrečių integracijų. Naršyklės prisijungimų grėsmių modelis įtrauktas jau į pirmą LLM etapą: DOM, cookies, screenshot ir trace sudaro papildomus slaptų duomenų kanalus.

**Etapo rezultatas:** žmogus gali rasti tinkamos aplinkos įrašą, autorizuotas agentas – atlikti apibrėžtus API/CLI ir autentifikuotos naršyklės veiksmus, o tikroji reikšmė bei sesijos duomenys nepateikiami agento API. Etapas nebaigtas, kol nepatikrintos abi kryptys. Šį teiginį riboja aprašyta izoliacija ir bandymų apimtis; jis negalioja agentui, valdančiam patikimą host ar vykdytojo procesą.
