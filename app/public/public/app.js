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
document.querySelectorAll('[data-generate]').forEach(button => button.addEventListener('click', () => {
  const input = document.getElementById(button.dataset.generate);
  if (!input) return;
  const bytes = crypto.getRandomValues(new Uint8Array(24));
  input.value = btoa(String.fromCharCode(...bytes));
  input.dispatchEvent(new Event('input', { bubbles: true }));
  button.textContent = 'Generated';
  say('Value generated. Use Show to check it.');
}));
// Every Generate button gets a Show/Hide companion, so a generated or typed value can be checked before saving.
document.querySelectorAll('[data-generate]').forEach(button => {
  const input = document.getElementById(button.dataset.generate);
  if (!input || input.type !== 'password') return;
  const name = input.closest('.field, label')?.querySelector('label span, span')?.firstChild?.textContent?.trim() || 'value';
  const show = document.createElement('button');
  show.type = 'button';
  show.textContent = 'Show';
  show.setAttribute('aria-pressed', 'false');
  show.setAttribute('aria-label', 'Show ' + name);
  show.addEventListener('click', () => {
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    show.textContent = visible ? 'Hide' : 'Show';
    show.setAttribute('aria-pressed', String(visible));
    say(name + (visible ? ' shown' : ' hidden'));
  });
  button.before(show);
  // A shown value never stays on screen through a submit or a page change.
  input.form?.addEventListener('submit', () => { input.type = 'password'; });
  addEventListener('pagehide', () => { input.type = 'password'; });
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
document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
  if (!confirm(form.dataset.confirm)) event.preventDefault();
}));
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
