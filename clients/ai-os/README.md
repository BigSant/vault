# ai-os integracija

Tikroji integracija yra [BigSant/ai-os](https://github.com/BigSant/ai-os) `app/public/app/Application/Vault/ExternalVault.php`, `AgentTools.php` ir `docker/native/aios_mcp.py`. Čia nekopijuojama antra klientų implementacija, kad autorizacijos sutartis neišsiskirtų.

Konfigūracija, scope failai ir įrankiai aprašyti [integracijų dokumente](../../docs/integrations.md). Įjungus external mode senasis plaintext `credential_use` užblokuojamas. Tikrų raktų nekelkite į checkout ar LLM sesiją.
