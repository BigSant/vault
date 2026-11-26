# Vault architecture

Implemented on 2026-11-10 based on the [research on official systems](../research/secret-management.md). The goal is a store that is convenient for a human and specific actions available to an LLM, with no credential values in their arguments or results. API/CLI and the browser have equal priority. The primary client is ai-os.

## Data model

**Workspace → entry, with a project and an environment.** Projects and environments are two separate lists of the workspace: an entry names one of each (`entries.project_id`, `entries.environment_id`). Environments are one shared list, like entry types (a new workspace starts with local, test, stage and prod; prod is production); no project has environments of its own. Environment names are unique in a workspace whatever their case or accents. The entry type and tags are used for search and filtering. Each workspace manages its own entry types and their fields. The initial set of types is created the first time types are needed. `shared` can be used for common entries, but there is no fallback or grant inheritance between environments.

MySQL holds identities, Argon2id password hashes, the structure, the entry UUID, alias, type, version reference, state, dates, grants, operations and the audit. OpenBao KV v2 holds the actual typed JSON content and the owners' TOTP seeds. Entries are written with CAS, and up to 1000 versions are kept. Every change is recorded in `entry_changes`: who, when, which fields (keys only, not values) and which non-sensitive metadata changed. The MySQL `entry_types` table stores only the field definitions. Field values stay in OpenBao. The database has no plaintext secret index. The alias is text explicitly approved for the agent; the name, username and target addresses are not returned to the agent.

The owner manages members, identities and policy; the editor creates, changes and reveals entries; the viewer sees metadata. Every server action re-checks the workspace. The last owner cannot be removed. Human and agent authentication are separate: cookie + password + TOTP, or a restricted bearer token hash.

## Modules and flow

```text
Human web UI → Identity / Access / Catalog → OpenBao (values)
                                       └→ MySQL (structure, audit)
LLM → ai-os trusted backend / MCP → Operations → broker
                                   ↑              ├→ HTTPS API
                          reauthorization         ├→ fixed CLI recipe
                                                  ├→ Chromium + human MFA
                                                  └→ dynamic DB lease
```

- `Secrets` hides the OpenBao AppRole, CAS and version reads. Web sessions are encrypted with the application key.
- `Catalog` manages typed content, search, version restore, import, archive and destruction. The backend write and SQL are not one distributed transaction: a failure can leave an unreachable backend orphan; there is no plaintext fallback.
- `Links` makes one-time links with the workspace's Onetime Secret account (`OneTimeSecret`, API v2). The content
  may name an entry's fields as `{{field}}`; the application puts the values in when it sends the content, and the
  agent that asked gets only the link. An agent's link that uses an entry is a request of the built-in action
  `onetimesecret.link` (approval, deadline and audit as any request), sent by the application, never by the broker.
