# ai-os, MCP ir veiksmų konfigūracija

## ai-os

Integracija įgyvendinta ai-os `App\Application\Vault\ExternalVault`, `AgentTools` ir native MCP transportuose. Įjunkite **tik patikimame WEB/worker backend**:

```dotenv
AIOS_EXTERNAL_VAULT_ENABLED=true
AIOS_EXTERNAL_VAULT_DIRECTORY=/run/aios-vault
```

Į katalogą prijunkite privatų read-only mount, prieinamą backend UID. Jo **neprijunkite** prie native modelio konteinerių, checkout ar agento įrankių. Kiekvienam autorizuotam kontekstui failas:

```text
/run/aios-vault/<ai-os-workspace-uuid>/<agent-uuid>/<project-uuid>.json
/run/aios-vault/<ai-os-workspace-uuid>/<agent-uuid>/workspace.json  # tik ticket be projekto
```

Failo teisės 0600, savininkas – backend procesas; katalogai operatoriaus valdomi. JSON laukai `url` (fiksuotas HTTPS origin, be path/query) ir `token` (Vault WEB vieną kartą parodytas machine raktas). Tikrą token įrašykite tiesiai į failą. Rekomenduojama atskira machine tapatybė kiekvienam workspace/agent/project kontekstui ir minimalūs grants. Ai-os workspace UUID neprivalo sutapti su Vault workspace UUID: ryšį nusako konkretaus failo ribotas token.

Modelis negali pakeisti savo workspace, agent ar project autorizacijos konteksto. Paieškoje `project_id` ir `environment_id` tik susiaurina jau leistus įrašus. Jie gaunami iš `AgentQueue::guard` autorizuoto run. `vault_search`, `vault_execute`, `vault_status`, `vault_cancel` naudojami per esamą ai-os įrankių transportą. Paieška priima `query` (alias), `project_id`, `environment_id`, `type`, `page`, `per_page` (1–100); atsakymas turi `next_page` arba null. Projektų ir aplinkų vardai yra agentui patvirtinti metaduomenys, juose nelaikykite paslapčių. Execute priima entry UUID, action ir 8–80 simbolių `request_id`; pakartojimui naudokite tą patį ID. Status/cancel gauna grąžintą opaque handle, galiojantį tik tam ticket ir kontekstui. Atsakymas papildomai tikrinamas pagal siaurą tipų sutartį; jokie upstream laukai neperduodami automatiškai.

**Įjungus external mode, senieji `credentials` ir `credential_use` atsisako darbo su `use_external_vault_actions`.** Nėra fallback į modelio kontekstą išduodamą plaintext, net kai trūksta naujo failo ar Vault nepasiekiamas. Esami senos saugyklos įrašai automatiškai nemigruojami ir netrinami. Išjungtas jungiklis išlaiko ankstesnį ai-os elgesį; todėl išjungimo negalima laikyti saugaus Vault naudojimo režimu.

MFA ir `pending` patvirtinimus žmogus užbaigia Vault WEB „Prieigos ir veiksmai“. ai-os neturi owner cookie, OTP seed ar machine token savo modelio kontekste. Perduodant tik būseną nėra automatinio ticket pažadinimo; agentas gali tikrinti `vault_status` savo įprastu darbo ciklu.

## Bendras MCP ir CLI

`POST /api/mcp` yra stateless JSON-RPC transportas (initialize, ping, tools/list, tools/call); `/api/v1/catalog`, `/execute`, `/operations/{id}`, `/operations/{id}/cancel` – HTTP sąsaja. Abu naudoja bearer machine tapatybę, atmeta naršyklės Origin ir taiko IP bei token limitus. Ilgos SSE sesijos nereikia. Nežinomos po brokerio restarto operacijos žymimos failed ir nekartojamos.

`clients/vault.py` gali veikti patikimame host kaip CLI arba stdio MCP bridge:

