# Vault priėmimo patikra

2026-11-10. Tikrintas Vault `2992078` ir ai-os `9523ca6a`.

## Nepriklausomos Codex analizės

| Ašis | Pradinis vertinimas | Pakartotinis | Galutinis |
|---|---:|---:|---:|
| Saugumas | 6,8 | 8,2 | **8,7/10** |
| Funkcijų atitikimas | 8,2 | 8,7 | **9,0/10** |
| Kodo kokybė | 7,3 | 8,6 | **8,7/10** |

Galutinį pakeitimų patikrinimą atliko trys atskiri agentai su nauju kontekstu. Saugumo ir kokybės paskutinio etapo patikra apėmė `3cb568a...2992078` ir susijusį kodą, remiantis ankstesne viso sprendimo analize. Visos trys ašys atitinka naudotojo 8,5 slenkstį; naujų P1/P2 blokatorių nerasta.

Ištaisyta: recovery ir MFA sesijų lenktynė, senų leidimų atgaivinimas po regrant, vėlyvas terminalinių būsenų perrašymas, brokerio lygiagretumo ribos, kiekvieno browser redirect žingsnio politika, backup dalinio rašymo / pilno disko / flush / fsync klaidos, netinkliniai download / srcdoc / popup bandymai. Įrašo lange pridėti leidimai, istorija, operacijų patvirtinimai ir MFA.

## Vykdymo įrodymai

- PHPUnit: **18 testų, 132 patikros**; Python brokerio, egress ir CLI: **10 testų**.
- Šifruotų kopijų roundtrip, klaidingo rakto, pakeisto ir nukirsto failo, dalinio rašymo, flush ir fsync gedimų testai PASS.
- Tikras HTTPS / CSRF / TOTP / OpenBao / brokeris: API, CLI, browser su MFA, dinaminė DB; visos saugaus rezultato patikros PASS.
- Browser neigiami scenarijai: išorinis origin, neleidžiamas same-origin POST, redirect grandinė, iframe, popup, HTTP attachment, blob download, srcdoc ir about:blank. Draudžiami tinklo keliai nepasiekia tikslinio serverio; pažeidimas neskelbiamas sėkme.
- Tikras ai-os PHP klientas → HTTPS MCP → brokeris PASS. Atskirai tikra AgentQueue / AgentTools / journal patikra izoliuotoje MySQL aplinkoje PASS; **26 native transport testai** PASS.
- Owner recovery atšaukia pending MFA ir senas autentifikuotas sesijas; naujas slaptažodis leidžia naują MFA registraciją.
- Šifruota kopija, izoliuotas verify ir pilnas atkūrimas į tuščią aplinką PASS: atkurtos 36 įrašų versijos bei MFA; atkurtos machine prieigos atšauktos; užpildytos aplinkos perrašymas atmestas.
- Vietinės aplinkos konteineriai healthy; `https://vault.local/health.php` grąžina HTTP 200 ir `ready`.

Komandos ir atkūrimo eiga pateiktos README ir `docs/operations.md`. Vietiniai vykdymo žurnalai: `/tmp/vault-phpunit-final.log`, `/tmp/vault-python-final.log`, `/tmp/vault-aios-integration.log`, `/tmp/vault-crypto-final.log`, `/tmp/vault-recovery-final.log`, `/tmp/aios-native.log`, `/tmp/aios-guarded-integration.log`. Šie laikini žurnalai nėra repozitorijos artefaktai; pakartojami testai yra repozitorijose.

## Sąžiningos ribos ir neblokuojančios pastabos

API/CLI pateikia fiksuotus Bearer GET/status receptus, DB – trumpalaikį SELECT 1, browser – registruotą login/MFA/status receptą. Naujos sistemos ir operacijos reikalauja operatoriaus connector. Bendro naršymo, CAPTCHA/WebAuthn, savavališko shell ir trečiųjų šalių slaptažodžių automatinio keitimo nėra. Realios paskyros nesukonfigūruotos; ai-os integracija opt-in, pilnas produkcinis native agento procesas e2e netikrintas.

Saugumo riba – patikimas backend/host ir atskiras agento sandbox. Host/root/Docker administratorius gali pasiekti paslaptis. WEB būsenas sinchronizuoja agento status užklausos. Operacijų lentelėje tapatybė matoma per operacijos UUID, tačiau atskiras prašančio agento vardo stulpelis būtų naudingas. Blade formatas ir brokerio operacijos žodynas turi priežiūros tobulinimo vietų. Pakartotinio cancel vykstant Chromium teardown deterministinis testas liko papildomas regresijos kandidatas; patvirtinto pažeidžiamumo nerasta.
