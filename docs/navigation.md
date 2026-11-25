# Web UI structure

How the signed-in web UI is organised: which page owns which job, who sees it, and where each form lives.
The look follows the ops design system (dark monospace, amber accent, `--token` colours, `.board`/`.list`/`.deck`
components, one solid `go` button per view, no horizontal scroll at 390 px). This file is about structure.
When it and the code disagree, fix one of them in the same change.

## Principles

- **One job per page.** A page answers one question ("which secrets do we have?", "what may agents do?").
  Forms live on the page whose list they change, next to that list.
- **Summary first, detail second.** Stats or the attention list, then the main table, then forms.
- **What needs you is visible from everywhere.** Pending agent requests show as a count on the Agents nav item.
- **Personal things are not workspace things.** Password, sudo-style confirmation and sign-out live under the
  account menu, not at the bottom of every page.
- **Role decides what is shown.** A link a role cannot use is not rendered. The server still returns 404 for it.

## Vocabulary

Use these words in every UI text. Code names may differ.

- **Entry** — a stored credential.
- **Agent** — a machine identity with a bearer token. The tab listing agents is **Identities**.
- **Grant** — one agent may run one action with one entry. Not "permission".
- **Request** — an agent asking to run a granted action (an `operations` row). Not "operation".
  States read "waiting for approval" (`pending`) and "needs MFA code" (`requires_user_action`).
- **Sign out** — not "Lock".
- Roles are shown as neutral `chip role`, never with state colours.
- A tabbed area keeps the area name as its h1 (Agents, Setup) with the tabs right below it.

## Header (every signed-in page)

```
VAULT  [workspace ▾]   Entries · Agents (2) · Members · Setup · Activity        [name ▾]
```

- **Workspace switcher** (`<details>` menu): the user's workspaces with their role, the current one marked, and
  "New workspace" → `GET /workspaces/new`. Switching keeps you on the same section when the role allows it,
  otherwise it opens Entries.
- **Primary nav**, filtered by role in the current workspace:

  | Item | Pages it is active on | Roles |
  |---|---|---|
  | Entries | `/`, `/entries/*`, `/workspaces/{w}/import` | all |
  | Agents  | `/workspaces/{w}/requests`, `/agents`, `/grants` | owner |
  | Members | `/workspaces/{w}/members` | owner |
  | Setup   | `/workspaces/{w}/structure`, `/types` | owner, editor |
  | Activity | `/workspaces/{w}/audit` | owner |

  The Agents item carries a count chip when requests wait for a decision or an MFA code.
- The header data comes from a view composer (`AppServiceProvider`): `$navSpaces`, `$navRole` and `$navWaiting`,
  derived from the `$space` each page passes. A workspace page that does not pass `$space` shows only Entries.
- **Account menu** (`<details>` under the user's name): Account → `GET /account`, Sign out (POST `/logout`).

## Areas

### Entries (all roles)

- `/` — the entry list for the current workspace: header with `+ Add an entry` (the one `go`) and `Import`
  (editor+), search and filters (project, environment, type, state), the board, pager. No workspace or project
  forms on this page; empty states link to Setup.
- `/entries/{id}` — one entry: facts, values (reveal), versions, lifecycle, and for owners the agent grants for
  this entry (read-only, with a link to Grants filtered by this entry), its requests and its activity.
- `/entries/new`, `/entries/new?entry=` — add / update form.
- `/workspaces/{w}/import` — paste a JSON import (editor+).

### Agents (owner)

Three tabs under the h1 "Agents": **Requests · Identities · Grants**.

- `/workspaces/{w}/requests` — agent requests. "Waiting for you" first (approval or a site MFA code), each row
  saying which agent asks to run which action on which entry and environment, then recent history. This is where
  Allow once / Deny / Continue sign-in live.
- `/workspaces/{w}/agents` — agent identities: state, expiry, how many active grants each has (links to Grants
  filtered by that agent), Revoke; the "Add an agent" form. Creating an agent shows the one-time token page,
  whose next step links to Grants for that agent.
- `/workspaces/{w}/grants` — what each agent may do: the grant list (filter `?agent=` and `?entry=`), Revoke,
  and the "Grant an action" form (pre-selected from the filter).
- `/workspaces/{w}/settings` — old address; redirects to Requests.

### Members (owner)

- `/workspaces/{w}/members` — people in the workspace with a role select and Remove on each row, the "Add a
  member" form, and a short role explanation.

### Setup (owner, editor)

Two tabs under the h1 "Setup": **Projects · Entry types**.

- `/workspaces/{w}/structure` — projects, environments, the environment template, the production flag.
- `/workspaces/{w}/types` — entry types and their fields.

### Activity (owner)

- `/workspaces/{w}/audit` — the workspace audit log with actor and subject names (not ids) and filters.

### Account (any signed-in user)

- `/account` — who you are, sensitive-action confirmation state (with the confirm form), change password, sign out.
- `/confirm?return=/path` — confirm your password. A sensitive POST without a fresh confirmation throws
  `App\Vault\ConfirmationRequired` (403), which renders this form instead of a generic error. After confirming you
  go back to `return`: a same-origin path only, never `/confirm`, `/login`, `/logout` or `/mfa`. Because
  `Referrer-Policy: no-referrer` hides the Referer, the return path falls back to the last page shown (kept in the
  session as `last_page`). A wrong password re-renders the form with 401.
- `/workspaces/new` — create a workspace.

## Ownership of files

| Area | Views | Controller methods |
|---|---|---|
| Shell, account, confirm | `layout`, `account`, `confirm`, `workspace-new`, `error`, `tabs` partial | `Account::*`, `Identity::confirm` |
| Entries | `catalog`, `entry`, `entry-form`, `import` | `Workspace::index`, `entryForm`, `detail`, `importForm` |
| Agents, members | `requests`, `agents`, `grants`, `members`, `token`, `operations` partial | `Workspace::requests`, `agents`, `grants`, `members`, `settings` |
| Setup | `structure`, `types` | `Workspace::structurePage`, `typesPage` |
