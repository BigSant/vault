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
- **Entry type** — the set of fields an entry has. Headings, links and buttons say "entry type" ("+ Add an entry
  type", "Add entry type", "Save entry type"); a field or column label may say just "Type".
- **Agent** — a machine identity with a bearer token. The tab listing agents is **Identities**; its rows and its
  empty state still say "agent" ("No agents yet.").
- **Grant** — one agent may run one action with one entry. Not "permission".
- **Request** — an agent asking to run a granted action (an `operations` row). Not "operation".
  States read "waiting for approval" (`pending`) and "needs MFA code" (`requires_user_action`).
- **Password confirmation** — the one name for the 5-minute confirmation before sensitive actions (Account row,
  the confirm page, the inline field). Not "identity", not "sensitive actions".
- **Sign out** — not "Lock". Create actions in the signed-in UI: headings and links "Add a/an X", submit
  buttons "Add X" ("Add entry", "Add grant"), never "New" or "Create". The sign-in pages may say "Create account". **Edit entry**. **Expires (UTC)**.
  **rotation due**. Password fields are labelled **Current password**.
- End dates of agents and grants read **Expires**, **Expired** or **Revoked**, then the date and UTC, then the
  reason on a line of its own. One partial (`end-date`) prints them on Identities, Grants and an entry's Agent access.
- Roles are shown as neutral `chip role`, never with state colours.
- A tabbed area keeps the area name as its h1 (Agents, Setup) with the tabs right below it. A page with an add
  form further down puts one quiet "+ Add a/an X" button in the head row beside the h1, jumping to the form's h2.

## Header (every signed-in page)

```
VAULT  [workspace ▾]   Entries · Agents (2) · Members · Setup · Activity        [name ▾]
```

- **Workspace switcher** (`<details>` menu): the user's workspaces with their role, the current one marked, and
  "+ Add a workspace" → `GET /workspaces/new`. Switching keeps you on the same section when the role allows it,
  otherwise it opens Entries.
- **Primary nav**, filtered by role in the current workspace:

  | Item | Pages it is active on | Roles |
  |---|---|---|
  | Entries | `/`, `/entries/*`, `/workspaces/{w}/import` | all |
  | Agents  | `/workspaces/{w}/requests`, `/agents`, `/grants` | owner |
  | Members | `/workspaces/{w}/members` | owner |
  | Setup   | `/workspaces/{w}/structure`, `/types` | owner, editor |
  | Activity | `/workspaces/{w}/audit` | owner |

  The Agents item carries a count chip when requests wait for a decision or an MFA code. A role with a single
  nav item (viewer) gets no nav row. The count stays current without a reload on every page of an owner: the pages
  that refresh their requests swap the nav with them; every other page asks `GET /workspaces/{w}/waiting`
  (`{"waiting": N}`, owners only, 404 otherwise) once a minute while it is visible, changes only the chip, never
  while the focus is in the nav, and stops after 15 minutes without a key or pointer press so an unattended page
  does not keep the session alive.
- The header data comes from a view composer (`AppServiceProvider`): `$navSpaces`, `$navRole` and `$navWaiting`,
  derived from the `$space` each page passes. A workspace page that does not pass `$space` shows only Entries.
- **Account menu** (`<details>` under the user's name): Account → `GET /account`, Sign out (POST `/logout`).

## Areas

### Entries (all roles)

- `/` — the entry list for the current workspace: header with `+ Add an entry` (the one `go`) and `Import`
  (editor+), search and filters (project, environment, type, state), the board, pager. No workspace or project
  forms on this page; empty states link to Setup.
- `/entries/{id}` — one entry, in this order: title, jump links, requests waiting for a decision (owners, only when any
  wait), details (the facts table), secret values (reveal), versions, agent access (owners; read-only grants with a link to Grants
  filtered by this entry), recent requests, activity, and Archive last.
- `/entries/new`, `/entries/new?entry=` — add / update form.
- `/workspaces/{w}/import` — paste a JSON import (editor+).

### Agents (owner)

Three tabs under the h1 "Agents": **Requests · Identities · Grants**.

- `/workspaces/{w}/requests` — agent requests. "Waiting for you" first (approval or a site MFA code), each row
  saying which agent asks to run which action on which entry and environment, then recent history. This is where
  Allow once / Deny / Continue sign-in live. Each waiting row shows the time left. While the page is visible
  (also while nothing waits; a hidden tab pauses), app.js re-fetches it every 30 s and swaps only the
  `[data-refresh]` regions (the request sections, the waiting heading and the header nav count), never while focus
  or a started input is inside them. "Refresh requests" and "Checked HH:MM UTC" sit beside the waiting heading,
  here and on an owner's entry page; the button works without script. A request that expires while it holds the
  focus keeps it: the focus moves to that row's expired status, and the announcement names the action and entry.
- `/workspaces/{w}/agents` — agent identities: state, expiry, how many active grants each has (links to Grants
  filtered by that agent), Revoke; the "Add an agent" form. Creating an agent shows the one-time token page.
  Its next steps are "Add a grant" (Grants for that agent) and "Done — back to Identities". Until the token is
  copied, every link out of that page (header, tabs, both next steps) and Sign out asks "Token copied?" in the
  two-step pattern; a copy that worked lifts the guard, and "I've saved it" lifts it for a token copied by hand.
- `/workspaces/{w}/grants` — what each agent may do: the grant list (filter `?agent=` and `?entry=`), Revoke,
  and the "Add a grant" form (pre-selected from the filter).
- `/workspaces/{w}/settings` — old address; redirects to Requests.

### Members (owner)

- `/workspaces/{w}/members` — people in the workspace with a role select and Remove on each row, the "Add a
  member" form, and a short role explanation.

### Setup (owner, editor)

Two tabs under the h1 "Setup": **Projects · Entry types**.

- `/workspaces/{w}/structure` — the project cards right under the tabs, with no h2 of their own (each project a
  card with Edit folds; an open fold's summary reads "Close"), then the h2 sections "Add a project" and
  "Environment template".
- `/workspaces/{w}/types` — the entry types as folds under the tabs, then the h2 section "Add an entry type".

### Activity (owner)

- `/workspaces/{w}/audit` — the workspace audit log with actor and subject names (not ids) and filters.

### Account (any signed-in user)

- `/account` — who you are, the password confirmation state, "Security" (the confirm form while a confirmation
  is needed, and change password; the h2 is the same in both states), the
  workspaces you belong to with your role in each, sign out (a plain box, no amber top).
- `/confirm?return=/path` — confirm your password (keeps the signed-in header). A sensitive POST without a fresh confirmation throws
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
| Agents, members | `requests`, `agents`, `grants`, `members`, `token`, `operations` and `end-date` partials | `Workspace::requests`, `agents`, `grants`, `members`, `settings` |
| Setup | `structure`, `types` | `Workspace::structurePage`, `typesPage` |

## House rules

Every page follows these, so the same kind of thing looks and behaves the same everywhere.

1. **Two widths.** Forms, standalone cards, facts tables and short lists are capped at `--card` (760 px); boards
   are capped at `--wide` (1100 px) on every page — a zero-specificity cap on every direct child of `main`, so heads,
   stats, filter bars and boards share one right edge. Only a multi-column `.deck` differs. No page adds its own width.
2. **Headings.** h1 is the page or area, h2 a section. A form card has its own h3 only when its h2 names something
   broader. No h2 that repeats the active tab (the list a tab is named after follows the tabs without a heading),
   no h1→h3 skips, no h2 inside a card.
3. **Form foot.** The note sits left or above; buttons sit right at natural width on every screen size.
   Validation errors return to the form (a 303 to the same-origin Referer or `last_page`) with a focused error
   summary read from the session's `form_errors`; only non-secret fields are refilled with `old()`. Views use
   `session('form_errors')`, not `$errors` (sessions are JSON, so the error bag does not survive).
4. **Long sensitive forms** include `@include('confirm-inline')`, so an expired confirmation never loses input.
   While the confirmation is valid the block is the line "Confirmed until HH:MM UTC."; the password field appears
   only when it is required. **Time-critical actions** never go to the confirm page while script runs: Reveal
   (current and per version) and the waiting requests' Allow once / Deny / Continue sign-in use one shared block
   each (`'shared' => 'confirm-reveal'` in the Secret values card, `'confirm-decide'` above the waiting board);
   their forms carry `data-confirm-from="<id>"` and `app.js` copies the password into the form that is sent. A
   submit without the password is held, the focus moves to the field, and Enter there sends it. Without script
   the confirm page is the fallback. A page states its confirmation once: on an entry page with waiting
   requests, the block above them carries "Confirmed until …" and, once expired, the page's one password field;
   the Reveal block (`'primary' => 'confirm-decide'`) then shows nothing while valid and a pointer to that field
   when expired, and its forms use that password.
5. **Filter bars** (Entries, Grants, Activity) share one pattern: a `details.filters[data-wide-open]` holding a
   `.toolbar` with labelled selects and one **Apply** button. On desktop it is always open and no toggle (its
   summary is not shown); on phone it is a closed drawer. When a filter is set, a `.filter-status` line says
   "Showing N of M" with the only **Clear filters** link beside it (`.filter-count`), then one `.chip.filter`
   per active filter.
6. **Boards on phone** become compact cards of two or three lines. Values that need their column head carry an
   inline label: "Requested …", "Finished …", "Expires …", "Expired …", "Revoked …" (`small.k`; `.k.keep` also shows
   on desktop where no column head names the value; the Requests history head reads "Finished (UTC)"). Approval
   reads "asks every time" or "runs without asking". Requests and Grants need the whole `--wide` for their
   columns, so they are cards below a 1180 px window (1100 px plus page padding and a scrollbar): a row action is
   never behind a sideways scroll. Every other board switches at 900 px. A request card reads: agent "requests"
   action; entry · project / environment; "Request 1a2b3c4d" with the state at the right; the times; the controls.
   On phone everything pressed is at least 40 px high; a link that shares its line with text (a link in a card,
   `.more-link` after a note) gets that height from padding, not from a taller line.
7. **Row stripes** come from state: `is-ok`, `is-bad`, `is-idle` (finished or inactive) and `is-wait` (waiting
   for a decision or still running). A row whose thing has no state (a person on Members) is `is-plain`: no
   stripe colour. Every row carries one of the five.
8. **Repeated buttons** get an `aria-label` naming their item.
9. **Words**: "Reveal" for anything that shows a secret value; names are never uppercased.
   Booleans are `label.check` checkboxes everywhere; array rows use explicit indices (`env_production[3]`).
10. **Actions only when possible**: no Revoke on inactive items; a form whose required choice is empty shows an
    empty state linking to where to fix it instead of an enabled submit.

## Additions to the ops design system

Vault adds a few components not in `DESIGN.md`: `button.ok` (approve a request), `nav.subnav` (quiet in-page jump
links labelled "On this page"; on the entry page they sit in a `details.jump` that is a closed drawer on phones), `nav.tabs` (area tabs), `details.menu` (header menus), `.chip.role` (neutral role
label), `.confirm-inline` (password confirmation in the page: a line while valid, a field when required; `.shared` outside its forms), the compact phone card for boards, `.card.solo`
(a capped standalone card), `.confirm-step` (inline two-step confirmation that replaces `confirm()` for every
`data-confirm` form) and `.field-error` (message under an invalid field: placed after the field's `<label>`, never
inside it, and tied to the field with `aria-describedby`, so the accessible name is the same before and after a
failed submit), `.legend` (form section titles; sections inside a card are
`fieldset`/`legend`, not headings) and `.capped` (short lists capped at the card width).

Validation messages use the visible labels from `lang/en/validation.php` (`attributes`, plus `form_attributes`
keyed by request path for labels that differ per form). Messages thrown by hand with
`ValidationException::withMessages` must use the visible label themselves. Activity event codes get readable
names in one mapping in `activity.blade.php`; the code stays as secondary text (visually hidden on phones, where
the card leads with the readable name and the subject). Date fields carry "UTC" only, no format hint.


## Known gaps

- A workspace cannot be renamed or deleted from the web UI yet.
- Account changes only the sign-in password. A person's name and email, and re-enrolment of the authenticator
  (a lost or replaced device), are not in Account: they are operator recovery only.
