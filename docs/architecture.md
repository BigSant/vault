# Vault architektūros pasiūlymas

2026-11-10. Tai būsimos sistemos projektas, ne jau įdiegtų saugumo savybių aprašymas. Įgyvendintas tik README nurodytas Docker pagrindas. Esamų produktų faktai ir nuorodos – [tyrime](../research/secret-management.md).

## Tikslas ir pasitikėjimas

Žmogus gali patogiai kurti, tvarkyti ir peržiūrėti prisijungimus. LLM gali rasti jam leidžiamą prisijungimą ir paprašyti atlikti konkretų veiksmą. Slapta reikšmė neturi būti įrankio argumentas ar rezultatas, modelio kontekstas, komandos eilutė, žurnalo įrašas ar naršyklės artefaktas.

Toks pažadas galioja tik tada, kai LLM įrankiai neturi prieigos prie vykdytojo atminties, aplinkos, failų, Docker socket, host administravimo ir saugyklos atskleidimo sąsajos. Dabartinė kūrimo aplinka šios izoliacijos neužtikrina: ji turi host bind mount'us, o kompiuterio administratorius gali skaityti konteinerių aplinką. Produkcinį vykdymo modulį reikės atskirti nuo agento aplinkos. Tai ne zero-knowledge saugykla: patikimas vykdytojas turi mokėti iššifruoti ir panaudoti prisijungimą.

Atsakymų maskavimas lieka papildoma apsauga. Savavališka komanda gali slaptažodį užkoduoti, išskaidyti ar nusiųsti kitur; vien regex to nesustabdo. Todėl pirmoji versija nesuteikia universalaus `get_secret`, shell, SQL ar laisvo HTTP proxy įrankio.

## Duomenų organizavimas

Hierarchija: **workspace → projektas → aplinka → prisijungimo įrašas**. Tipas – privalomas įrašo atributas ir filtras, ne dar vienas privalomas katalogas. Žymos suteikia papildomas, neprivalomas grupes.

Pavyzdys: `Asmeniniai / parduotuvė / stage / administratoriaus paskyra`, tipas `login`, žymos `PrestaShop`, `admin`. Tos pačios paslaugos prod įrašas yra atskiras ir automatiškai nepaveldi stage teisių ar reikšmių. Bendriems prisijungimams naudoti aiškų projektą `shared`, su atskiromis teisėmis; nenumatyti tylaus fallback į kitą aplinką.

- **Workspace:** savininkas ir aukščiausia prieigos sritis; pradžioje vienas savininkas, struktūra leidžia vėliau turėti kelias darbo sritis.
- **Projektas:** konkreti sistema ar produktas, ne saugomo slaptažodžio tipas.
- **Aplinka:** `local`, `test`, `stage`, `prod` arba savininko pridėtas vardas. Prod reikalauja atskiro leidimo.
- **Įrašas:** stabilus atsitiktinis ID, pavadinimas, tipas, aplinka, žymos, būsena, sukūrimo ir pakeitimo laikas, savininkas, galiojimo/rotacijos data, slapto turinio versijos nuoroda.
- **Slapti laukai:** tipizuotas turinys, saugomas šifruotame backend. `login`: vartotojas ir slaptažodis; `api_key`: raktas ir autentifikavimo būdas; `database`: vartotojas ir slaptažodis; `ssh`: privatus raktas ir jo passphrase. TOTP seed, recovery codes ir secure note – vėlesnis etapas.
- **Naudojimo politika:** agento tapatybė, leidžiami įrašai, veiksmai, tikslinės sistemos, laiko ribos, žmogaus patvirtinimo poreikis ir atšaukimas. Leidimas rasti įrašą nesuteikia leidimo jį naudoti ar atskleisti.

Vartotojo vardai, adresai, pastabos ir net įrašo pavadinimas gali būti jautrūs. Pagal nutylėjimą agentas mato tik savininko patvirtintą alias, tipą, sritį ir leidžiamus veiksmus. Paieška vyksta tik pagal autorizuotus metaduomenis, be slaptažodžių ar raktų indeksavimo. Patikrinti teises prieš filtravimą ir skaičiavimą, kad nutekėjimo nebūtų per rezultatų skaičių ar autocomplete.

## Moduliai ir jų sąsajos

**Katalogo modulis** valdo struktūrą, metaduomenis ir paiešką. Jis nesugrąžina slaptų reikšmių. Jo išorinė sąsaja: `search(scope, query)`, `describe(entry_id)` ir savininko tvarkymo veiksmai. Autorizacija taikoma kiekvienai operacijai, ne vien WEB filtrams.

