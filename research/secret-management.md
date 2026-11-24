# A credential vault for people and LLMs: research and proposed design

Reviewed 2026-11-10 for this vault project, a planned PHP 8.5 and MySQL 8.4 application. This documentation review and architecture proposal did not audit a deployed system or test specific versions for compatibility. No real secrets were read and nothing was installed.

**Main recommendation:** Build a secret catalog for people and a broker for narrowly defined actions on top of a trusted secrets backend. The LLM gets entry identifiers, permitted metadata and action results. Only the trusted executor gets the actual value. The LLM API must exclude `get_secret`, general-purpose shell access and unrestricted authenticated HTTP proxying.

**API/CLI and browser logins have equal priority.** Both are part of the first LLM phase. The [architecture proposal](../docs/architecture.md) defines the execution contract and acceptance scenarios. This priority does not change the facts documented by vendors.

The documentation findings below describe vendor-documented mechanisms. The remaining sections give **design recommendations and threat model conclusions**. The sources do not guarantee these outcomes.

## Documentation findings and application

Five primary official references follow, with additional pages documenting individual features.

### 1. 1Password: references and `op run`

Source: [Load secrets into the environment](https://www.1password.dev/cli/secrets-environment-variables). During the review, the old `developer.1password.com/docs/cli/secrets-environment-variables/` address redirected here.

**Findings.** A secret reference identifies a vault, item, section and field by name or ID, for example `op://vault/item/field`. `op run` resolves references and starts a process with the actual values in environment variables. The documentation warns, “You should assume that processes on your computer can access the environment of other processes run by the same user.”

**Application.** Use stable identifiers in the repository and agent context. A reference identifies a location. The component that resolves it must check permissions. Use `op run` to launch a trusted program. An LLM-controlled program can read a secret in its environment, so this provides no isolation from that program.

### 2. 1Password Connect: backend integration example

Source: [1Password Connect](https://www.1password.dev/connect).

**Findings.** Connect provides a private REST API for applications to access 1Password items and vaults. It caches a copy of the data on the organization's infrastructure, described as “cache your data in your infrastructure.”

**Application.** A backend adapter can read secrets through Connect where 1Password is already in use. Restrict the Connect API and credentials to the broker and keep them out of the LLM tool. The local copy is another component to protect. A private REST API still exposes returned secrets to its client.

### 3. Bitwarden Secrets Manager: projects and machine identities

Source: [Machine Accounts](https://bitwarden.com/help/machine-accounts/). Additional reference: [Projects](https://bitwarden.com/help/projects/).

**Findings.** A machine account represents an application or running process. It receives project assignments and permissions, and an access token allows programmatic access to secrets. `Can read` exposes project secret values to the reader. Projects group secrets, people and machine accounts. The reviewed page says a secret belongs to one project at a time.

**Application.** Give agents their own identities and the broker separate backend identities. Grant the agent `metadata:read` and specific `action:execute` permissions. Restrict broker reads to secrets in the required environment. Avoid sharing one token across projects. A `use` permission would introduce additional broker semantics distinct from Bitwarden's `read`.

### 4. Infisical: environments, paths and dynamic secrets

Source: [Access control in Secrets Management](https://infisical.com/docs/documentation/platform/secrets-mgmt/concepts/access-control). Additional reference: [Dynamic secrets](https://infisical.com/docs/documentation/platform/dynamic-secrets/overview).

**Findings.** Secrets belong to an environment and path. Environments separate `development`, `staging` and `production`. Paths group secrets under locations such as `/redis` or `/checkout-service`. Project roles restrict accessible environments and paths. Dynamic secrets are generated on demand and support revocation. At the time of review, the dynamic secrets page specified the “Advanced” plan and a license requirement for self-hosting.

**Application.** Make the environment a mandatory security boundary and use folders for additional grouping. Never fall back to `prod` when a `dev` entry is missing. Plan dynamic credentials for a later phase after evaluating licensing and target service capabilities. Short lifetimes reduce the window for use. Credentials can still leak or be misused while valid.

### 5. OpenBao: response wrapping and dynamic DB credentials

Source: [Response wrapping](https://openbao.org/docs/concepts/response-wrapping/). Additional reference: [Databases](https://openbao.org/docs/secrets/databases/). The reviewed pages identified documentation version 2.7.x.

**Findings.** Response wrapping replaces the API response with a single-use token. The recipient retrieves the response via `unwrap`. Token lifetime is independent of the secret's lifetime. The DB secrets engine generates unique credentials per request based on roles and supports leases and revocation. The documentation describes revocation within a reasonable time after lease expiry. Active sessions may survive expiry.

**Application.** Use wrapping to transfer responses between trusted components. Keep wrapping tokens away from the LLM because anyone who can unwrap one can retrieve the secret. For a future DB action, the broker could issue short-lived, action-specific credentials to its executor, revoke them afterward and monitor revocation failures. Test dynamic MySQL 8.4 user creation, privileges, revocation and active sessions in an integration test.

## Trust boundaries and API

Reduce the risk of secret values reaching model inputs, tool responses, conversation history and agent-readable artifacts. Telling a model “do not reveal the password” provides no technical access control.

Recommended flow:

```text
Human → separate web login → catalog / input / reveal according to permissions
LLM → authenticated action API → policy → trusted broker → target service
                                                 ↓
                                           secrets backend
LLM ← defined result only ← output schema ← broker
```

`get_secret(id) → plaintext` defeats the objective if an LLM tool receives the response. A backend may expose an internal read method exclusively to the trusted broker. Human reveal requires separate authentication and permission. Agent identities must have no access to it.

Proposed agent API:

- `list_entries(workspace_id, project_id, environment_id, type)` returns only permitted metadata and available actions.
- `execute_action(entry_id, action_id, parameters, request_id)` accepts only a server-defined action with a strict parameter schema.
- `get_action_result(operation_id)` checks that the operation belongs to the caller and workspace before returning a limited result.

A first action could be `database.check_connection`. The server selects a registered MySQL target, connects and runs a fixed `SELECT 1`. It returns only `ok`, a generic error code and `operation_id`. The connection string, password and raw DB error stay private. The LLM cannot supply SQL, shell commands, hosts, proxies, URLs or arbitrary HTTP headers.

The agent must have no write access to broker code, the action registry, destinations or policy. An allowlist loses its protection if the agent can edit the executor script before launch. Deploy the production broker from a reviewed artifact. Keep agent-editable bind mounts out of the deployment.

## Exfiltration risks and controls

**This section gives the threat analysis.** Each control limits specific channels. Other leaks may still be possible.

| Channel | How a secret can reach the agent or another recipient | Recommended control |
| --- | --- | --- |
| Arbitrary shell / process | The agent selects code that reads a supplied secret, changes its encoding and sends it out. | The action API rejects commands and executables. The executor loads no agent-controlled code, plugins or configuration. |
| `stdout`, `stderr`, errors | An SDK, HTTP or DB error, diagnostics or the process itself prints the value. | Use a fixed result schema and generic error codes. Exclude raw output. Masking adds a layer of protection but can miss transformed or partial values. |
| `env`, arguments, files, memory | Process environments, argument lists, `/proc`, temporary files, core dumps or shared volumes expose the value. | Use a separate identity and isolation, with no shared PID namespace or agent-readable volumes. Avoid secret arguments and retain values as briefly as possible. Environment variables can be read. |
| Browser DOM and screenshots | `type=password` masks the display but allows DOM / JS reads. A “show” button or automatic copy exposes the value. | Keep the human vault page and session inaccessible to the LLM browser. Send secrets to the DOM only after an authorized reveal. Isolate the authenticated browser executor and test it alongside API/CLI in the first LLM phase. |
| Traces, HAR, video, logs, telemetry | Request bodies, Authorization headers, cookies, network records, session replays and screen recordings can retain credentials. | Disable content recording and session replay on secret-handling paths. Log only approved fields. Test trace and error exports using dummy secrets. |
| Prompt injection | A website, README or secret description instructs the agent to reveal a value or change the destination. | Treat metadata and external responses as untrusted data. The server checks identity, permission, parameters and destination independently of the prompt. The agent cannot expand its own policy. |
| SSRF and HTTP redirects | The LLM changes a URL, redirect, DNS or proxy and sends credentials to an unintended server or reaches a metadata service. | Allow only preregistered destinations with a server-selected scheme, host, port and path. Disable redirects, verify TLS, restrict network egress and prevent DNS rebinding. Allow internal addresses only for specific registered services. |
| Authenticated result | Even a trusted destination can echo a token or return sensitive business data. | Exclude general-purpose proxies. Each action defines response fields, size and allowed content. Validate both request and response. |
| Sessions and delegated tokens | A cookie, bearer token or wrapping token can confer similar powers while the password stays hidden. | Treat these as secrets too. Give the LLM only the broker operation ID, with no direct target session. |

If the agent has broker host `root` access, the Docker socket, a debugger / `ptrace`, vault keys or arbitrary process execution as the broker, **secret isolation cannot be guaranteed**. A separate container on an agent-administered host does not establish this boundary. Run the trusted broker and backend on a VM or server outside agent administration. Local Docker can serve as a development environment with dummy data.

Even with secret values hidden, `execute` permission allows actions on behalf of another account. Limit action scope, frequency and duration, support revocation and audit use. Define a separate human approval policy for destructive or sensitive production actions.

## Proposed organization

The hierarchy is `workspace → project → environment → entry`. The `type` field identifies the entry type and serves as a filter. An optional `folder` groups entries within an environment. Human navigation can show `workspace / project / environment / type` without duplicating entries for each filter.

- **Workspace:** Isolation boundary for an organization, client or personal space, with separate membership.
- **Project:** A product or system belonging to one workspace.
- **Environment:** A project's `dev`, `staging`, `prod` or explicitly named `shared` environment. Never switch environments silently.
- **Entry:** Stable UUID, name, entry type (`login`, `database`, `api_token`, `ssh_key`, `certificate`), environment, folder, owner, tags, rotation status and backend reference. The backend holds secret values. Usernames, URLs and notes may also be sensitive. Keep their visibility explicit.
- **Machine identity:** Identity for a specific agent or integration, with independent expiry and revocation.
- **Action definition / grant:** Associates an entry and permitted action with parameters, destination, principal, validity and approval rules.
- **Audit event:** Records who did what, when and in which environment, plus policy version, result code and operation ID. Exclude secrets and raw parameters.

MySQL relationships and queries must enforce that the project, environment and entry belong to the same workspace. Knowing a UUID grants no access. Check permissions on lists, individual entry requests, actions and results. Reevaluate grants when moving an entry to another environment.

## Web navigation for people

Place the workspace selector at the top. Put Projects, “Entries”, “Machine identities”, “Access” and “Activity” on the left. Within a project, show an environment switcher, an environment folder tree and entry type filters. Identify `prod` with both text and color. Keep the environment visible when opening entries or running actions.

List each entry's name, type, owner, update time, rotation status and permitted actions. Exclude secret values from initial HTML, JSON and the search index. The proposed entry card groups overview, access, actions and history. Separate “Copy reference” from revealing or copying a secret. Reveal requires human permission, reauthentication when needed and an audit event. Disable caching for reveal responses and request body logging for secret input.

The first version can direct users to the existing backend's secure input and reveal interface. If integrated web editing is required, keep its secret-handling path separate from the agent API. Keep temporary values out of application queue messages, session storage and telemetry.

## Existing backend versus custom cryptography

Start with one backend adapter. Use a suitable backend already in operation. For a new self-managed deployment that will need dynamic DB credentials, evaluate OpenBao first. 1Password Connect fits existing 1Password deployments. Bitwarden offers a clear project and machine account model. Infisical closely matches the proposed environment and folder structure, though individual features need a licensing review.

The PHP 8.5 application and MySQL 8.4 would store metadata, policies and audit events. The backend would store values and versions and may use different infrastructure from MySQL. Define a narrow adapter interface and support one vendor in the first phase. A write to the external secrets backend and a MySQL transaction are separate operations. Define explicit states and repeatable reconciliation for discrepancies.

Avoid starting with an encrypted `password` column and a shared key in the application's `.env`. Even a trusted encryption library leaves key storage, rotation, backups, integrity, versioning, authorization and recovery to the operator. A backend still requires TLS, scoped broker credentials, secure initial provisioning, a key recovery plan, updates and tested backups. The broker's bootstrap secret needs a location accessible before the backend it unlocks is available.

## First implementation phase and acceptance criteria

1. **Docker foundation with dummy data.** Use PHP 8.5 and MySQL 8.4 with explicit network and file access. At this stage, make no claim of protection from an agent administering the host.
2. **Catalog and authorization.** Model workspace, project, environment, entry and entry type. Authenticate people and give the agent a separate identity. Deny access by default. Return only authorized metadata from the API.
3. **One backend adapter.** Link a catalog entry to a secret and test with a synthetic value. Keep backend tokens and read endpoints inaccessible to the agent. Provide human input and reveal through the backend web UI or a separately secured application flow.
4. **Broker actions for both paths.** Provide one API read and one fixed CLI recipe. `database.check_connection` remains an example of a bounded action. For the browser, log in to a registered site and perform one permitted read using an isolated session and human MFA handoff outside the LLM context. Target systems have yet to be selected. Start with controlled test systems. The audit log and agent API expose only the defined result. Exclude general-purpose shell, SQL, HTTP proxy, DOM and JS access and browser session export.
5. **Security acceptance tests.** Reject a foreign workspace ID, unauthorized `prod` access, a disabled identity and invalid parameters. Verify that dummy secrets stay out of API responses, logs, traces and the initial web DOM. Test backend errors, timeouts, destination changes, prompt injection in metadata and attempts by the agent to edit executor code. Verify that the agent cannot reach the backend network, credentials or executor files. For browsers, also test that cookies, tokens, CSRF values and artifacts are inaccessible. Check MFA continuation, context isolation, redirect / iframe / popup policy, session expiry and revocation. Both paths must pass. Searching logs for text alone provides insufficient security evidence.

Later phases can add dynamic DB credentials, lease revocation and monitoring of revocation failures, sensitive action approvals and more specific integrations. The first LLM phase already includes the browser login threat model because the DOM, cookies, screenshots and traces add channels for secret data.

**Phase outcome:** A person can find an entry in the correct environment. An authorized agent can perform defined API/CLI and authenticated browser actions. The agent API exposes neither secret values nor session data. Completion requires verification of both paths. This claim assumes the isolation described above and is limited to the scope of testing. It excludes agents that control the trusted host or executor process.
