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
- **One-time link** — a link made with the workspace's Onetime Secret account that opens once. Its list is
  **Links**; removing an unread one early is **Burn** ("Burned one-time link"). Its states read "waiting for
  approval", "not opened", "previewed", "read", "burned", "expired", "cancelled", "failed". The service is named
  **Onetime Secret** (two words), as it names itself.
- **Request** — an agent asking to run a granted action (an `operations` row). Not "operation".
  States read "waiting for approval" (`pending`) and "needs MFA code" (`requires_user_action`).
- **Password confirmation** — the one name for the 5-minute confirmation before sensitive actions (Account row,
  the confirm page, the inline field). Not "identity", not "sensitive actions".
- **Entry details** — what a viewer sees of an entry. Not "metadata".
- Buttons that add a row to an editable table read "+ Add a field", "+ Add an environment". Copy uses straight
  apostrophes.
- **Sign out** — not "Lock". Create actions in the signed-in UI: headings and links "Add a/an X", submit
  buttons "Add X" ("Add entry", "Add grant"), never "New" or "Create". The sign-in pages may say "Create account". **Edit entry**. **End date (UTC)** as the column head for agent and grant end dates (rows say Expires, Expired or Revoked).
  **rotation due**. Password fields are labelled **Current password**.
- End dates of agents and grants read **Expires**, **Expired** or **Revoked**, then the date and UTC, then the
  reason on a line of its own. One partial (`end-date`) prints them on Identities, Grants and an entry's Agent access.
  The Identities column head reads "End date (UTC)", because its rows hold all three; each row keeps its own label.
  **Expiring soon.** An agent or grant that is still active and ends within 3 days carries a `chip warn` "expires
  soon" after its date (the same partial), and its row the `is-wait` stripe instead of `is-ok`, on all three boards
  (`Access::endsSoon`, one rule for the chip and the stripe).
- A request that ended without an owner's decision reads **cancelled**, never "revoked" (the `outcome` partial maps it).
- Roles are shown as neutral `chip role`, never with state colours.
- An environment sign is amber when its name starts with "stag" ("stage", "staging", "staging-eu-west-1"), hazard
  red when the environment is production, and plain otherwise (the `sign` partial).
- A tabbed area keeps the area name as its h1 (Agents, Setup) with the tabs right below it. A page with an add
  form further down puts one quiet "+ Add a/an X" button in the head row beside the h1, jumping to the form's h2.

## Header (every signed-in page)

```
VAULT  [workspace ▾]   Entries · Links · Agents (2) · Members · Setup · Activity        [name ▾]
```

- **Workspace switcher** (`<details>` menu): the user's workspaces with their role, the current one marked, and
  "+ Add a workspace" → `GET /workspaces/new`. Switching keeps you on the same section when the role allows it,
  otherwise it opens Entries. In the open list a name is whole: it wraps, with the role chip beside it, so two
  workspaces with the same role whose names differ only at the end stay apart (checked at 390 px). The closed menu
  in the bar keeps its one line and its ellipsis.