**Saugojimo modulis** slepia versijas, šifravimą, raktų valdymą ir atkūrimą. Pirmiausia įvertinti esamą backend (OpenBao KV v2 arba Infisical), o savo PHP dalyje laikyti metaduomenis ir nuorodas. Backend parenkamas atskiru įgyvendinimo sprendimu po tyrimo; šiame etape nei vienas neįdiegtas. Nekurti savo šifravimo algoritmo. Jei vis dėlto prireiktų savos realizacijos, būtinas AEAD, autentifikuotas įrašo kontekstas, atskirai laikomi pagrindiniai raktai, raktų rotacija ir reali atkūrimo patikra; vien DB disko šifravimas nepakanka.

**Vykdymo modulis** apima teisių patikrą, trumpą naudojimo leidimą, slapto lauko paėmimą, įterpimą į tikslinį protokolą ir saugų rezultato sudarymą. Sąsaja `execute(entry_id, operation, validated_arguments)` nesuteikia laisvai pasirinkti vykdomos komandos ar paskirties URL. Kiekvienas connector turi savo leidžiamų argumentų ir grąžinamų laukų schemą. Tarp leidimo patikros ir panaudojimo turi būti tikrinamas atšaukimas; trumpas TTL nepakeičia atšaukimo patikros.

**Audito modulis** fiksuoja kas, kada, kokį įrašą ir veiksmą naudojo, rezultatą ir correlation ID. Nefiksuoja slapto turinio, autentifikavimo antraščių, formų, pilnų išorinių atsakymų ar exception argumentų. Įrašo atskleidimas žmogui taip pat yra audito įvykis. Žurnalų prieiga ir saugojimo trukmė valdomos atskirai; vėliau reikalinga nuo aplikacijos atskirta audito kopija.

## LLM naudojimo eiga

1. Agentas autentifikuojamas savo tapatybe, susieta su ribota darbo sritimi. Jo autentifikavimo duomenis pateikia patikimas MCP/CLI transportas iš privataus failo ar OS saugyklos, ne modelio prompt'as.
2. `vault.search` grąžina tik leistinus metaduomenis ir neįgalinančias nuorodas. ID ar URI savaime nesuteikia prieigos.
3. `vault.execute` priima įrašo ID, registruotą veiksmą ir validuotus argumentus. Serveris nustato tikslą ir autentifikavimo formatą iš savininko valdomos konfigūracijos.
4. Vykdytojas patikrina politiką ir, kai jos reikalauja konkretus veiksmas, žmogaus patvirtinimą. Patvirtinimas susiejamas su konkrečiais argumentais ir galioja ribotą laiką.
5. Vykdytojas paima paslaptį ir tiesiogiai atlieka leidžiamą užklausą. Slapta reikšmė nepatenka į agento valdomo proceso env, argv, failą ar stdin.
6. Grąžinamas tik connector sutartas rezultatas, pavyzdžiui `ok`, objekto ID ir būsena. Pilnas stdout, stderr, HTTP kūnas ar headers negrąžinami. Vien leidžiamų laukų sąrašas dar neįrodo saugumo: laukas negali būti savavališkas echo ar tekstas su prisijungimu, todėl reikia kiekvieno connector nuotėkio testų ir konservatyvaus atsakymų atmetimo.

Pirmajam connector tinka vienas skaitymo veiksmas konkrečioje vartotojo pasirinktoje API. Tikslus endpoint dar nenustatytas. CLI palaikymas vėliau gali vykdyti tik administratoriaus aprašytus receptus su ribotais argumentais ir išvestimi. `run(secret, arbitrary_command)` neatitinka pažado slėpti paslaptį nuo agento.

HTTP vykdymas: tiksliai leidžiami HTTPS host/port/path/method, draudžiami savavališki auth header pakeitimai ir redirect'ai. DNS ir egress kontrolė turi neleisti SSRF į loopback, cloud metadata ar kitus vidinius adresus; vidiniai tikslai leidžiami tik atskira konkretaus connector politika. Tikrinti realiai pasiekiamą adresą, ne vien URL tekstą. Dokumentų ar svetainių tekstas negali pakeisti politikos.

