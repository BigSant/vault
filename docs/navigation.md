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
  buttons "Add X" ("Add entry", "Add grant"), never "New" or "Create". The sign-in pages may say "Create account". **Edit entry**. **End date (UTC)** as the column head for agent and grant end dates (rows say Expires, Expired or Revoked).
  **rotation due**. Password fields are labelled **Current password**.
- End dates of agents and grants read **Expires**, **Expired** or **Revoked**, then the date and UTC, then the
  reason on a line of its own. One partial (`end-date`) prints them on Identities, Grants and an entry's Agent access.
  The Identities column head reads "End date (UTC)", because its rows hold all three; each row keeps its own label.
- A request that ended without an owner's decision reads **cancelled**, never "revoked" (the `outcome` partial maps it).
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
- **Focus order is the order seen.** Wide screens: mark, workspace switcher, nav, account menu (the order of the
  source). At 900 px and below the rows are [mark, workspace switcher, account menu] and [nav], and app.js moves
  the account menu before the nav in the document, so Tab and Shift+Tab follow the rows (checked with real keys at
  390 and 1280; an open menu and the focus survive a resize across the breakpoint). Not CSS `reading-flow`: in
  Chrome it drops the menus' summaries from the Tab order of the grid. Without script the phone order is the
  source order.
- **One right edge.** On desktop the header's contents start and end where the page's do: the bar is full width,
  its contents are capped at `--wide` and left-aligned like `main`, so the account menu ends where the boards end
  (checked at 1280 and 1920).

## Areas

### Entries (all roles)

- `/` — the entry list for the current workspace: header with `+ Add an entry` (the one `go`) and `Import`
  (editor+), the stats, search and filters (project, environment, type, state), the board, pager. No workspace or
  project forms on this page; empty states link to Setup.
  **Stats (decided, final):** desktop shows all four as stats (active entries, rotation due →, production, used by
  agents in 7 days). On phone only the two actionable numbers stay stats (active entries, rotation due →); production
  and agent use follow as one small summary line ("3 production · 2 used by agents in 7 days"). Reviewers disagreed
  both ways; do not reopen it. While the rotation-due filter is on, its stat is underlined and says "Clear ×".
- `/entries/{id}` — one entry, in this order: title, jump links, requests waiting for a decision (owners, only when any
  wait), details (the facts table), secret values (reveal; while values are on screen a plain "Hide values" button
  conceals every revealed field at once, switches their Copy off, says so and moves the focus to "Reveal again";
  the values hide by themselves after 30 seconds either way), versions, agent access (owners; read-only grants with a link to Grants
  filtered by this entry), recent requests, activity, and Archive last.
  Under Recent requests and under Activity a `.more-link` leads to the workspace log filtered by this entry
  ("Request activity for this entry →", "All activity for this entry →").
- `/entries/new`, `/entries/new?entry=` — add / update form. Missing a type or a field never costs the draft:
  "Edit entry types (new tab)" opens Setup beside the form, and "Reload types" (script; "Reload fields" on the
  update form, where the type is fixed) fetches the form again and swaps only the Type options and the type field
  rows; typed values stay in the fields that still exist, and every "Clear the current … value" box
  (`clear-<type>-<key>`) keeps its state, checked or not. The status line says what was kept and what was not.
- `/workspaces/{w}/import` — paste a JSON import (editor+). Where two columns fit `--wide` the form and the Format
  reference (field table, example) stand side by side; below that they stack and "Format ↓" in the intro jumps
  to the reference. The form column is the wider one (1.5 : 1), so an environment ID and its "Copy ID" share one
  line. The form's Environment IDs and Type keys folds open from the links in the Format table, also
  on a second press after one was closed by hand.

### Agents (owner)

Three tabs under the h1 "Agents": **Requests · Identities · Grants**.

- `/workspaces/{w}/requests` — agent requests. "Waiting for you" first (approval or a site MFA code), each row
  saying which agent asks to run which action on which entry and environment, then recent history. This is where
  Allow once / Deny / Continue sign-in live. Each waiting row shows the time left. While the page is visible
  (also while nothing waits; a hidden tab pauses), app.js re-fetches it every 30 s and swaps only the
  `[data-refresh]` regions (the request sections, the waiting heading and the header nav count). A request board is
  merged row by row (rows match by `data-request`): only the row that holds the focus or a started input is left
  as it is, and its siblings are updated, added and removed around it without moving it; the waiting heading
  is always "Requests waiting for you" with the number of rows shown as a `chip warn` beside it (as in the header nav). "Refresh requests" and "Checked HH:MM UTC" sit beside the waiting heading; when a round
  fails, or a row in use no longer matches the server, the status reads "Check delayed · last checked HH:MM UTC"
  ("Automatic check paused · …" after 15 idle minutes; the status keeps to the one verb, check)
  and the time does not advance,
  here and on an owner's entry page; the button works without script. A request that expires while it holds the
  focus keeps it: the focus moves to that row's expired status, and the announcement names the action and entry.