- **Primary nav**, filtered by role in the current workspace:

  | Item | Pages it is active on | Roles |
  |---|---|---|
  | Entries | `/`, `/entries/*`, `/workspaces/{w}/import` | all |
  | Links   | `/workspaces/{w}/links` | owner, editor |
  | Agents  | `/workspaces/{w}/requests`, `/agents`, `/grants` | owner |
  | Members | `/workspaces/{w}/members` | owner |
  | Setup   | `/workspaces/{w}/structure`, `/types`, `/onetimesecret` (owner) | owner, editor |
  | Activity | `/workspaces/{w}/audit` | owner |

  The Agents item carries a count chip when requests wait for a decision or an MFA code. A role with a single
  nav item (viewer) gets no nav row. On phones (600 px and below) an owner's six items are two even rows of three
  (a grid), never five and one left over. The count stays current without a reload on every page of an owner: the pages
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
- **One row, whatever the names.** From 901 px up the bar never wraps: the mark and the nav keep their width, the
  two menus give way and cut their names with an ellipsis (the whole name is the menu's accessible name). The
  account menu is at most 16em wide, the workspace menu at most 24em and at least 8em; between 901 and 1040 px the
  bar's gaps are smaller, so the usual names stay whole. On phones the two names share the room beside the mark:
  each gets what it needs while there is room, and two long names get equal shares, both cut.

## Areas

### Entries (all roles)

- `/` — the entry list for the current workspace: header with `+ Add an entry` (the one `go`) and `Import`
  (editor+), the stats, search and filters (project, environment, type, state), the board, pager. No workspace or
  project forms on this page; empty states link to Setup.
  **Stats (decided, final):** desktop shows all four as stats (active entries, rotation due →, in production, used by
  agents, 7 days). On phone only the two actionable numbers stay stats (active entries, rotation due →); production
  and agent use follow as one small summary line ("3 in production · 2 used by agents, 7 days"). The labels are the
  same words at every width. Reviewers disagreed
  both ways; do not reopen it. On desktop the four are equal columns across the strip. While the rotation-due
  filter is on, its stat is underlined and says "Clear ×". With nothing due and the filter off the stat is a plain
  number ("0 rotation due", "0 rotation due in this view"), not a link: it would only open an empty list.
  **Rotation-due count (decided):** the stat's link keeps the search and the project, environment and type filters,
  so its number is counted inside them (`Workspace::index`): it is what the link will show. While any of them is
  on, the label reads "rotation due in this view". The other three stats stay workspace totals. The label is
  "used by agents, 7 days": "in the past 7 days" does not fit the phone summary's one line at 390 px.
  **Columns (decided):** as a table (901 px and up) the board has a fixed layout: Project and Environment
  14 % each, Type 18 %, Updated 124 px, Last used 168 px, and the entry name takes the rest, so no column moves when a
  filter changes the rows. The board is named by the h1 (`aria-labelledby="page-title"`).
  **Filter bar (decided):** with the bar open, the search field and the four filters are one row with Apply last,
  at the board's right edge, at every desktop width: a select is at most 12em wide and gives way before anything
  wraps (a long project name is cut by the select and whole in its list); the search field keeps at least 15rem.
  "All environments" is never cut at rest: the Environment select keeps 13em and Project keeps the 146 px of "All
  projects"; Type and State give way first. The bar is one row down to 901 px, and from 1000 px up every select
  shows its resting option whole.
- `/entries/{id}` — one entry, in this order: title (at 900 px and below the h1 takes the whole row and "Edit entry"
  stands at the right of the meta line under it), jump links, requests waiting for a decision (owners, only when any
  wait), details (the facts table; a viewer gets the note "Viewers see entry details and version history, not values." under it and no Secret
  values section), secret values (reveal; while values are on screen a plain "Hide values" button
  conceals every revealed field at once, switches their Copy off, says so and moves the focus to "Reveal again"
  ("Reveal version N again" under an older version); the values hide by themselves after 30 seconds either way, and
  then the focus moves to that button only when it stood on a Copy of these values or on "Hide values", and stays
  where it is otherwise). On phones, where the jump links are a closed drawer, a person who may reveal gets
  "Secret values ↓" as a boxed link (no underline) beside the drawer: one tap to the values, while waiting requests stay the first
  section (the open drawer lists the link itself, versions, agent access (owners; read-only grants with a link to Grants
  filtered by this entry), recent requests, activity, and Archive last.
  Under Recent requests and under Activity a `.more-link` leads to the workspace log filtered by this entry
  ("Request activity for this entry →", "All activity for this entry →").
  **Page length (decided):** Recent requests stays a section of its own (it holds the decisions: denied, expired,
  cancelled) and lists the last 10 finished requests; Activity lists the last 10 events. The two links lead to the rest.
- `/entries/new`, `/entries/new?entry=` — add / update form. Missing a type or a field never costs the draft.
  Under Type sits a closed disclosure (`details.type-detour`) whose summary is the question, always in view:
  "Missing a type or field?" ("Missing a field?" on the update form). The ordinary path stays short, on phones
  too; opened, it says what to do in words on the page, never in a `title`, and names the button: "Edit entry
  types in a new tab. After editing the entry type, return here and choose Reload types." ("… choose Reload
  fields." on the update form), then the button, then the status line. Without script there is no button, and the
  sentence printed by the server says what is true then: "After editing the entry type, load this page again. What
  you typed here is not kept." (app.js swaps in the other wording, `data-script-text`).
  The link opens Setup beside the form, and "Reload types" (script; "Reload fields" on the
  update form, where the type is fixed) fetches the form again and swaps only the Type options and the type field
  rows; typed values stay in the fields that still exist, and every "Clear the current … value" box
  (`clear-<type>-<key>`) keeps its state, checked or not. The status line says what was kept and what was not.
  **Reload never drops a typed value without asking (decided).** Before anything is replaced, the fields that hold
  a value are compared with the fields that arrive. When a typed value would have no field to go to (its field or
  its type was removed), the old fields stay as they are; the status names those fields by their labels, never
  their values ("Nothing was reloaded yet. The field Password no longer exists, and what you typed in it would be
  lost. Copy it elsewhere first, or choose Discard and reload."), and two buttons follow it: "Discard and reload"
  and "Cancel" (the focus goes to Cancel; Escape cancels; the reload button is off while the question is open).
  Cancel changes nothing; after a discard the status says which fields were discarded and that the rest was kept.
  **Required markers (decided).** Every required field a person can edit in Details carries the same marker,
  `<small aria-hidden="true">required</small>` under its label (the field's own `required` is what a screen reader
  says, once): Name, Agent alias, Project / environment and Type (the last two only
  on the add form; on the update form they are fixed text). Tags and the rotation reminder carry none. In Secret
  values the marker is the other way round: every field is required unless it says `optional`.
- `/workspaces/{w}/import` — paste a JSON import (editor+). Where two columns fit `--wide` the form and the Format
  reference (field table, example; the example's long lines wrap and an environment ID may break, so no value is clipped) stand side by side; below that they stack and "Format ↓" in the intro jumps
  to the reference. The form column is the wider one (1.5 : 1), so an environment ID and its "Copy ID" share one
  line. The form's Environment IDs and Type keys folds open from the links in the Format table, also
  on a second press after one was closed by hand.

### Links (owner, editor)

- `/workspaces/{w}/links` — one-time links made with the workspace's Onetime Secret account, by people and by
  agents. Head row "+ Add a link"; one sentence on what a link is; then "Check now" (asks Onetime Secret for the state
  of up to 50 open links; the page itself never calls the service) with the note that explains "previewed" and
  "read"; the board; "Add a link". Without an account the page says "Onetime Secret is not set up." and links an
  owner to Setup (an editor reads "An owner sets up the account in Setup."). Board columns: Link (the note, else the
  entry as a link, else "Text only"; under it the entry when the note took the first line, the field names the
  content used, and a `passphrase` chip), Made by (a person, or an agent with the `agent` chip), Made (UTC), End
  date (UTC) ("Expires …" while it can be opened, "Expired …", "Ended …" once read or burned; a link waiting for
  approval reads "1 day once allowed", one being sent "1 day once sent"), State, and Burn (Cancel while waiting for
  approval). "Check now" says how many it checked, and when Onetime Secret did not answer for some, that their states
  are as last checked. Burn records what Onetime Secret confirms ("burned", or "read" when the recipient was first);
  without the receipt, or for a link made in an earlier account, it says so and changes nothing; a row that ended
  while the page was open says "This link had already ended (…)" instead of an error. "read" is a muted chip, except
  for an agent's link that held an entry's values: there it is amber, because that is the news. A link made in an
  earlier account carries an "earlier account" chip and no Burn (Vault can no longer reach it); "Check now" counts
  those apart. A Burn that could not be done says why in a red callout above the list (`session('alert')`), never in
  the form error summary. Archiving or destroying an entry, revoking an agent or a `onetimesecret.link` grant, and
  Stop say in their notice how many links holding values can still be opened. An owner burns any link,
  an editor the ones they made. Open links come first, then the newest. As a card: the link with its state at the
  right, who and when, then the end date with Burn at the right.
- **Add a link.** Content (a textarea; required without an entry), Lifetime (the workspace's choices up to its
  longest), Passphrase (optional, with Generate and Reveal; "send it another way than the link"), Note (optional,
  "never put a secret here"), the form's own password block. From an entry ("Share as a one-time link →" under its
  Secret values, editors) the form names the entry, offers "Text only instead", and starts with one line per field,
  "Label: {{key}}"; the hint lists the entry's field keys. **The content is never put in the session:** a form that
  fails comes back in the same response (422) with the content and the note, never the passphrase (the summary says
  so), and the error summary is the usual one (`session()->now`).
- **Link added** (the POST's response, like an agent's token): the link once, "Copy link", "I've saved it", the
  passphrase once with "Copy passphrase" when one was set (a generated one would otherwise be lost), "Do not open the link yourself: opening it uses it up.", and "Back to Links". Leaving asks
  "Link copied?" until it was copied.

### Agents (owner)

Three tabs under the h1 "Agents": **Requests · Identities · Grants**.

- `/workspaces/{w}/requests` — agent requests. "Waiting for you" first (approval or a site MFA code), each row
  saying which agent asks to run which action on which entry and environment, then recent history. This is where
  Allow once / Deny / Continue sign-in live. Each waiting row shows its deadline and the time left at the
  top of its Decision cell, above the controls they limit: one line on a card ("Expires 2026-11-26 00:09 UTC · 2
  min left"), the time left under the deadline in the table, so the cell is as high in the last minute as before. While the page is visible
  (also while nothing waits; a hidden tab pauses), app.js re-fetches it every 30 s and swaps only the
  `[data-refresh]` regions (the request sections, the waiting heading and the header nav count). A request board is
  merged row by row (rows match by `data-request`): only the row that holds the focus or a started input is left
  as it is, and its siblings are updated, added and removed around it without moving it; the waiting heading
  is always "Requests waiting for you" with the number of rows shown as a `chip warn` beside it (as in the header nav). Beside the waiting heading sits the refresh row; when a round
  fails, or a row in use no longer matches the server, the status reads "Check delayed · last checked HH:MM UTC"
  ("Automatic check stopped after 15 idle minutes · …"; the status keeps to the one verb, check)
  and the time does not advance,
  here and on an owner's entry page; "Check now" works without script. A request that expires while it holds the
  focus keeps it: the focus moves to that row's expired status, and the announcement names the action and entry. After
  the history a `.more-link` "All request activity →" leads to the workspace log (`/audit?group=request`).
  **Refresh row (decided).** One verb family, check: "Check now" (named "Check now for requests"), "Pause
  automatic checks" / "Resume automatic checks", "Checked HH:MM UTC", "Check delayed · last checked HH:MM UTC",
  "Paused · last checked HH:MM UTC". No label or status says "refresh" or "automatic updates". The row reads left to
  right in the order of the document at every width, so what is seen, what Tab visits and what a screen reader
  reads agree: [Check now] [Pause automatic checks], then the status. No `row-reverse`, no `order`. On desktop the
  status has a fixed slot (31ch, its longest steady text) that ends at the right edge, so the buttons do not move
  when it changes; on phones both buttons share one row at the left edge and the status is a small line under
  them. The toggle is a `button.quiet[data-refresh-pause]` (`aria-pressed`, shown by script only): it has the
  `--edge` border every control has at rest and is quieter by its fill, colour and weight; pressed, its edge and
  text are amber. While it is pressed no automatic round runs and nothing is swapped; "Check now" still checks
  once. Resuming checks at once. The choice is kept for the browser
  session (`sessionStorage`, in try/catch: without storage it holds for the page). A row still switches itself
  off at its deadline while paused: that is the row's own clock, and allowing an expired request must not look
  possible. A manual check that leaves a row in use as it is names it: "Request 5cda3b60 will update when you
  leave this row."
- `/workspaces/{w}/agents` — agent identities: state, expiry, how many active grants each has (links to Grants
  filtered by that agent), whether it may create one-time links (column "One-time links": "allowed" / "not allowed"
  with Allow / Stop, only once Onetime Secret is set up; held like Revoke, "Allow one-time links: <agent>"), Revoke;
  the "Add an agent" form (with "May create one-time links" once set up, its hint saying what it allows). Under the
  board one note says what the permission allows. Stop and Revoke say, in the page's notice, how many of the agent's
  links can still be opened. While any agent holds an active `onetimesecret.link` grant, the footer line reads
  "Secret values stay in the vault. Agents get action results, and one-time links with values only after your
  approval." Creating an agent shows the one-time token page.
  Its next steps are "Add a grant" (Grants for that agent) and "Back to Identities". Until the token is
  copied, every link out of that page (header, tabs, both next steps) and Sign out asks "Token copied?" in the
  two-step pattern (an armed link reads "Leave this page" and is named "Leave this page: <link text>"); a copy that worked lifts the guard, and "I've saved it" lifts it for a token copied by hand.
  The ways out that are not links of the page (Back, reload, closing the tab) get the browser's own question from
  a `beforeunload` handler, registered only while the token is unsaved: a copy that worked, "I've saved it" and a
  departure confirmed in the page (the second press of an armed link or of Sign out) remove it. The visible
  warning stays. An armed header mark is a button like the armed nav links, not the wordmark.
- `/workspaces/{w}/grants` — what each agent may do: the grant list (filter `?agent=` and `?entry=`), Revoke,
  and the "Add a grant" form (pre-selected from the filter; its checkbox reads "Require owner approval for every
  run"; once an action is chosen the form's note names the fields it reads: "shop.admin-login reads the fields
  username and password. The entry's type must have them."). The empty state under the filter bar is as wide as
  the bar; the one that replaces the form is capped. The shared password block (`confirm-grants`) stands above the filter bar, so the bar, its status line and
  the board stay together. A held "Add grant" keeps its context like a held request decision: beside the button
  the block says "<agent> may run <action> · <entry> · <project> / <environment> · valid for N days · asks every
  time" (the form's own `<template data-held-summary>`, filled from its fields by app.js and following them while
  held); it is the button's description (`aria-describedby`).
- The grant action `onetimesecret.link` (listed once Onetime Secret is set up) lets an agent put that entry's
  values into a one-time link; the agent must also be allowed one-time links on Identities ("This agent may not
  create one-time links. Allow them on Identities first."). With "Require owner approval" every such link is a
  request on Requests, like any action. Its row there says what the link would hold, under the action: "fields
  username, password · 3 days · passphrase" at normal weight on a line of its own (also in the held summary and the
  decision's accessible name). Action codes may break after their dots (`<wbr>`), never inside a word that fits.
- `/workspaces/{w}/settings` — old address; redirects to Requests.

### Members (owner)

- `/workspaces/{w}/members` — people in the workspace with a role select and Remove on each row, the "Add a
  member" form, and a short role explanation. Save role, Remove and Add member use the held-action pattern (house
  rule 4): one shared password block (`confirm-members`) above the board is the page's one confirmation state
  ("Password confirmed until …") and, once expired, its one password field. An action pressed then is held under a
  button that names it, "Change Dana's role to Editor" (the role chosen in the row, which stays chosen and can
  still be changed; the name keeps its case together with its "'s"), "Remove Dana" or "Add member: Dana"; Remove still asks its two-step question first, and the
  held button does not ask again. The "Add a member" form only points to that field when the confirmation has expired.

### Setup (owner, editor)

Tabs under the h1 "Setup": **Projects · Entry types**, and for an owner **Onetime Secret**.

- `/workspaces/{w}/structure` — the project cards right under the tabs, with no h2 of their own (each project a
  card with Edit folds; the cards are a `.deck`, two side by side at `--wide` and one column below), then the h2
  sections "Add a project" and "Environment template". A fold's toggle is a compact control with an edge
  (`.fold-act`): ▸ "Edit" while closed, ▾ "Close" while open (every fold, Account's "Change sign-in password"
  too); on phone an environment row is one line (sign, count, Edit; a long sign wraps inside itself), and a
  project's head is one line while it fits: a long project name takes the line and the count drops under it, with
  Edit at the right of that second line (`.fold-tail`). On desktop the count and Edit are one group at the right
  that never wraps (`.fold-tail`), and a long project name wraps in the room at its left. On phones a project's
  count stands beside Edit while the head is one line, like an environment row's. "+ Add an environment" is a
  control with an edge (`.fold-act.add`), its text starting where the environment names start. One shared password block (`confirm-setup`) above the cards serves every form of the page.
- `/workspaces/{w}/types` — the entry types as folds under the tabs, listed by name whatever the case (so are
  the Type options everywhere), then the h2 section "Add an entry type".
  One shared password block (`confirm-types`) above the folds serves every form of the page: an action pressed
  after the confirmation expired is held as "Save entry type Login", "Remove entry type Login" or "Add entry type
  FTP server". New field rows start with Required unchecked, in a fold and in the add form alike.

- `/workspaces/{w}/onetimesecret` (owner; 404 for an editor, who does not get the tab) — the account one-time
  links are made with. While set up: the facts (Region, Account email, API token "Saved <date> UTC", Lifetimes) and
  "Check account" (signs in, creates nothing), then "Change the account"; before: one sentence on what to prepare and
  "Set up the account". The form: Region, Account email, API token (required the first time, then "Leave blank to
  keep"; stored in OpenBao `settings/<workspace>`, never shown again), Default lifetime, Longest lifetime, the form's
  own password block, "Save account". While links of the current account are open, a "Change anyway" checkbox
  (with a hint naming how many) stands above the password block: moving to another region or email without it is a
  validation error, because Vault cannot check or burn those links afterwards; a new token for the same account
  needs nothing. While set up, "Remove account" (a `stop` button with the two-step question, no
  password: it only takes the ability away and deletes the token) stands under "Check account".

### Activity (owner)

- `/workspaces/{w}/audit` — the workspace audit log with actor and subject names (not ids) and filters.
  **Outcome chips (the rule).** A chip has a state colour only where the state is the news. A request's own
  outcome is: on Requests and an entry's Recent requests "succeeded" is green and "failed" / "denied" red. In the
  Activity log nearly every row succeeded, so "ok" is a muted chip there (the `outcome` partial's `quiet`) and
  only what went wrong ("denied", "failed") keeps its colour. The words are the stored ones: an audit outcome
  reads "ok", a request state "succeeded".

### Account (any signed-in user)

- `/account` — who you are and "Sign out" as a plain button in the head row beside the h1 (no section of its
  own), the password confirmation state (while needed the facts row is the chip and the link "Confirm your
  password", which puts the focus in the password field, nothing more; what it is for is said once, by the card's subtitle), "Security" (the confirm form
  while a confirmation is needed, and change password; the h2 is the same in both states; while a confirmation is
  needed "Change sign-in password" is a closed `details.fold`, so the page shows one password field at rest; a
  `<summary>` never holds a heading, the fold's name is a span with the h3 look), the workspaces you belong to
  with your role in each.
- `/confirm?return=/path` — confirm your password (keeps the signed-in header). The page says what happened once
  (the callout "Nothing was changed. …"), has one sentence about the 5-minute validity in the form foot, and names
  a stopped action once, in its solid button: "Confirm: Reveal <name>" ("Confirm and continue" when the action
  has no known name; a reveal of an older version names it: "Confirm: Reveal version 1 of <name>"), then "Reveal <name>" after the password, both in a right-aligned `.form-foot`. That button
  (`.action-name`) wraps inside the card however long the label is, and the name is in normal weight beside the bold verb. Action labels
  carry no colon of their own ("Allow once shop.admin-login on …"). A sensitive POST without a fresh confirmation throws
  `App\Vault\ConfirmationRequired` (403), which renders this form instead of a generic error. After confirming you
  go back to `return`: a same-origin path only, never `/confirm`, `/login`, `/logout` or `/mfa`. Because
  `Referrer-Policy: no-referrer` hides the Referer, the return path falls back to the last page shown (kept in the
  session as `last_page`). A wrong password re-renders the form with 401. On desktop a short action shares the
  row with the note; an action too long for that puts the note above and the two buttons under it at the right, as
  one row: Cancel stays beside the solid button, whose label wraps, never alone on a line above it. On phones the solid button comes first
  and Cancel stands under it, both at the right edge (`data-phone-first`: CSS order, and app.js moves the button
  before Cancel in the document at that width, so the focus order is the order seen).
- `/workspaces/new` — create a workspace.

## Ownership of files

| Area | Views | Controller methods |
|---|---|---|
| Shell, account, confirm | `layout`, `account`, `confirm`, `workspace-new`, `error`, `tabs` partial | `Account::*`, `Identity::confirm` |
| Entries | `catalog`, `entry`, `entry-form`, `import` | `Workspace::index`, `entryForm`, `detail`, `importForm` |
| Agents, members | `requests`, `agents`, `grants`, `members`, `token`, `operations` and `end-date` partials | `Workspace::requests`, `agents`, `grants`, `members`, `settings` |
| Setup | `structure`, `types`, `onetimesecret` | `Workspace::structurePage`, `typesPage`, `Sharing::settings` |
| Links | `links`, `link-created`, `notice` partial | `Sharing::index`, `store`, `check`, `burn`, `machine` |

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
   (`'confirm-agents'`, above the board) and Grants (`'confirm-grants'`, above the filter bar). A form card on such a page (Add a
   member, Add an agent, Add a grant, Add a project, the environment template, Add an entry type) holds a
   deferred block (`'primary' => '<id>'`, `'fallback' => true`): nothing while valid, a pointer to the one field
   when expired, and a field of its own only in `<noscript>`. The pointer is one line under a dashed rule,
   "Password confirmation expired. Go to the password field ↑" (the link puts the focus in that field), and while it
   shows, the solid button of its own form or card ("Add member", "Reveal current") is plain: nothing on the page
   is solid until an action is held. A block is not deferred while its own field holds the focus or something
   typed (the entry page, when a request arrives under a person who is typing); it is looked at again on focusout.
   An action held by a block that then becomes deferred is released and said so ("The held action was released:
   the password field is now above. Choose the action again."): its field and held button are gone, and the
   page's solid buttons must not stay plain. A page whose list is still empty has no shared
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
   the hold ends. One prompt at a time: when a two-step action that was just confirmed ("Confirm revoke") is held
   for the password, its armed step is withdrawn; the held button is the next press. A held name never ends in an
   empty choice ("Add grant", not "Add grant: Choose an action"). `aria-describedby` is edited one id at a time
   (`describe` in app.js), so a field's hint or error stays when another description comes or goes. A password entered first gets the visible line "Now choose an
   action below." The waiting requests' block asks "Enter your current password, then choose a request action
   below."; on the entry page, where it also serves Reveal, Restore and Archive, "…then choose a request action,
   or reveal, restore or archive below." A password that is not right is a validation error on `confirm_password` (303 back to the page, never
   the confirm page): for a form with `data-confirm-from` the summary stands above the shared block (the primary
   one while a block is deferred), its message links to and is repeated under that block's field, the focus goes
   to the field and the action that failed is held again under its name. The one exception: when the form came
   back with a secret field emptied (the summary says "Secret fields are not refilled." and carries
   `data-unfilled`), the action is not held again and the focus goes to that emptied field (a password input, or
   a waiting request's site MFA code), so the form is never sent without it. Without script
   the confirm page is the fallback. A page states its confirmation once: on an entry page with waiting
   requests, the block above them carries "Password confirmed until …" and, once expired, the page's one password field;
   the Reveal block (`'primary' => 'confirm-decide'`) then shows nothing while valid and a pointer to that field
   when expired, and its forms use that password.
5. **Filter bars** (Entries, Grants, Activity) share one pattern: a `details.filters[data-wide-open]` holding a
   `.toolbar` with labelled selects and one **Apply** button. On desktop it is always open and no toggle (its
   summary is not shown) and ends where the board under it ends: on Entries the search field takes the room, on
   Grants and Activity Apply stands at the right edge (the Grants Entry select, which names an entry with its
   project and environment, may be up to 28em wide in that room). On phone it is a closed drawer. When a filter is set, a `.filter-status` line says
   "Showing N of M" with the only **Clear filters** link beside it (`.filter-count`), then one `.chip.filter`
   per active filter.
6. **Boards on phone** become compact cards of two or three lines. Values that need their column head carry an
   inline label: "Requested …", "Finished …", "Expires …", "Expired …", "Revoked …" (`small.k`; `.k.keep` also shows
   on desktop where no column head names the value; the Requests history head reads "Finished (UTC)"). Approval
   reads "asks every time" or "runs without asking" while the grant is active, and "previously asked every time" or
   "previously ran without asking" once it is revoked or expired (Grants and an entry's Agent access): an inactive
   grant does nothing now. A date's "UTC" is a `small.k` wherever a column head already
   says it (also after a waiting request's "Expires …"): it shows on cards only. The site MFA field of a waiting
   request has one width wherever the board is a table (Requests and the entry page). Requests and Grants need the whole `--wide` for their
   columns, so they are cards below a 1180 px window (1100 px plus page padding and a scrollbar): a row action is
   never behind a sideways scroll. Every other board switches at 900 px.
   **Long data never widens a board (decided).** As tables, Requests, Grants and an entry's Agent access have a
   fixed layout (like Entries): every column has its width from the table, so the last column (Decision, Finished,
   Revoke, State) always ends inside the board and no column moves between the whole list and a filtered one.
   Grants: Agent 163 px, Action 144, Approval 162, End date 200, State 82, the Revoke column 92 (its button and no
   more), and the entry takes the rest (257), so two long entry names that differ near their end are whole within
   two lines; 12 px between two columns. **The armed Revoke takes a line of its own** across the bottom of its row,
   under a dashed rule: the question, then "Confirm revoke" and "Cancel" at the right edge (app.js marks the row
   and measures the line; the row keeps that room, so nothing overlaps and no column moves). As a card the armed
   step is a line of the card, as before; the first cell keeps the board's left
   padding, so its text starts where every other board's does. End date has 188 px for its text: "Revoked
   2026-11-21 23:27" (184 px) ends 16 px before the State chip. Agent access: 15 / 21 / 27 / 25 % and State the rest. An agent name, an action
   code, an entry name and an environment sign wrap in their cell or card, inside a word when they must; agent
   names carry no `.nobreak`. A date with its label ("Revoked 2026-11-20 10:15") is one `.nobreak` piece, in the
   `end-date` partial too. The widths fit the usual data on one line ("ci-runner", a sixteen-letter action code,
   a sixteen-letter entry name). **Names break at their hyphens.** The Agent column holds a part of thirteen
   letters on Requests and fifteen or more on Grants and an entry page, so "deployment-orchestrator-eu-west-1-canary"
   wraps after its hyphens; a break inside a word is left for a part longer than that and for a name without a
   hyphen ("DeploymentOrchestratorWithoutAnyBreakPoint"), where nothing else is possible. On a grant card the action wraps beside the state chip like the name does (both leave it 84 px).
   **Separators stay with what comes before them.** A "·" or "/" between two parts of a card line is glued to the
   part before it (a no-break space, or the pseudo-element of the earlier item), so a wrapped line never starts
   with one: "Shop admin panel ·" then the project, "shop /" then the sign, the deadline "·" then the time left.
   On request and grant cards the entry link is inline for that reason (its 40 px tap height comes from padding).
   **Control edges:** everything pressed that looks like a box has a `--edge` border: buttons (also
   `button.quiet`), fields, the header menus, `.fold-act`, and on phones the drawers (`details.filters`,
   `details.jump`) and the boxed link `a.jump-quick`. `--rule` is for cards, rows and dividers only. A request card reads: agent "requests"
   action ("requested" in the history, where the row is finished); entry · project / environment; "Request 1a2b3c4d" with the state at the right; the times; the controls.
   **One grant card** (Grants below 1180 px, an entry's Agent access below 900 px): line 1 the agent and the action
  together with the state chip at the top right, where the Identities card has it; line 2 the entry · project /
  environment (Grants only); line 3 the approval; line 4 the end date, with Revoke at the right (Grants only). The
  chip is out of the flow, so a long name or action wraps under it and never moves it.
  **Requests columns:** as tables, "Requests waiting for you" and "Recent requests" share the widths of their
  leading columns (Agent 123 px, Action 144, Entry 193, State 200, Requested 152; 12 px between two columns), so
  each column starts at the same place in both, with any data; the last column (Decision or Finished) takes the
  rest (288). State is exactly its widest content, the lamp and "waiting for approval" (187 px). Requested holds
  one date: a waiting row's deadline is in its Decision cell, and that width went to Entry, which holds "Payment
  gateway API" on one line. On an entry page the
  boards have no Entry column (`.no-entry`): its width goes to Agent (176) and Action (284), so a long agent name
  and a long action code ("storefront.administrator-console-signin") break at their hyphens, and State, Requested
  and the last column start where they do on Requests. The site MFA field is 9em wide to make that room. In app.css every property of these boards is set once: shared rules first, then one
  block for the card (below 1181 px) and one for the table; nothing is set in one and overridden in the other.
  **Requests on phones:** "Checked HH:MM UTC" is a small line right under "Check now" and belongs to it; the
  password line ("Password confirmed until …") follows under a dashed rule, apart from it and before the cards.
  On phone everything pressed is at least 40 px high; a link that shares its line with text (a link in a card,
   `.more-link` after a note) gets that height from padding, not from a taller line. On the Members card the
   email is `--dim`, so the name leads.
7. **Row stripes** come from state: `is-ok`, `is-bad`, `is-idle` (finished or inactive) and `is-wait` (waiting
   for a decision or still running). A row whose thing has no state (a person on Members) is `is-plain`: no
   stripe colour. Every row carries one of the five. An active agent or grant that ends within 3 days is `is-wait`.
   In the Activity log a row that went well is `is-idle`, like its muted "ok" chip: only `is-bad` has a colour there.
8. **Repeated buttons** get an `aria-label` naming their item.
9. **Words**: "Reveal" for anything that shows a secret value; names are never uppercased.
   Booleans are `label.check` checkboxes everywhere; array rows use explicit indices (`env_production[3]`).
10. **Actions only when possible**: no Revoke on inactive items; a form whose required choice is empty shows an
    empty state linking to where to fix it instead of an enabled submit.
11. **Secret inputs** (a password input in `.with-button`) get a Reveal/Hide toggle from app.js. It is switched
    off while the field is empty, on as soon as something is typed or generated, and a field emptied while shown
    is masked again. Every field that sets a new password has it: an entry's secret fields, a member's initial
    password, Account's "New password" and "Repeat new password", and the first owner's Password. Fields that ask
    for the current password do not.

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
40 px to the finger on phones), and `.secret-row` (a value block: its label, the value and its Copy button, on the
entry page, the token page and the authenticator setup alike; `.compact` puts a revealed value and its Copy on one row).

Validation messages use the visible labels from `lang/en/validation.php` (`attributes`, plus `form_attributes`
keyed by request path for labels that differ per form). Messages thrown by hand with
`ValidationException::withMessages` must use the visible label themselves. Activity event codes get readable
names in one mapping in `activity.blade.php`; the code stays as secondary text (visually hidden on phones, where
the card leads with the readable name and its outcome, then the subject, then when and who). Date fields are text
fields in ISO form, never the browser's date picker (its display format follows the device, not the data):
`type="text" pattern="\d{4}-\d{2}-\d{2}" placeholder="YYYY-MM-DD"`, with "UTC, YYYY-MM-DD" in the label; the server
validates the date. An import that fails names the item by its place in the array ("Item 2: …"), never its values. No `inputmode="numeric"`: a phone's number pad may have no "-". app.js (`isoDate`) rewrites
`YYYYMMDD`, `YYYY/MM/DD` and `YYYY.MM.DD` to `YYYY-MM-DD` when the field changes, loses the focus or its form is sent.


## Known gaps

- A workspace cannot be renamed or deleted from the web UI yet.
- Agents cannot be renewed; add a new agent and its grants before the old one expires.
- Account changes only the sign-in password. A person's name and email, and re-enrolment of the authenticator
  (a lost or replaced device), are not in Account: they are operator recovery only.

## Request updates, titles and labels

- **Titles.** A page inside a workspace names it: `<title>Grants · Acme production · Vault</title>`. Pages outside
  one (Account, Add a workspace, the sign-in pages) stay "Account · Vault". The confirm page and the error pages
  stand in for another page and never name the workspace, also when a stopped action renders them at a workspace
  address: `<title>Password confirmation · Vault</title>` (the layout checks the `quiet-nav` section, not the path).
- **Table names.** Every `.facts` and `.list` table has `aria-labelledby` too: its section h2 (Details, Workspaces,
  Format, Environment template, Add an entry type), the h1 where it has no section (Account), or the summary or
  fold name it sits under (Import's two folds, an entry type). Every board has `aria-labelledby`: its section h2 where it has one (the request boards, and
  Versions, Agent access and Activity on an entry page), else the current tab (`id="tab-current"`: Identities,
  Grants) or the page h1 (`id="page-title"`: Entries, Members, Activity).
- **Field hints** sit under the field, never in the label column: `.field > label, input, small.hint`
  (Initial password, Agent alias, the first owner's Activation code). The hint stays the field's `aria-describedby`.
- **Placeholders.** An example placeholder starts with "e.g. " ("e.g. shop-stage-admin"). A format
  ("YYYY-MM-DD") and an instruction ("Leave blank to keep", the search hint) are not examples. A placeholder
  never repeats its label or column head.
- **Rotation due stat.** While its filter is on, "Clear ×" is a line of its own under the label, in normal case.
- **Two-button form foot on phones.** The buttons share one row, the submit last; a long add label is shortened
  with `.wide-only` ("+ Add" for "+ Add an environment"; the accessible name stays whole).
- **Entries list.** "archived" and "rotation due" chips both sit under the entry name.
- **Import** speaks of entries only: "every entry is checked", "Entry 2: …".
- **Confirm prompts** (`data-confirm`) use no quotation marks: "Remove environment qa?".
- **Empty states** end their sentences: the bold line has its period ("No projects yet.", "No entry types yet.")
  and so does a sentence that ends in a link ("Add the first project.", "Add a workspace."). A link with an arrow
  ("Manage grants →") takes none.
- **Import, phones.** A Format card whose description holds a link is as tall as its neighbours: the link is part
  of the sentence and gets no tap padding of its own.
- **Validation messages** name a field by its visible label ("Require owner approval for every run", "6-digit
  code", "Type DELETE to destroy"). A select's empty option names what to choose and fits 390 px:
  "Choose an environment" under the label "Project / environment".
- **Words for a save.** Saving an entry's values is "Saved": the form's button "Save new version", the Versions
  column "Saved (UTC)", the Activity event "Saved new version". Not "rotated" (rotation is the reminder date).
- **Quotation marks** in UI text are straight (`"`), like apostrophes.
- **Three buttons in a form foot** (an older version on screen: Hide values, Reveal current, Reveal version N
  again): the note stands above the buttons (`.form-foot.stack`), the buttons keep one row at the right.
- **Removing a project or environment** that still holds entries says "Archive and destroy all entries in this
  project before removing it." (an archived entry still counts; only destroyed ones do not).
- **Environment template rows** are labelled "Environment" on phones (the column head is not shown there).
- **Account.** The head row and both h2s ("Security", "Workspaces") are as wide as the cards (`.capped`), so
  "Sign out" and the heading rules end where the cards end, and the head row keeps
  the block gap of other head rows above the facts. The Security deck is one column at the card width in both
  confirmation states (`deck wide capped`): the page has one right edge.
