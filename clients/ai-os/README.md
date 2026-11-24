# ai-os integration

The actual integration lives in the ai-os repository: `app/public/app/Application/Vault/ExternalVault.php`, `AgentTools.php` and `docker/native/aios_mcp.py`. A second client implementation is not copied here, so that the authorization contract does not diverge.

The configuration, scope files and tools are described in the [integrations document](../../docs/integrations.md). With external mode enabled, the old plaintext `credential_use` is blocked. Do not put real keys into the checkout or an LLM session.