- `/workspaces/{w}/agents` — agent identities: state, expiry, how many active grants each has (links to Grants
  filtered by that agent), Revoke; the "Add an agent" form. Creating an agent shows the one-time token page.
  Its next steps are "Add a grant" (Grants for that agent) and "Back to Identities". Until the token is
  copied, every link out of that page (header, tabs, both next steps) and Sign out asks "Token copied?" in the
  two-step pattern; a copy that worked lifts the guard, and "I've saved it" lifts it for a token copied by hand.
  The ways out that are not links of the page (Back, reload, closing the tab) get the browser's own question from
  a `beforeunload` handler, registered only while the token is unsaved: a copy that worked, "I've saved it" and a
  departure confirmed in the page (the second press of an armed link or of Sign out) remove it. The visible
  warning stays. An armed header mark is a button like the armed nav links, not the wordmark.
- `/workspaces/{w}/grants` — what each agent may do: the grant list (filter `?agent=` and `?entry=`), Revoke,
  and the "Add a grant" form (pre-selected from the filter).
- `/workspaces/{w}/settings` — old address; redirects to Requests.

### Members (owner)

- `/workspaces/{w}/members` — people in the workspace with a role select and Remove on each row, the "Add a
  member" form, and a short role explanation. Save role, Remove and Add member use the held-action pattern (house
  rule 4): one shared password block (`confirm-members`) above the board is the page's one confirmation state
  ("Password confirmed until …") and, once expired, its one password field. An action pressed then is held under a
  button that names it, "Change Dana's role to Editor" (the role chosen in the row, which stays chosen and can
  still be changed), "Remove Dana" or "Add member: Dana"; Remove still asks its two-step question first, and the
  held button does not ask again. The "Add a member" form only points to that field when the confirmation has expired.

### Setup (owner, editor)

Two tabs under the h1 "Setup": **Projects · Entry types**.

- `/workspaces/{w}/structure` — the project cards right under the tabs, with no h2 of their own (each project a
  card with Edit folds; the cards are a `.deck`, two side by side at `--wide` and one column below), then the h2
  sections "Add a project" and "Environment template". A fold's toggle is a compact control with an edge
  (`.fold-act`): ▸ "Edit" while closed, ▾ "Close" while open; on phone an environment row is one line (sign,
  count, Edit). One shared password block (`confirm-setup`) above the cards serves every form of the page.
- `/workspaces/{w}/types` — the entry types as folds under the tabs, then the h2 section "Add an entry type".
  One shared password block (`confirm-types`) above the folds serves every form of the page: an action pressed
  after the confirmation expired is held as "Save entry type Login", "Remove entry type Login" or "Add entry type
  FTP server". New field rows start with Required unchecked, in a fold and in the add form alike.

### Activity (owner)

- `/workspaces/{w}/audit` — the workspace audit log with actor and subject names (not ids) and filters.

### Account (any signed-in user)

