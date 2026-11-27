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

`POST /api/mcp` is a stateless JSON-RPC transport (initialize, ping, tools/list, tools/call); `/api/v1/catalog`, `/execute`, `/operations/{id}`, `/operations/{id}/cancel` and, for an executor, `/operations/{id}/release` (MCP `vault_release`, listed only for executor identities) are the HTTP interface. Both use the bearer machine identity, reject a browser Origin and apply IP and token limits. No long SSE session is needed. Operations unknown after a broker restart are marked failed and not retried.

`clients/vault.py` can run on a trusted host as a CLI or a stdio MCP bridge:

```bash
python3 clients/vault.py --config /private/client.json list
python3 clients/vault.py --config /private/client.json execute <entry-uuid> <action> --request-id <stable-id>
python3 clients/vault.py --config /private/client.json status <operation-uuid>
python3 clients/vault.py --config /private/client.json cancel <operation-uuid>
python3 clients/vault.py --config /private/client.json release <operation-uuid>   # executor: done with the handoff file
python3 clients/vault.py --config /private/client.json mcp
```

The file schema is the same (url/token), permissions 0600, no symlinks. The client does not follow redirects and does not pick up the environment's HTTP proxy. This process's file reading or shell is not a model tool; if the LLM has the same host administration, the promise of secret separation no longer holds. For the ai-os integration, use its trusted backend, not a config file in the agent sandbox.

## One-time links (Onetime Secret)

An owner sets up the workspace's Onetime Secret account in Setup › Onetime Secret: the region (`eu`, `uk`, `us`,
`ca`, `nz`), the account email, its API token (kept in OpenBao) and the lifetimes people and agents may choose. Every
link is made in that account through the API v2 (`POST /api/v2/secret/conceal`, HTTP Basic). The PHP application
calls `https://REGION.onetimesecret.com` itself, so it needs outbound HTTPS to that host; the broker is not involved.

An agent may create links only when an owner allowed it on Identities. It then has four more tools (MCP) and
endpoints (HTTP); an agent without the permission does not see the tools.

| MCP tool | HTTP | What it does |
|---|---|---|
| `vault_link_options` | `GET /api/v1/links/options` | `allowed`, `lifetimes` (seconds), `default_lifetime`, and `entries` it may use: `id`, `alias`, `fields` (keys), `approval` |
| `vault_link_create` | `POST /api/v1/links` | `text`, `entry_id`, `lifetime`, `passphrase`, `note`, `request_id` (8–80, the same one for a retry) |
| `vault_link_status` | `GET /api/v1/links/{id}` | the state and, while the link can be opened, the link |
| `vault_link_burn` | `POST /api/v1/links/{id}/burn` | destroys an unread link (or cancels one waiting for approval) |