```bash
python3 clients/vault.py --config /privatus/klientas.json list
python3 clients/vault.py --config /privatus/klientas.json execute <entry-uuid> <action> --request-id <stabilus-id>
python3 clients/vault.py --config /privatus/klientas.json status <operation-uuid>
python3 clients/vault.py --config /privatus/klientas.json cancel <operation-uuid>
python3 clients/vault.py --config /privatus/klientas.json mcp
```

Failo schema tokia pati (url/token), teisės 0600, be symlink. Klientas nevykdo redirect ir neperima aplinkos HTTP proxy. Šio proceso failų skaitymas ar shell nėra modelio įrankis; jei LLM turi tą patį host administravimą, paslapčių atskyrimo pažadas nebegalioja. Integracijai ai-os naudokite jo trusted backend, ne config failą agento sandbox.

## Connector aprašas

Privatus `data/connectors/connectors.json` (atitinkamas aplinkos data kelias) turi `actions` objektą. Numatyta tuščia konfigūracija. Pavyzdys be tikrų reikšmių:

```json
{
  "actions": {
    "service.status": {"kind":"http", "url":"https://service.example/api/status"},
    "service.cli-status": {"kind":"cli", "url":"https://service.example/api/status"},
    "service.account": {
      "kind":"browser", "url":"https://service.example/login", "origins":[],
      "username_selector":"input[name=username]", "password_selector":"input[name=password]",
      "submit_selector":"button[type=submit]",
      "allowed_requests":[{"method":"POST","url":"https://service.example/login"},{"method":"GET","url":"https://service.example/account"}],
      "success_selector":"#account-state", "success_text":"connected"
    }
  }
}
```

API/CLI naudoja įrašo `token` lauką kaip Bearer ir grąžina sėkmę tik gavę 200. HTTP kūnas ir antraštės ignoruojami, CLI stdout/stderr sunaikinami. Tai fiksuotas receptas, ne savavališkos komandos paleidimas. Browser naudoja login `username`/`password`; MFA srautui pridėkite `mfa_selector` ir `mfa_submit_selector`. `success_text` tiksliai palyginamas, jo tekstas negrąžinamas. Vietinis kontekstas uždaromas; target serverio sesija gali gyventi iki jo nustatyto TTL.

`allowed_requests` turi tiksliai įvardyti kiekvieną papildomą URL (įskaitant query) ir metodą: formas, MFA, redirect ir statinius resursus. Tik pradinis GET automatiškai leidžiamas. Kiekvienas navigacijos redirect į leidžiamą GET perleidžiamas per naują patikrą; POST išsaugantys 307/308 ir subresursų redirect atmetami. Iframe ir popup užklausos blokuojamos net jei jų URL yra sąraše. `timeout_seconds` gali sutrumpinti, bet ne pailginti 180 s ribą. `origins` leiskite tik būtinus, patikimus svetainės origin; neleiskite wildcard. Privatų tikslą leidžia tik tikslus operatoriaus CIDR, pvz. `"allow_cidrs":["10.20.30.40/32"]`. DNS proxy tikrina visus atsakymus ir pin'ina tikrą IP. HTTP leidžiamas tik test Compose fixture režime. Target, selectors, headers, command ir URL nėra modelio argumentai. Konfigūracija skaitoma kiekvieną operaciją, todėl prieš keičiant veiksmo prasmę atšaukite jo grants / užbaikite aktyvias operacijas ir iš naujo suteikite leidimus.

DB connector: `kind=database`, `role=vault-...`, fiksuotas `host` ir `port`. Įrašo tipas `dynamic_database` ir slaptas `role` turi sutapti; vykdomas tik SELECT 1. Nereikia ir negalima pateikti DB slaptažodžio agentui. Testinis provisioninimas aprašytas runbook.

Naują connector pradėkite su sintetine paskyra: patikrinkite normalų atsakymą, paslapties echo ir koduotą echo, redirect, neleistiną adresą, atšaukimą ir klaidas. Tik tada žmogus įveda tikrus prisijungimus WEB. Tipas `ssh` leidžia saugojimą/peržiūrą, bet šioje versijoje neturi vykdymo recepto.
