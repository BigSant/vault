// Screen readers hear short status changes through the polite live region in the layout.
const say = text => { const live = document.getElementById('live'); if (live) { live.textContent = ''; setTimeout(() => { live.textContent = text; }, 50); } };
// A hidden value cannot be copied any more, so its Copy button is switched off too.
const hideSecrets = (selector = '[data-secret]') => document.querySelectorAll(selector).forEach(el => {
  el.textContent = 'Hidden. Reveal again to see it.'; el.dataset.hidden = '1';
  if (el.id) document.querySelectorAll('[data-copy]').forEach(button => { if (button.dataset.copy === el.id) { button.disabled = true; button.textContent = 'Copy'; } });
});
// MFA enrollment keys stay visible until the form is submitted; other secrets auto-hide.
if (document.querySelector('[data-secret]:not([data-keep])')) setTimeout(() => { hideSecrets('[data-secret]:not([data-keep])'); say('Secret values hidden again. Copy is off until you reveal them again.'); }, 30000);
// The enrollment QR encodes the same key, so it goes too.
addEventListener('pagehide', () => { hideSecrets(); document.querySelectorAll('.totp-qr').forEach(img => img.remove()); });
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
  const el = document.getElementById(button.dataset.copy);
  if (!el || el.dataset.hidden) return;
  try { await navigator.clipboard.writeText(el.textContent); button.textContent = 'Copied'; say('Copied'); }
  catch { button.textContent = 'Copy blocked'; say('Copy blocked'); }
}));
const type = document.getElementById('entry-type');
const fields = () => document.querySelectorAll('[data-types]').forEach(el => {
  const active = el.dataset.types.split(',').includes(type.value);
  el.hidden = !active;
  el.querySelectorAll('input,textarea').forEach(input => { input.disabled = !active; input.required = active && input.closest('form').dataset.editing !== '1' && !input.hasAttribute('data-optional'); });
});
if (type) { type.addEventListener('change', fields); fields(); }
// The visible label of a field, without its hint: "Password", "API token".
const fieldName = input => input.closest('.field, label')?.querySelector('label span, span')?.firstChild?.textContent?.trim() || 'value';
document.querySelectorAll('[data-generate]').forEach(button => button.addEventListener('click', () => {
  const input = document.getElementById(button.dataset.generate);
  if (!input) return;
  const bytes = crypto.getRandomValues(new Uint8Array(24));
  input.value = btoa(String.fromCharCode(...bytes));
  input.dispatchEvent(new Event('input', { bubbles: true }));
  button.textContent = 'Generated';
  const name = fieldName(input);
  say(name + ' generated. Use Show ' + name + ' to check it.');
}));
// Every Generate button gets a Show/Hide companion, so a generated or typed value can be checked before saving.
document.querySelectorAll('[data-generate]').forEach(button => {
  const input = document.getElementById(button.dataset.generate);
  if (!input || input.type !== 'password') return;
  const name = fieldName(input);
  const show = document.createElement('button');
  show.type = 'button';
  // The button names what a press does, in text and accessible name alike; the announcement reuses the words.
  const sync = () => {
    const verb = input.type === 'password' ? 'Show' : 'Hide';
    show.textContent = verb;
    show.setAttribute('aria-label', verb + ' ' + name);
  };
  const mask = () => { input.type = 'password'; sync(); };
  sync();
  show.addEventListener('click', () => {
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    sync();
    say(name + (visible ? ' shown. Hide ' + name + ' to mask it again.' : ' hidden. Show ' + name + ' to check it.'));
  });
  button.before(show);
  // A shown value never stays on screen through a submit or a page change.
  input.form?.addEventListener('submit', mask);
  addEventListener('pagehide', mask);
});
// Entry type editor: add another empty field row.
document.querySelectorAll('[data-add-row]').forEach(button => button.addEventListener('click', () => {
  const rows = document.getElementById(button.dataset.addRow);
  const template = rows?.querySelector('[data-row-template]');
  if (!template) return;
  const row = template.cloneNode(true);
  row.removeAttribute('data-row-template');
  row.querySelectorAll('input').forEach(input => { if (input.type !== 'hidden') input.value = ''; });
  row.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
  if (rows.closest('table')?.hasAttribute('role')) { row.setAttribute('role', 'row'); row.querySelectorAll('td').forEach(td => td.setAttribute('role', 'cell')); }
  rows.appendChild(row);
  row.querySelector('input:not([type=hidden])')?.focus();
}));
// Inline password confirmation: the block is in every long sensitive form, hidden while the confirmation has more
// than 4 minutes left. It appears when the time comes, when the tab is looked at again, and at the latest on submit.
document.querySelectorAll('.confirm-inline[data-confirmed-until]').forEach(box => {
  const input = box.querySelector('input[type=password]');
  if (!input) return;
  // The server's clock decides; data-now turns it into an offset from this device's clock.
  const skew = box.dataset.now ? Number(box.dataset.now) * 1000 - Date.now() : 0;
  const until = Number(box.dataset.confirmedUntil) * 1000;
  const check = () => {
    const left = until - (Date.now() + skew);
    if (left >= 240000) return false;
    const expired = left <= 0;
    const appeared = box.hidden;
    box.hidden = false;
    input.disabled = false;
    input.required = expired;
    box.querySelectorAll('[data-when]').forEach(el => { el.hidden = (el.dataset.when === 'expired') !== expired; });
    if (appeared) say(expired ? 'Your password confirmation has expired. Enter your current password in this form.' : 'Your password confirmation ends soon. Enter your current password in this form.');
    return expired;
  };
  check();
  setInterval(check, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
  box.closest('form')?.addEventListener('submit', event => {
    if (!check() || input.value) return;
    event.preventDefault();
    input.focus();
    say('Your password confirmation has expired. Enter your current password to continue.');
  });
});
document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
  if (event.defaultPrevented) return;
  if (!confirm(form.dataset.confirm)) event.preventDefault();
}));
// Grant form: an action needs fields the chosen entry's type may not have. Options carry data-fields (entry: the
// fields it has; action: the fields it reads), so impossible actions are switched off before the server says no.
document.querySelectorAll('select[name=entry_id]').forEach(entrySelect => {
  const form = entrySelect.form;
  const actionSelect = form?.querySelector('select[name=action]');
  if (!actionSelect || !actionSelect.querySelector('option[data-fields]')) return;
  const lacking = ' — needs fields this entry lacks';
  const options = [...actionSelect.options];
  options.forEach(option => { option.dataset.text = option.textContent; });
  const submit = form.querySelector('button:not([type=button])');
  const note = document.createElement('p');
  note.className = 'note';
  note.id = 'grant-action-note';
  note.hidden = true;
  note.textContent = 'No action can use this entry: its type lacks the fields every action reads. Choose another entry.';
  actionSelect.closest('label')?.after(note);
  const list = value => (value || '').split(',').map(key => key.trim()).filter(Boolean);
  const sync = () => {
    const chosen = entrySelect.selectedOptions[0];
    const picked = Boolean(chosen?.value);
    const has = new Set(list(chosen?.dataset.fields));
    options.forEach(option => {
      const fits = !picked || list(option.dataset.fields).every(key => has.has(key));
      option.disabled = !fits;
      option.textContent = option.dataset.text + (fits ? '' : lacking);
    });
    const valid = options.filter(option => !option.disabled);
    if (actionSelect.selectedOptions[0]?.disabled && valid.length) valid[0].selected = true;
    const none = picked && !valid.length;
    if (submit) submit.disabled = none;
    if (none) actionSelect.setAttribute('aria-describedby', note.id); else actionSelect.removeAttribute('aria-describedby');
    if (none && note.hidden) say(note.textContent);
    note.hidden = !none;
  };
  entrySelect.addEventListener('change', sync);
  addEventListener('pageshow', sync);
  sync();
});
// Filter bars: an inline bar on wide screens, a closed drawer on phones. A resize follows along until the user
// opens or closes the bar themselves.
const wide = matchMedia('(min-width: 901px)');
const filterBars = document.querySelectorAll('details[data-wide-open]');
filterBars.forEach(bar => {
  if (wide.matches) bar.open = true;
  bar.querySelector('summary')?.addEventListener('click', () => { bar.dataset.toggled = '1'; });
});
wide.addEventListener('change', () => filterBars.forEach(bar => { if (!bar.dataset.toggled) bar.open = wide.matches; }));
// Header menus are <details>: only one stays open, and a click outside or Escape closes it.
const menus = document.querySelectorAll('details.menu');
menus.forEach(menu => menu.addEventListener('toggle', () => {
  if (menu.open) menus.forEach(other => { if (other !== menu) other.open = false; });
}));
addEventListener('click', event => menus.forEach(menu => { if (menu.open && !menu.contains(event.target)) menu.open = false; }));
addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  menus.forEach(menu => {
    if (!menu.open) return;
    menu.open = false;
    if (menu.contains(document.activeElement)) menu.querySelector('summary').focus();
  });
});
// A link to a collapsed editor (#type-KEY) opens it.
const openTarget = () => {
  let target = null;
  try { target = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null; } catch { return; }
  const fold = target?.closest('details');
  if (!fold || fold.classList.contains('menu')) return;
  fold.open = true;
  target.scrollIntoView();
};
// On phones the rows of these tables are cards (display:block/grid/flex), which drops the table semantics.
// Explicit roles keep the column and row heads tied to their cells for screen readers.
document.querySelectorAll('table.board, table.facts, table.list.edit, table.list.import-format, table.list.import-ids, table.list.import-types').forEach(table => {
  table.setAttribute('role', 'table');
  table.querySelectorAll('thead, tbody, tfoot').forEach(el => el.setAttribute('role', 'rowgroup'));
  table.querySelectorAll('tr').forEach(el => el.setAttribute('role', 'row'));
  table.querySelectorAll('th').forEach(el => el.setAttribute('role', el.scope === 'row' ? 'rowheader' : 'columnheader'));
  table.querySelectorAll('td').forEach(el => el.setAttribute('role', 'cell'));
});
addEventListener('hashchange', openTarget);
openTarget();
