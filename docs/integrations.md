# ai-os, MCP and action configuration

## ai-os

The integration is implemented in ai-os `App\Application\Vault\ExternalVault`, `AgentTools` and the native MCP transports. Enable it **only in the trusted web/worker backend**:

```dotenv
AIOS_EXTERNAL_VAULT_ENABLED=true
AIOS_EXTERNAL_VAULT_DIRECTORY=/run/aios-vault
```

Attach a private read-only mount to the directory, accessible to the backend UID. Do **not** attach it to the native model containers, the checkout or the agent tools. One file per authorized context:

```text
/run/aios-vault/<ai-os-workspace-uuid>/<agent-uuid>/<project-uuid>.json
/run/aios-vault/<ai-os-workspace-uuid>/<agent-uuid>/workspace.json  # only for a ticket without a project
```

File permissions are 0600, the owner is the backend process; the directories are operator-managed. The JSON fields are `url` (a fixed HTTPS origin, without path/query) and `token` (the machine key shown once in the Vault web UI). Write the real token directly into the file. A separate machine identity for each workspace/agent/project context and minimal grants are recommended. The ai-os workspace UUID does not have to match the Vault workspace UUID: the link is defined by the restricted token of the specific file.

The model cannot change its workspace, agent or project authorization context. In search, `project_id` and `environment_id` only narrow the already allowed entries. They come from the run authorized by `AgentQueue::guard`. `vault_search`, `vault_execute`, `vault_status` and `vault_cancel` are used through the existing ai-os tool transport. Search accepts `query` (alias), `project_id`, `environment_id`, `type`, `page`, `per_page` (1–100); the response has `next_page` or null. `vault_search` also returns `entries.type` to the agent – an identifier built from the name of the type the user chose. Do not keep sensitive data in the names of types, projects and environments. Execute accepts the entry UUID, action and an 8–80 character `request_id`; use the same ID for a retry. Status/cancel take the returned opaque handle, valid only for that ticket and context. The response is additionally checked against a narrow type contract; no upstream fields are passed through automatically.

**With external mode enabled, the old `credentials` and `credential_use` refuse to work with `use_external_vault_actions`.** There is no fallback to plaintext issued into the model context, even when the new file is missing or Vault is unreachable. Existing entries of the old store are not migrated or deleted automatically. The disabled switch keeps the previous ai-os behavior; so disabling it cannot be treated as a safe mode of using Vault.

A human completes MFA and `pending` approvals in the Vault web UI under "Access". ai-os has no owner cookie, OTP seed or machine token in its model context. Since only the state is passed, there is no automatic ticket wake-up; the agent can check `vault_status` in its normal work cycle.

## General MCP and CLI

`POST /api/mcp` is a stateless JSON-RPC transport (initialize, ping, tools/list, tools/call); `/api/v1/catalog`, `/execute`, `/operations/{id}`, `/operations/{id}/cancel` are the HTTP interface. Both use the bearer machine identity, reject a browser Origin and apply IP and token limits. No long SSE session is needed. Operations unknown after a broker restart are marked failed and not retried.

`clients/vault.py` can run on a trusted host as a CLI or a stdio MCP bridge:

```bash
python3 clients/vault.py --config /private/client.json list
python3 clients/vault.py --config /private/client.json execute <entry-uuid> <action> --request-id <stable-id>
python3 clients/vault.py --config /private/client.json status <operation-uuid>
python3 clients/vault.py --config /private/client.json cancel <operation-uuid>
python3 clients/vault.py --config /private/client.json mcp
```

The file schema is the same (url/token), permissions 0600, no symlinks. The client does not follow redirects and does not pick up the environment's HTTP proxy. This process's file reading or shell is not a model tool; if the LLM has the same host administration, the promise of secret separation no longer holds. For the ai-os integration, use its trusted backend, not a config file in the agent sandbox.

## Connector definition

The private `data/connectors/connectors.json` (the corresponding environment's data path) has an `actions` object. The default configuration is empty. An example without real values:

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

API/CLI use the entry's `token` field as the Bearer and return success only on a 200. The HTTP body and headers are ignored, CLI stdout/stderr are discarded. This is a fixed recipe, not arbitrary command execution. Browser uses the login `username`/`password`; for an MFA flow add `mfa_selector` and `mfa_submit_selector`. `success_text` is compared exactly, and its text is not returned. The local context is closed; the target server session can live until the TTL it sets.

`allowed_requests` must name exactly every additional URL (including the query) and method: forms, MFA, redirects and static resources. Only the initial GET is allowed automatically. Each navigation redirect to an allowed GET is passed through a new check; 307/308 that preserve POST and subresource redirects are rejected. A disallowed request, iframe/popup, WebSocket or download attempt aborts the whole operation with failed; even an allowed URL's Content-Disposition attachment is not passed to the browser. `timeout_seconds` can shorten but not extend the 180 s limit. In `origins`, allow only the necessary, trusted site origins; do not allow wildcards. A private target is allowed only by an exact operator CIDR, e.g. `"allow_cidrs":["10.20.30.40/32"]`. The DNS proxy checks all answers and pins the real IP. HTTP is allowed only in the test Compose fixture mode. Target, selectors, headers, command and URL are not model arguments. The configuration is read on every operation, so before changing the meaning of an action, revoke its grants / finish the active operations and grant the permissions again.

DB connector: `kind=database`, `role=vault-...`, a fixed `host` and `port`. The entry type `dynamic_database` and the secret `role` must match; only SELECT 1 is executed. The DB password need not and cannot be given to the agent. Test provisioning is described in the runbook.

Start a new connector with a synthetic account: check the normal response, secret echo and encoded echo, redirect, a disallowed address, revocation and errors. Only then does a human enter the real credentials in the web UI. The `ssh` type allows storage/viewing but has no execution recipe in this version.