`text` is the content, up to 10,000 characters. With `entry_id` it may name that entry's fields as `{{field}}`;
Vault puts the values in when it sends the content, so the agent never handles them (without `text`: every field,
one "Label: value" per line). Using an entry needs a grant of the action `onetimesecret.link` on it, and such a
grant always requires owner approval (the Grants form refuses it otherwise, and a request is opened as waiting
whatever an older grant says; a request is sent only with the approving owner on record): the answer is `"state": "pending"`
until the owner allows it on Requests, where the row names the fields, the lifetime and whether a passphrase guards
it; the agent checks `vault_link_status`. An agent has at most 20 links open and 60 new ones an hour (429 beyond).
When Onetime Secret refuses a text-only link (a lifetime beyond the plan: 422; the account: 503; its rate limit:
429) nothing is kept and the same `request_id` can be used again; when it does not answer, the link is `failed`. States: `pending`, `sending`, `active` (not opened), `previewed`, `revealed`, `burned`,
`expired`, `cancelled`, `failed`. The `link` field is null once the link can no longer be opened, and while the
agent is not allowed one-time links. A retry with the same `request_id` must ask for exactly the same thing (an
HMAC of entry, text, lifetime, passphrase and note is kept): it then gets the same link; anything else is 409. A
`failed` link needs a new `request_id`. `expires_at` is the lifetime Onetime Secret applied, which a plan may
shorten. An owner's Stop (Identities) cancels the agent's links that were not sent yet; a request is sent only if
the agent is still allowed, in the account and within the longest lifetime the workspace has at that moment, and
only with the draft that was approved: the final template (every field when no text was given) and the passphrase
are sealed with an HMAC when the request is made, and a draft that does not match is never sent. The link is handed
to the agent only while it is allowed one-time links and, for an entry's values, still holds the grant. The
request_id, the agent's limits and its permission are checked under the agent's row lock, the same lock Stop takes.
A new link, an account change and a send all take the workspace's settings row lock, so no link starts in an account
that is being replaced. A send that crashed after Onetime Secret answered is recovered from its kept receipt and
followed (a failure after the receipt was kept never ends the link); one that crashed before is failed. MCP keeps
`text` and `passphrase` exactly as sent (no trimming). A link whose lifetime is over is asked about once more (read, burned or expired); while Onetime
Secret does not answer its receipt is kept, for up to a week. Onetime Secret's "previewed" also covers a visit it
counts without a state change (`view_count`, `first_access`). A plan limit (`EntitlementRequired`) is reported as a
lifetime the plan does not allow (422 for an agent). Each link keeps the region and account it was made in:
after the account changes, Vault no longer checks or burns older links (they end with their lifetime).

**Boundary.** Whoever holds a link can open it once. An agent that may use an entry's values in a link can therefore
read them by opening the link itself (which also uses it up, and Links then shows it as read). Allow one-time links
only for agents that must hand credentials to people, grant `onetimesecret.link` only on the entries they need, and
keep "Require owner approval" for them. The content, the receipt, the passphrase and the link live in OpenBao
(`links/<id>`) until the link has ended, then they are destroyed; MySQL keeps who, which entry, which field names,
the lifetime and the state. The audit records `link.create` and `link.burn` without content.