- `Operations` binds the machine, entry, action, version, specific approval and a five-minute deadline. `request_id` ensures idempotency within one identity. The dispatch claim is written before execution: a crash in that interval ends in a safe failure, not a repeated external action.
- The broker re-checks the grant before getting the value, before the browser's network requests and every second during active work. There is no promise to undo an external action that has already completed. Responses are only server-generated states/booleans.
- Handoff kinds (`storage_state`, `broker/handoff.py` for the shared directory, lease, release and cleanup) end with a
  file for an *executor* identity instead of a boolean. After the broker writes `<operation-uuid>.json` (0640, the
  directory's group, `openat` with `O_CREAT|O_EXCL|O_NOFOLLOW`, directory checked: not a symlink, owned by the
  broker, no access for others, no group write) it tells the application (`POST /api/internal/operations/{id}/handoff`,
  broker key); without that record the file is removed at once. The operation stays `running` with
  `lease_expires_at`; release → `succeeded`, TTL → `expired`, revoked grant/identity/entry version → `revoked` (the
  reaper's one-second check), broker start removes every file and unknown operations read `failed`. The claim before
  dispatch extends the deadline to login (180 s) + TTL. Only an executor may hold such a grant and only for the
  connector's `entry_types`; one live handoff per entry and 60 per executor and hour.
- The audit records the action, subject, actor, time and outcome. Values, HTTP payloads, MFA, exception arguments and broker stdout/stderr are not recorded.

States: `pending` → `approved` → `running` → `requires_user_action` / `succeeded` / `failed` / `expired` / `revoked`. Browser MFA is supplied by a human in the Vault web UI; the code is never passed to the agent. An operation ID is not a permission – only the machine identity that created the operation gets its state. ai-os additionally signs the handle with the workspace, agent, project and ticket context. A handoff adds the audit events `handoff.written` and `handoff.removed` (outcome `release`, `ttl`, `revoke` or `restart`), never with a value or path. A storageState the executor has already read stays usable at the site until the site ends that session: Vault can remove the file, not take back what was read.

## Isolation

The broker is attached only to the `internal` Docker network. The egress proxy separately has the external network; it allows only operator-registered hosts/ports, checks all DNS addresses and connects to the verified IP. Private addresses must have an explicit CIDR allowance. API/CLI do not follow redirects; the browser allows only exact registered URLs and methods on registered origins, and blocks iframes/popups, WebSocket and service workers. The HTTP fixture exception exists only in the test Compose environment. External targets use HTTPS.

Chromium runs with the user namespace sandbox, cap-drop ALL, no-new-privileges, and limited processes and memory. Each operation gets a new context, which is closed on completion or revocation. The agent gets no browser control, DOM, cookies, screenshots, HAR, trace or CDP. The broker's fixed CLI runs in its own trusted process; the model cannot specify a shell, program, URL, headers or code.

This is not a zero-knowledge system. The web UI and the trusted executor must be able to read the values. An agent with host/root/Docker or backend code change rights can bypass this boundary. The production boundary is a separate operator host/VM and an agent sandbox, not just a directory name or a masking regex. Internal OpenBao HTTP and MySQL traffic is treated as the trusted host's network; a multi-host deployment needs a separate TLS and network design.

## Web UI

An English responsive UI: workspace selection, search, project/environment/type/state filters, a paginated list and a separate detail view. Prod has a text label. Secret data is not sent in the initial detail page; it is revealed by a separate POST request after a password confirmation valid for five minutes and an audit record. It is hidden after 30 s. The clipboard is a function of the human's OS – we do not guarantee clearing its history.

Pending and unlocked sessions are tied to the password revision; recovery and MFA are serialized with a user row lock. TOTP is protected against reuse of a code from the same time step. The session idle timeout is 20 min, the absolute one 8 h. HttpOnly/Secure/SameSite strict cookies, CSRF, CSP, no-store, rate limits and generic error messages are used. A machine identity does not give web reveal access. Destruction requires an explicit DELETE confirmation; backup retention stays separate.

## Stages and checks

| Stage | Implementation | Evidence |
|---|---|---|
| Docker and research | shared DDE, local HTTPS, research on official systems | `health.php`, setup checks |
| Owner store | MFA, versioned backend, CRUD, search, audit, backups/restore | PHPUnit, real HTTPS e2e, isolated and empty-target restore |
| Both LLM directions | MCP/API, fixed CLI, browser with MFA, allowlist and grants | synthetic echo, redirect, forbidden destination, MFA cancel |
| Expansion | multiple owners, import, temporary permissions, dynamic DB, managed key rotation | application tests, DB lease e2e, operator commands |
| ai-os | trusted backend integration, scope config, ticket handle, plaintext path disabled when external mode is on | isolated client contract test and native MCP tests |

Configuring real remote accounts depends on the addresses and values the operator enters. We do not consider such a login verified just because the fixture works. Automatic password rotation for any site, CAPTCHA/WebAuthn and free browsing are not promised actions of this version. Further connector additions must keep the same contract of not releasing values and have their own negative tests.