Naršyklės prisijungimas yra atskiras connector: slaptažodis, cookies ir autentifikuota sesija priklauso izoliuotam vykdytojui. Agentui neduodamas tos pačios sesijos DevTools, DOM, JS vykdymas, trace, tinklo log'ai ar screenshot'as, galintis rodyti paslaptį. Įprastas password lauko užmaskavimas shared naršyklėje nėra izoliacija. Kol toks kelias neįgyvendintas ir nepatikrintas, naršyklės slaptažodžių injekcija nepalaikoma.

## WEB vaizdas

Darbinis išdėstymas: viršuje workspace pasirinkimas ir paieška; kairėje projektai ir aplinkos; centre filtruojamas įrašų sąrašas; dešinėje pasirinkto įrašo detalės. Mažame ekrane – sąrašas ir atskiras detalių puslapis. Visą sąsają kurti lietuviškai.

Sąraše: pavadinimas, tipas, aplinka, galiojimo būsena ir paskutinio naudojimo laikas. Paslaptys nerodomos nei lentelėje, nei paieškos ištraukoje. Detalėse: `Duomenys`, `LLM prieiga`, `Istorija`. PROD turi aiškią tekstinę žymą, ne vien spalvą. Kūrimo forma pritaikoma įrašo tipui, aplinka privalomai pasirenkama.

Slapti laukai iš pradžių visai neatsiunčiami į puslapį; pastovaus ilgio maskuotė neatskleidžia reikšmės ilgio. „Parodyti“ ir „Kopijuoti“ kreipiasi į atskirą savininko sąsają po pakartotinio autentifikavimo ir įrašo auditą. Automatinis slėpimas bei clipboard valymas yra patogumo priemonės, ne garantija, kad reikšmė neliko OS istorijoje ar ekrano nuotraukoje.

Žmogaus prieigai: MFA/passkey, sesijos galiojimas ir užrakinimas, HttpOnly/Secure/SameSite cookies, CSRF apsauga, prisijungimo bandymų ribojimas, CSP, TLS ir `no-store`. Savininko atskleidimo sąsaja neprieinama agento tapatybei. WEB autentifikacijos ir agento autentifikacijos negalima sutapatinti.

## Įgyvendinimo seka ir priėmimas

**0 – pagrindas (šis darbas):** repozitorija, setup, vietinis HTTPS, DB parengties patikra, tyrimas ir projektas. Tai nėra saugyklos MVP.

**1 – savininko saugykla:** pasirinktas backend, autentifikacija/MFA, schema ir migracijos, šifruoti įrašai, WEB CRUD, metaduomenų paieška, atskleidimo auditas, atsarginė kopija ir atkūrimas. Be realiai išbandyto atkūrimo nekelti realių prisijungimų.

**2 – pirmas LLM veiksmas:** atskira agento tapatybė, viena ribota politika ir vienas API connector; MCP metaduomenų paieška ir veiksmo vykdymas. Jokio bendro raw secret skaitymo įrankio. CLI receptai ir naršyklė įgyvendinami atskirai.

**3 – plėtra:** dinaminiai prisijungimai, automatinė rotacija, trumpalaikės teisės, importas, daugiau connector'ų ir keli savininkai. Importo metu originalūs failai yra paslaptys, ne tyrimo ar Git medžiaga.

Prieš tikrų slaptažodžių naudojimą turi praeiti bent šie scenarijai:

- Kitos darbo srities, aplinkos ar agento nuoroda neatskleidžia nei reikšmės, nei metaduomenų; tikrinami ir sąrašo skaičiai.
- DB kopija be backend raktų neleidžia perskaityti paslapčių; atsarginė kopija atkuriama atskirai nuo kasdienės aplinkos, įskaitant raktus ir versijas.
- Testinė atpažįstama paslaptis ir jos koduotos formos nepatenka į MCP rezultatus, HTTP klaidas, auditą, tracing ar modelio sesiją net tada, kai tikslinė sistema bando ją atkartoti.
- Draudžiami target, redirect, DNS rebinding, savavališki argumentai, neteisingos politikos, pasibaigę ir atšaukti leidimai atmetami.
- Nepasiekiamas backend, neteisingas raktas ar auditui būtino įrašo klaida nesuteikia prieigos ir neįjungia plaintext fallback.
- Agentas negali pasiekti savininko reveal sąsajos, vykdytojo host administravimo, secret mount'ų ar naršyklės artefaktų.

Šie scenarijai yra būsimų saugumo testų sutartis. Dabartinis vienas health testas jų neatstoja.