- `/account` — who you are, the password confirmation state, "Security" (the confirm form while a confirmation
  is needed, and change password; the h2 is the same in both states; while a confirmation is needed "Change
  sign-in password" is a closed `details.fold`, so the page shows one password field at rest), the
  workspaces you belong to with your role in each, sign out (a plain box, no amber top).
- `/confirm?return=/path` — confirm your password (keeps the signed-in header). The page says what happened once
  (the callout "Nothing was changed. …"), has one sentence about the 5-minute validity in the form foot, and names
  a stopped action once, in its solid button: "Confirm: Reveal <name>" ("Confirm and continue" when the action
  has no known name), then "Reveal <name>" after the password, both in a right-aligned `.form-foot`. Action labels
  carry no colon of their own ("Allow once shop.admin-login on …"). A sensitive POST without a fresh confirmation throws
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
   stats, filter bars and boards share one right edge. Only a multi-column `.deck` differs: its columns are at
   least 520 px, so two cards fit `--wide` side by side (2 × 520 + 12), and where they do not fit the cards stack
   at `--card`. No page adds its own width.
2. **Headings.** h1 is the page or area, h2 a section. A form card has its own h3 only when its h2 names something
   broader. No h2 that repeats the active tab (the list a tab is named after follows the tabs without a heading),
   no h1→h3 skips, no h2 inside a card.
3. **Form foot.** The note sits left or above; buttons sit right at natural width on every screen size.
   Validation errors return to the form (a 303 to the same-origin Referer or `last_page`) with a focused error
   summary read from the session's `form_errors`; only non-secret fields are refilled with `old()`. Views use
   `session('form_errors')`, not `$errors` (sessions are JSON, so the error bag does not survive).
4. **Long sensitive forms** include `@include('confirm-inline')`, so an expired confirmation never loses input.
   While the confirmation is valid the block is the line "Password confirmed until HH:MM UTC."; the password field appears
   only when it is required. **Row and time-critical actions** never go to the confirm page while script runs: Reveal
   (current and per version), the waiting requests' Allow once / Deny / Continue sign-in and the Members rows'
   Save role / Remove use one shared block each (`'shared' => 'confirm-reveal'` in the Secret values card,
   `'confirm-decide'` above the waiting board, `'confirm-members'` above the members board). Where a page has
   such a block, its other sensitive actions use it too, so the page has one password field: Restore (Versions)
   and Archive entry on the entry page; every form of Setup › Projects (`'confirm-setup'` above the project
   cards); every form of Setup › Entry types (`'confirm-types'` above the folds: Save entry type, Remove entry
   type, Add entry type; no fold has a block of its own); Revoke and the add form on Identities
   (`'confirm-agents'`) and Grants (`'confirm-grants'`), above the board. A form card on such a page (Add a
   member, Add an agent, Add a grant, Add a project, the environment template, Add an entry type) holds a
   deferred block (`'primary' => '<id>'`, `'fallback' => true`): nothing while valid, a pointer to the one field
   when expired, and a field of its own only in `<noscript>`. The pointer is one line under a dashed rule,
   "Password confirmation expired — enter it above ↑" (the link puts the focus in that field), and while it
   shows, the solid button of its own form or card ("Add member", "Reveal current") is plain: nothing on the page
   is solid until an action is held. A block is not deferred while its own field holds the focus or something
   typed (the entry page, when a request arrives under a person who is typing); it is looked at again on focusout. A page whose list is still empty has no shared
   block (no agents, no grants, no entry types): its add form then holds an ordinary block with its own field.
   Everything else goes to the confirm page, which
   offers the action again after the password ("Confirm: <action>", then "<action>"), so no action has to be repeated;
   their forms carry `data-confirm-from="<id>"` and `app.js` copies the password into the form that is sent. A
   submit without the password is held and stays visible: the focus moves to the field and a button under it names
   the held action ("Allow once: shop.admin-login, request 1a2b3c4d"); that button, or Enter in the field, sends
   it. A held decision keeps its context: beside the button the block repeats the request ("<agent> requests
   <action> · <entry> · <project> / <environment> · Request <id>", from the row's `<template data-held-summary>`),
   and that summary is the button's description (`aria-describedby`, set only while the summary holds something).
   A held Reveal names the entry in its own case ("Reveal current: Shop admin panel"). While an action is held,
   the held button is the page's only solid button: every other solid button or link in `main` is plain until
   the hold ends. A password entered first gets the visible line "Now choose an
   action below." The waiting requests' block asks "Enter your current password, then choose a request action
   below."; on the entry page, where it also serves Reveal, Restore and Archive, "…then choose a request action,
   or reveal, restore or archive below." A password that is not right is a validation error on `confirm_password` (303 back to the page, never
   the confirm page): for a form with `data-confirm-from` the summary stands above the shared block (the primary
   one while a block is deferred), its message links to and is repeated under that block's field, the focus goes
   to the field and the action that failed is held again under its name. The one exception: when the form came
   back with a secret field emptied (the summary says "Secret fields are not refilled." and carries
   `data-unfilled`), the action is not held again and the focus goes to that emptied field, so the form is never
   sent without it. Without script
   the confirm page is the fallback. A page states its confirmation once: on an entry page with waiting
   requests, the block above them carries "Password confirmed until …" and, once expired, the page's one password field;
   the Reveal block (`'primary' => 'confirm-decide'`) then shows nothing while valid and a pointer to that field
   when expired, and its forms use that password.
5. **Filter bars** (Entries, Grants, Activity) share one pattern: a `details.filters[data-wide-open]` holding a
   `.toolbar` with labelled selects and one **Apply** button. On desktop it is always open and no toggle (its
   summary is not shown) and ends where the board under it ends: on Entries the search field takes the room, on
   Grants and Activity Apply stands at the right edge. On phone it is a closed drawer. When a filter is set, a `.filter-status` line says
   "Showing N of M" with the only **Clear filters** link beside it (`.filter-count`), then one `.chip.filter`
   per active filter.
6. **Boards on phone** become compact cards of two or three lines. Values that need their column head carry an
   inline label: "Requested …", "Finished …", "Expires …", "Expired …", "Revoked …" (`small.k`; `.k.keep` also shows
   on desktop where no column head names the value; the Requests history head reads "Finished (UTC)"). Approval
   reads "asks every time" or "runs without asking". A date's "UTC" is a `small.k` wherever a column head already
   says it (also after a waiting request's "Expires …"): it shows on cards only. The site MFA field of a waiting
   request has one width wherever the board is a table (Requests and the entry page). Requests and Grants need the whole `--wide` for their
   columns, so they are cards below a 1180 px window (1100 px plus page padding and a scrollbar): a row action is
   never behind a sideways scroll. Every other board switches at 900 px. A request card reads: agent "requests"
   action ("requested" in the history, where the row is finished); entry · project / environment; "Request 1a2b3c4d" with the state at the right; the times; the controls.
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
11. **Secret inputs** (a password input in `.with-button`) get a Reveal/Hide toggle from app.js. It is switched
    off while the field is empty, on as soon as something is typed or generated, and a field emptied while shown
    is masked again.

## Additions to the ops design system

Vault adds a few components not in `DESIGN.md`: `button.ok` (approve a request), `nav.subnav` (quiet in-page jump
links labelled "On this page"; on the entry page they sit in a `details.jump` that is a closed drawer on phones), `nav.tabs` (area tabs), `details.menu` (header menus), `.chip.role` (neutral role
label), `.confirm-inline` (password confirmation in the page: a line while valid, a field when required; `.shared` outside its forms), the compact phone card for boards, `.card.solo`
(a capped standalone card), `.confirm-step` (inline two-step confirmation that replaces `confirm()` for every
`data-confirm` form) and `.field-error` (message under an invalid field: placed after the field's `<label>`, never
inside it, and tied to the field with `aria-describedby`, so the accessible name is the same before and after a
failed submit), `.legend` (form section titles; sections inside a card are
`fieldset`/`legend`, not headings), `.capped` (short lists capped at the card width), `details.fold` (a collapsed
editor, one per thing: a project, an environment, an entry type) with `.fold-act` (its toggle as a compact control
with an edge: ▸ "Edit" closed, ▾ "Close" open), `.filter-status` (the "Showing N of M" line under a filter bar, with
Clear filters and one `.chip.filter` per active filter), `.held` (the row in a shared `.confirm-inline` that names
the action waiting for the password, as the page's one solid button, with `.held-what` beside it) and `.more-link`
(a link on a line of its own or after a note that leads to more of the same thing elsewhere, "Manage grants →";
40 px to the finger on phones), and `.secret-row.compact` (a revealed value with its Copy on the same row).

Validation messages use the visible labels from `lang/en/validation.php` (`attributes`, plus `form_attributes`
keyed by request path for labels that differ per form). Messages thrown by hand with
`ValidationException::withMessages` must use the visible label themselves. Activity event codes get readable
names in one mapping in `activity.blade.php`; the code stays as secondary text (visually hidden on phones, where
the card leads with the readable name and its outcome, then the subject, then when and who). Date fields are text
fields in ISO form, never the browser's date picker (its display format follows the device, not the data):
`type="text" pattern="\d{4}-\d{2}-\d{2}" placeholder="YYYY-MM-DD"`, with "UTC, YYYY-MM-DD" in the label; the server
validates the date. No `inputmode="numeric"`: a phone's number pad may have no "-". app.js (`isoDate`) rewrites
`YYYYMMDD`, `YYYY/MM/DD` and `YYYY.MM.DD` to `YYYY-MM-DD` when the field changes, loses the focus or its form is sent.


## Known gaps

- A workspace cannot be renamed or deleted from the web UI yet.
- Account changes only the sign-in password. A person's name and email, and re-enrolment of the authenticator
  (a lost or replaced device), are not in Account: they are operator recovery only.