CLI: `link-options`, `link-create --request-id ID [--entry UUID] [--lifetime S] [--note TEXT]` with
`{"text": "...", "passphrase": "..."}` on stdin (never as arguments), `link-status ID`, `link-burn ID`.

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
      "allowed_requests":[{"method":"POST","url":"https://service.example/login","credential_fields":["password"]},{"method":"GET","url":"https://service.example/account"}],
      "success_selector":"#account-state", "success_text":"connected"
    }
  }
}
```

API/CLI use the entry's `token` field as the Bearer and return success only on a 200. The HTTP body and headers are ignored, CLI stdout/stderr are discarded. This is a fixed recipe, not arbitrary command execution. Browser uses the login `username`/`password`; for an MFA flow add `mfa_selector` and `mfa_submit_selector`. `success_text` is compared exactly, and its text is not returned. The local context is closed; the target server session can live until the TTL it sets.

`allowed_requests` must name exactly every additional URL (including the query) and method, and a login (`browser`, `storage_state`) marks exactly one of them, a POST, as **the login request** with `"credential_fields"`: the 1–8 names of the form fields that carry the typed password (PrestaShop's front office `password`, its back office `passwd`). A connector without it, or with it on two rules, fails (`connector`). The rules cover forms, MFA, redirects and static resources (unless `static_paths` / `variable_query`, below, cover them). Only the initial GET is allowed automatically. Each navigation redirect to an allowed GET is passed through a new check; 307/308 that preserve POST and subresource redirects are rejected. A disallowed request, iframe/popup, WebSocket or download attempt aborts the whole operation with failed; even an allowed URL's Content-Disposition attachment is not passed to the browser. `timeout_seconds` can shorten but not extend the 180 s limit. In `origins`, allow only the necessary, trusted site origins; do not allow wildcards. A private target is allowed only by an exact operator CIDR, e.g. `"allow_cidrs":["10.20.30.40/32"]`; two connectors that name the same host and port must give it the same `allow_cidrs`, otherwise the destination proxy refuses every destination until the configuration agrees. The DNS proxy checks all answers and pins the real IP. HTTP is allowed only in the test Compose fixture mode. Target, selectors, headers, command and URL are not model arguments. The configuration is read on every operation, so before changing the meaning of an action, revoke its grants / finish the active operations and grant the permissions again.

DB connector: `kind=database`, `role=vault-...`, a fixed `host` and `port`. The entry type `dynamic_database` and the secret `role` must match; only SELECT 1 is executed. The DB password need not and cannot be given to the agent. Test provisioning is described in the runbook.

### Browser rules for real sites

Two opt-in rules make a real login page (PrestaShop's front and back office load dozens of theme files) possible
without opening the browser to the site:

- `"static_paths": ["/themes/", "/js/", "/img/", "/modules/"]` — a **GET** that is **not a navigation**, whose
  type is stylesheet, script, image, font, media, text track or manifest (never "other": beacons, prefetch), to a
  registered origin (`url` plus `origins`), whose path starts with one of these prefixes (each starts and ends with
  `/` and has at least one segment: `"/"` alone is refused, and so is a `.` or `..` segment) and ends with an allowed
  file extension, is allowed without listing it. Optional `"static_extensions"` narrows the built-in list (`.css`,
  `.js`, `.mjs`, images, fonts, `.vtt`, `.webmanifest`); a path with `%`, `//`, a `.`/`..` segment or another extension never matches.
  The query must be empty, or only the `"static_query"` parameters in their shapes, each at most once; a static
  query takes only `digits` / `digits:N` and `version` (e.g. `{"v": "version"}`): `hex` and `token` are refused there
  (the connector fails), since a page script chooses a static URL. Such a file is fetched **without cookies, credentials or any header of the page** (its own
  request context, a new one per file, with only an `Accept` the broker sets: no `Referer`, so a back-office URL and
  its token never reach the static host's log, no `Origin`) and its `Set-Cookie`, `Location`, `Refresh` and
  `Content-Disposition` are dropped; one that redirects is dropped,
  never followed. Any other static-typed GET (outside the paths, another origin, an analytics pixel, a back-office
  URL dressed as an image) is dropped before it leaves, without failing the login. Never covered: documents and
  navigations, frames, XHR/fetch, EventSource, WebSocket, downloads, popups, and every method but GET; those still
  need an exact `allowed_requests` entry or fail the operation. Scripts may run, but whatever they send (XHR, POST,
  navigation) is checked like any other request. The older `"static_subresources": true` is refused (the operation
  fails) so that an old connector is noticed.
- `"variable_query": {"rand": "digits:13"}` on one `allowed_requests` rule — the named query parameters may differ,
  each at most once and only in its shape; the method, scheme, host, port, path and every other parameter must still
  be exactly the rule's (raw, undecoded). Shapes are a fixed vocabulary, never a pattern: `digits` / `digits:N`
  (1–N digits, N ≤ 20), `hex:N` (exactly N lowercase hex digits, 8 ≤ N ≤ 64), `token` / `token:N` (16–N base64url
  characters `A-Z a-z 0-9 - _`, 16 ≤ N ≤ 128, 64 when left out: a CSRF token such as Symfony's `_token`) and
  `version` (`8`, `8.1.2`, at most four parts). A percent-encoded or `+` key or value never matches. It exists for a nonce or a token the site makes,
  e.g. PrestaShop's `index.php?rand=<ms>` login XHR (`digits:13`) and
  `index.php?controller=AdminDashboard&token=<md5>` after it (`hex:32`). A `token` value is opaque: only the URL check
  below stands between it and an encoded entry value, so prefer `ignore_requests` for a request the login does not need.
- `"ignore_requests": [{"method": "POST", "url": "https://shop.example/admin123/index.php/common/notifications"}]` — a
  request from the main frame, not a navigation, with this method, scheme, host, port and path (any query; the
  `url` itself has none and is on a registered origin) is **aborted before it leaves, without failing** the login.
  For what a page fires on its own and the login does not need: PrestaShop 8 fetches the wishlist module on every
  front-office page (`GET /module/blockwishlist/action`) and its dashboard POSTs notifications and loads its widgets
  by XHR on document-ready. It is decided before anything is fetched, so an ignored path under `static_paths` is
  dropped, never fetched as a static file. A request that is both listed and ignored is sent (listed wins). A
  navigation, or a request from a frame, is never ignored (it fails the operation as before), and an ignored URL that
  carries an entry value still fails the operation (the URL check below comes first).

**After the login is confirmed** (`success_selector` has `success_text`) no new request is sent: one the page makes
later (a dashboard timer, a late XHR), and one that was still waiting for the application's confirmation at that
moment, is aborted silently, and nothing the page still does fails the operation. A request that had already been
sent is waited for (at most 15 s, else the operation fails), so the state is read after the site's last answer,
never while a request is on its way; then the browser is closed. Before that point, any request that is neither
listed, static, nor ignored fails the operation.

Every request URL (navigation, XHR, static, a redirect target) is also checked for the entry's values: one that
carries a value, or is longer than 8192 characters, is denied and the operation fails. So is every request the
broker sends with the page's own headers and body (a listed request): its headers (names and values, the `Cookie`
header too) and its body (at most 64 KiB, else denied) must carry no value, except the login request's
`credential_fields` in a urlencoded form body; anything else in that body (another field, a JSON or multipart body)
is checked like any other. A static file is sent with the broker's own headers and no body. This is a best-effort
check, not a guarantee.

**Which values are looked for**: every stored field of the entry except the username and the entry type's *Text*
fields: the password (it is typed into the page), every *Secret (masked)* and *Multi-line text* field (Setup › Entry
types), and a field the type no longer has whose value is still stored. The username and the *Text* fields (an admin
URL, a shop name) are not: they are not secrets, and a URL field such as `https://shop.example/admin123` would
otherwise refuse the very first navigation. Keep anything secret in a secret field. (An older application that does
not name the fields makes the broker check every field but the username.)

**What the check recognises, exactly** (`broker/leak.py`), in a URL and in the exported cookies and items:

- each text on its own, and up to four layers of: reversal, percent-decoding, `+`-as-space decoding, JSON (every
  string, key and value of an array or object), base64 (standard and URL-safe alphabet, with or without padding, at
  each of the four alignments inside a token of 4 characters or more, a dangling last character trimmed; and a whole
  text of 2–3 characters, which is what one or two bytes encode to) and hex (any run of 4 or more hex digits, odd or
  even, at both nibble alignments, so a value after one stray hex digit is found); bytes that are not UTF-8 are
  searched as they are (invalid bytes dropped), and only their printable ASCII runs of 8 characters or more are
  decoded further;
- a value equal to one of those (any length; a value of one or two characters is found only this way);
- a value of 3–5 characters as a whole word of one of those: not directly next to a letter or digit (`pin:7788;`
  or `/7788/`, not `a17788b`), so that a short value is not found by chance inside a long hex or base64 session
  token (measured on 300 random 1 200-character hex session cookies: a random 3-letter value refused 1, 3 digits or
  4–5 characters none; a whole-word match in decoded bytes stays possible). A short value that is an ordinary word of
  the site (`login`, `index`, `https`) is still found wherever the site uses that word and refuses every such login
  (`failed`, reason `leak`): keep such a value out of secret fields;
- a value of 6 characters or more contained in one of those, or its reversal contained in one;
- a value of 8 characters or more cut once into two parts of at least 4, where one text (or one of its tokens split at
  whitespace and `& ; : , | = ? # / . " '`) ends with the first part and another begins with the second.

Not recognised, among others: a value split into three or more parts, interleaved, encrypted, compressed, or encoded
in any other way; and a non-ASCII value percent- or hex-encoded in a charset other than UTF-8 (windows-1257,
ISO-8859-x): its bytes are not the UTF-8 bytes the check decodes to. Each text has its own budget (candidates and bytes, in proportion to its length); a text that
exhausts it counts as carrying a value (the state or request is refused): the check never stops silently.

What this leaves open, plainly: a static file goes only to a registered origin, under an operator path, without the
session and with a query of only the `static_query` numbers or versions (`digits:N` at most 20 digits, `version`
at most four parts of up to 6 digits, 24 in all, per parameter); it cannot change state with the logged-in session or
read data back. A page script chooses the *path* under a static path (up to 512 characters, any file name with an
allowed extension) as freely as the query, so a hostile page could encode a value into a path in a way the URL check
does not recognise and send it to a registered origin. Register only origins that are
the site itself (they receive the password at login anyway), keep `static_paths` narrow, and treat a page that runs
someone else's script (an XSS in the back office) as able to use the browser while the login runs. Negative cases are
in `tests/test_browser_policy.py` and the integration fixture.

## Executor handoff (`storage_state`)

For a trusted executor on the Vault host (the Server OPS Playwright runner), Vault logs in and leaves only the login
session. The executor's suites start with `storageState`; they never see the password, and the LLM agent that orders
a run never sees anything.

```json
"shop.customer-session": {
  "kind": "storage_state", "url": "https://shop.example/en/login", "origins": [],
  "username_selector": "#field-email", "password_selector": "#field-password", "submit_selector": "#submit-login",
  "static_paths": ["/themes/", "/js/", "/img/", "/modules/"],
  "allowed_requests": [{"method": "POST", "url": "https://shop.example/en/login?back=my-account", "credential_fields": ["password"]},
                       {"method": "GET", "url": "https://shop.example/en/my-account"}],
  "ignore_requests": [{"method": "GET", "url": "https://shop.example/module/blockwishlist/action"}],
  "success_selector": "#main .page-header h1", "success_text": "Your account",
  "entry_types": ["prestashop_customer"], "ttl_seconds": 900
},
"shop.admin-session": {
  "kind": "storage_state", "url": "https://shop.example/admin123/index.php?controller=AdminLogin",
  "username_selector": "#email", "password_selector": "#passwd", "submit_selector": "#submit_login",
  "static_paths": ["/admin123/themes/", "/js/", "/img/", "/modules/"], "static_query": {"v": "version"},
  "allowed_requests": [{"method": "POST", "url": "https://shop.example/admin123/index.php", "variable_query": {"rand": "digits:13"}, "credential_fields": ["passwd"]},
                       {"method": "GET", "url": "https://shop.example/admin123/index.php?controller=AdminDashboard", "variable_query": {"token": "hex:32"}}],
  "ignore_requests": [{"method": "POST", "url": "https://shop.example/admin123/index.php/common/notifications"},
                      {"method": "GET", "url": "https://shop.example/admin123/index.php"}],
  "success_selector": "#header_employee_box", "success_text": "account_circle",
  "entry_types": ["prestashop_admin"], "ttl_seconds": 900
}
```

Both were run against a stock `prestashop/prestashop:8` (8.2.9, default themes and modules, friendly URLs on), before
`credential_fields` and the header/body check existed; that run has not been repeated with them (the field names
are the forms' own: `password` in the front office's form post, `passwd` in the back office's urlencoded XHR). The
login succeeded, the exported state opened the back office and *My account* in a fresh browser, and without the
`ignore_requests` lines both failed (the wishlist fetch on the login page; the notifications POST and widget XHRs on
the dashboard). The second ignore rule drops the dashboard's non-navigation XHRs to `index.php` (widgets, the address
helper); the navigation to the dashboard is still the listed rule. `success_text` `account_circle` is the icon text of
PrestaShop 8's employee menu. Other themes, modules or versions differ: check them with a synthetic account first. The entry uses the `username` and `password` fields; its type must be one of `entry_types`
(otherwise the grant is refused and a run is 422). `ttl_seconds` is 1–1800 (default 900); `mfa_selector` is not
allowed (runs are unattended; a connector with it cannot be granted).

**What the file holds** (`{"cookies": [...], "origins": [...]}`), narrowed at the source:

- cookies: only **HttpOnly** ones (a page script cannot write those; PrestaShop's session cookies are HttpOnly) plus
  any the connector names in `"cookie_names"`, and only those for the **login host** (the host of `url`). Other
  hosts that must get the session are named in `"cookie_hosts"` (each a registered host); a static-file origin in
  `origins` gets none. Each cookie is exported **host-only** for its host (PrestaShop sets its main cookie for the
  parent domain `.shop.example`: it is narrowed, so the executor's browser never sends it to a sibling subdomain) and
  as a session cookie (`expires: -1`: the browser forgets it at close; the site's own lifetime still applies on its
  side). A host-only and a parent-domain cookie with the same name and path cannot both be host-only for one host:
  such a state is refused rather than one silently dropped. A **partitioned** cookie (`Partitioned`, CHIPS) that would
  be exported refuses the state too (`failed`, reason `state`): the file format cannot keep its partition, and without
  it the executor's browser would send it as an ordinary cookie. It is looked for in the original `Set-Cookie`
  headers of every answer the broker fetches (each redirect hop, and an answer that arrives after the login was
  confirmed), not only in the browser's cookies: Playwright's request client stores such a cookie in the context
  without its partition, so the exported state alone could not show it;
- localStorage: **none** by default; a connector names the keys it needs in `"storage_keys"` (registered origins
  only);
- sessionStorage, IndexedDB and everything of other origins: never.

On top of that the broker refuses (operation `failed`, no file) a state in which a cookie's name, value or path or an
item's name or value carries one of the values above, as the check above recognises it (also split in two across
cookies and items). Only what the site set is checked, not the file's own JSON (its domain and field names would
match ordinary passwords). This check is **best effort**: it recognises the encodings listed, not every encoding a
page could invent. The boundary, plainly: the session is issued by the target site. If
that site (or a script running in it during the login) is compromised, no output filter can guarantee what it encoded
into the session; narrowing the source (HttpOnly cookies, no storage) is what keeps an ordinary page script out.

**Executor identity.** In Access › Agents tick *Executor* when making the identity (it cannot be changed later, and
an executor does not create one-time links). Only an executor may be granted a `storage_state` action; for any other
identity the action does not exist (404). Grants may be standing and without approval, since runs happen at night.
Limits: one live handoff per entry (whoever asks, also at the same moment), 60 per executor per hour, the usual five
open operations per identity, and at the broker four logins at a time (a lease whose file is ready does not count)
and 16 live leases (429 beyond). When the broker refuses a start (busy, its directory not clean) or cannot be reached at
all (connection refused, its name not resolved: a stopped broker container is not in Docker's DNS, a connect
timeout) the operation fails at once and the answer is 429 or 503 with no operation left running; retry with a new
`request_id`. Only a timeout after the start was sent leaves the operation `running` for status to settle. A retry of
the same `request_id` while the first start is still on its way does not fail it: a status the broker does not know
the operation for counts as "still on its way" for 30 s from the claim, until the start is confirmed (the broker's
answer to the start, a status in which it knows the operation, or its `written` report); after the confirmation an
unknown operation is a broker restart and fails at once. A handoff whose file the broker
could not remove (or has not reported removed) keeps its entry reserved (429) until the removal is confirmed.

**The executor's sequence** (CLI shown; HTTP and MCP are the same calls):

```text
1. POST /api/v1/execute         {"entry_id": "<uuid>", "action": "shop.customer-session", "request_id": "<8-80 chars>"}
   → {"id": "<operation-uuid>", "state": "running", "ok": false, "expires_at": "...", "ready": false, "lease_expires_at": null}
2. GET  /api/v1/operations/<operation-uuid>       every 1-2 s until "ready": true, or a final state (failed, expired, revoked)
   → {"id": "...", "state": "running", "ok": false, "expires_at": "...", "ready": true, "lease_expires_at": "2026-11-27T03:15:00Z"}
3. read  <handoff directory>/<operation-uuid>.json  (e.g. /run/vault-handoff/prod/…) and start the suite with it
   as Playwright storageState; read it once, keep it only in memory
4. POST /api/v1/operations/<operation-uuid>/release  {}     (MCP vault_release, CLI release)
   → {"state": "succeeded", "ready": false, ...}; the file is gone (ENOENT)
```

The answer never contains the path or a value: the executor derives the path from the fixed directory and its own
operation ID; the directory is 2710, so it cannot list it and must open the file by name. `expires_at` and
`lease_expires_at` are RFC 3339 UTC; once the file is recorded, `expires_at` is the lease end + 10 s. Until `ready` is true the file is either absent or 0600 (unreadable for the group): it becomes 0640 only
after it is complete, fsynced and recorded by the application; never read it earlier. Release as soon as the suite
has loaded the state; otherwise the TTL removes it (state `expired`, at `lease_expires_at`). Revoking the grant or
the identity, or changing the entry, removes it within about 1–2 s, at most about 11 s while the application answers slowly (`revoked`; the broker checks every live lease each
second, all at once, each check bounded to 5 s, and that loop waits on nothing else). The final event (`removed`, with
its reason) is sent to the application after every removal and repeated every 5 s (at most 8 at a time) until
confirmed; the broker keeps the unconfirmed ones (IDs and reasons only) in its handoff directory, so a broker restart
does not lose them. A host reboot empties that directory: then the application settles the operation itself, at its
next status or within 10 minutes (`vault:links`), when the broker no longer knows it; until then the entry stays
reserved (429). A file whose answer to `written` came after the lease end is removed without ever being readable. A short application outage (a PHP restart, a stalled
database) also ends every lease as `revoked`: the broker fails closed when it cannot confirm a lease; start a new
run. A broker restart removes every file (`failed`). Use a new `request_id` per run; repeating one returns the same
operation and never writes a second file. The process needs only search access through the `vault-handoff` group
(docs/operations.md) and its own client config file.

**Why a run failed.** The answer says only `failed`; the activity log (`operation.failure`) records one coarse reason,
never a value, URL or selector: `policy` (a request, frame, popup, download or WebSocket the connector does not
allow), `leak` (a request URL or the session state carried an entry value, or could not be checked completely),
`unauthorized` (the application withdrew the operation during the login), `login` (a selector, a page timeout, the
success text), `state` (a partitioned or duplicate cookie, an empty or too large state), `connector` (the connector
is not valid for its kind), `handoff` (the file could not be written, recorded or removed), `restart` (the broker
restarted) or `error`.

**A state already read cannot be revoked.** Vault removes the file; whatever the executor loaded from it stays a
valid session at the site until the site ends it (logout, its own expiry). To end such a session early, log it out
at the site or change the account's password there.

**The executor's own artifacts.** Playwright's trace (`trace: 'on'`, `'retain-on-failure'`), HAR recording and
video keep request headers, including `Cookie`: a trace of a failed test is a live back-office session, and an LLM
that reads traces to explain a failure gets it. For suites that start from a handed-off state: turn trace and HAR off,
or keep them only for unauthenticated projects (or scrub `Cookie`/`Set-Cookie` before anything else reads them);
never pass `storageState` content, traces or HAR to an agent; log out at the end of the suite (the session at
PrestaShop outlives `release`); and shorten the back-office cookie lifetime in PrestaShop (*Advanced parameters ›
Administration › Lifetime of back office cookies*). Entries for `storage_state` should hold only `username`,
`password` and secret fields: a *Text* field is not looked for by the leak check, so do not put anything secret in
one.

## Starting a connector

Start a new connector with a synthetic account: check the normal response, secret echo and encoded echo, redirect, a disallowed address, revocation and errors. Only then does a human enter the real credentials in the web UI. The `ssh` type allows storage/viewing but has no execution recipe in this version.
