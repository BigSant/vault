// Screen readers hear short status changes through the polite live region in the layout.
const say = text => { const live = document.getElementById('live'); if (live) { live.textContent = ''; setTimeout(() => { live.textContent = text; }, 50); } };
// A hidden value cannot be copied any more, so its Copy button is switched off too and gets its first wording back.
// data-hidden-text says what to do when the value cannot be revealed again (the one-time agent token).
document.querySelectorAll('[data-copy]').forEach(button => { button.dataset.label = button.textContent; });
const hideSecrets = (selector = '[data-secret]') => document.querySelectorAll(selector).forEach(el => {
  el.textContent = el.dataset.hiddenText || 'Hidden. Reveal again to see it.'; el.dataset.hidden = '1';
  if (el.id) document.querySelectorAll('[data-copy]').forEach(button => { if (button.dataset.copy === el.id) { button.disabled = true; button.textContent = button.dataset.label; } });
  if (el.id) document.getElementById(el.id + '-copy-note')?.remove();
});
// MFA enrollment keys stay visible until the form is submitted; other secrets auto-hide.
if (document.querySelector('[data-secret]:not([data-keep])')) setTimeout(() => { hideSecrets('[data-secret]:not([data-keep])'); say('Secret values hidden again. Copy is off until you reveal them again.'); }, 30000);
// The enrollment QR encodes the same key, so it goes too.
addEventListener('pagehide', () => { hideSecrets(); document.querySelectorAll('.totp-qr').forEach(img => img.remove()); });
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
  const el = document.getElementById(button.dataset.copy);
  if (!el || el.dataset.hidden) return;
  try {
    await navigator.clipboard.writeText(el.textContent);
    button.textContent = 'Copied';
    document.getElementById(el.id + '-copy-note')?.remove();
    say('Copied');
  } catch {
    // The clipboard is closed to this page (permission, old browser): the text is selected, so the device's own
    // Copy command works, and a note that stays says so. data-copy-name names the thing: "token", else "value".
    button.textContent = button.dataset.label;
    const range = document.createRange();
    range.selectNodeContents(el);
    const selection = getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    const text = 'Copy did not work. Select the ' + (button.dataset.copyName || 'value') + ' and use your device\'s Copy command.';
    let note = document.getElementById(el.id + '-copy-note');
    if (!note) {
      note = document.createElement('p');
      note.className = 'note copy-note';
      note.id = el.id + '-copy-note';
      (button.closest('.controls') || button).after(note);
    }
    note.textContent = text;
    say(text);
  }
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
// Two-step confirmation in place. The first press turns the button into its armed wording with a Cancel beside it and
// the question as the note; go() runs on the second press. Returns the controls of one button's step.
const armedText = text => 'Confirm ' + text.charAt(0).toLowerCase() + text.slice(1);
const twoStep = (question, armed = armedText) => {
  let step = null;
  let armedAt = 0;
  const disarm = focus => {
    if (!step) return;
    const { box, button, text, label } = step;
    step = null;
    box.before(button);
    box.remove();
    button.textContent = text;
    button.classList.remove('armed');
    if (label === null) button.removeAttribute('aria-label'); else button.setAttribute('aria-label', label);
    if (focus) button.focus();
  };
  const arm = button => {
    const text = button.textContent.trim();
    const label = button.getAttribute('aria-label');
    const box = document.createElement('span');
    box.className = 'confirm-step';
    const note = document.createElement('span');
    note.className = 'confirm-note';
    note.textContent = question;
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.textContent = 'Cancel';
    cancel.setAttribute('aria-label', 'Cancel: ' + (label || text));
    cancel.addEventListener('click', () => disarm(true));
    button.before(box);
    box.append(note, button, cancel);
    button.textContent = armed(text);
    button.setAttribute('aria-label', 'Confirm: ' + (label || text));
    button.classList.add('armed');
    step = { box, button, text, label };
    armedAt = Date.now();
    button.focus();
    say(question + ' Press Confirm to go on, or Cancel.');
  };
  // A double click must not confirm what its first click only asked for.
  return { arm, disarm, armed: () => Boolean(step), tooSoon: () => Date.now() - armedAt < 500 };
};
// Generate fills a secret field with a random value. A field that already holds a value is only replaced after a
// second press ("Replace value?").
document.querySelectorAll('[data-generate]').forEach(button => {
  const step = twoStep('Replace value?', () => 'Confirm replace');
  const input = document.getElementById(button.dataset.generate);
  if (!input) return;
  button.addEventListener('click', () => {
    if (step.armed()) { if (step.tooSoon()) return; step.disarm(true); }
    else if (input.value !== '') { step.arm(button); return; }
    const bytes = crypto.getRandomValues(new Uint8Array(24));
    input.value = btoa(String.fromCharCode(...bytes));
    input.dispatchEvent(new Event('input', { bubbles: true }));
    button.textContent = 'Generated';
    const name = fieldName(input);
    say(name + ' generated. Use Reveal ' + name + ' to check it.');
  });
  button.closest('.with-button')?.addEventListener('keydown', event => { if (event.key === 'Escape' && step.armed()) { event.stopPropagation(); step.disarm(true); } });
  // Typing a value by hand, or emptying the field, withdraws the question.
  input.addEventListener('input', event => { if (event.isTrusted) step.disarm(false); });
  addEventListener('pageshow', event => { if (event.persisted) step.disarm(false); });
});
// Every secret field (a password input in .with-button) gets a Reveal/Hide button right after it, before Generate
// when the field has one, so a generated or typed value can be checked before saving.
document.querySelectorAll('.with-button > input[type=password]').forEach(input => {
  const name = fieldName(input);
  const show = document.createElement('button');
  show.type = 'button';
  // The button names what a press does, in text and accessible name alike; the announcement reuses the words.
  const sync = () => {
    const verb = input.type === 'password' ? 'Reveal' : 'Hide';
    show.textContent = verb;
    show.setAttribute('aria-label', verb + ' ' + name);
  };
  const mask = () => { input.type = 'password'; sync(); };
  sync();
  show.addEventListener('click', () => {
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    sync();
    say(name + (visible ? ' revealed. Hide ' + name + ' to mask it again.' : ' hidden. Reveal ' + name + ' to check it.'));
  });
  input.after(show);
  // A shown value never stays on screen through a submit or a page change.
  input.form?.addEventListener('submit', mask);
  addEventListener('pagehide', mask);
});
// "+ Row" / "+ Field": add another empty row to an editable table. The clone is a fresh row: its controls go back to
// their defaults, every indexed name ("field_required[3]") gets the next free index so a checkbox stays tied to its
// row, and its accessible names end in the row's number ("Label for the new field, row 6").
document.querySelectorAll('[data-add-row]').forEach(button => button.addEventListener('click', () => {
  const rows = document.getElementById(button.dataset.addRow);
  const template = rows?.querySelector('[data-row-template]');
  if (!template) return;
  const indexed = /\[(\d+)\]/;
  const used = [...rows.querySelectorAll('[name]')].map(el => indexed.exec(el.name)).filter(Boolean).map(match => Number(match[1]));
  const next = Math.max(rows.children.length - 1, ...used) + 1;
  const row = template.cloneNode(true);
  row.removeAttribute('data-row-template');
  row.querySelectorAll('.field-error').forEach(note => note.remove());
  row.querySelectorAll('input, select, textarea').forEach(control => {
    if (control.name) control.name = control.name.replace(indexed, '[' + next + ']');
    if (control.matches('select')) {
      [...control.options].forEach(option => { option.selected = option.defaultSelected; });
      if (![...control.options].some(option => option.defaultSelected)) control.selectedIndex = 0;
    } else if (control.type === 'checkbox' || control.type === 'radio') control.checked = control.defaultChecked;
    else if (control.type !== 'hidden') control.value = '';
    control.removeAttribute('aria-invalid');
    control.removeAttribute('aria-describedby');
    if (/^field-error-/.test(control.id)) control.removeAttribute('id');
  });
  if (rows.closest('table')?.hasAttribute('role')) { row.setAttribute('role', 'row'); row.querySelectorAll('td').forEach(td => td.setAttribute('role', 'cell')); }
  rows.appendChild(row);
  const number = rows.children.length;
  row.querySelectorAll('[aria-label]').forEach(el => el.setAttribute('aria-label', el.getAttribute('aria-label').replace(/,? row \d+$/, '') + ', row ' + number));
  row.querySelector('input:not([type=hidden]):not([type=checkbox])')?.focus();
  say('Row ' + number + ' added.');
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
// Destructive actions confirm in place, in two steps (twoStep above): the form's data-confirm question is the note
// and the second press sends the form.
// In a table the armed buttons need more room than the single button. Each such cell gets invisible, zero-height
// copies of its button row, one per button that can be armed (that button as "Confirm …" + Cancel, the others as
// they are). Only one button is armed at a time, so the column is as wide as the widest of these from the start
// and arming never moves a column.
document.querySelectorAll(':is(table.board, table.list) td:has(form[data-confirm])').forEach(cell => {
  const buttons = [...cell.querySelectorAll('button')];
  const arming = buttons.filter(button => button.closest('form[data-confirm]') && button.type !== 'button');
  arming.forEach(armedOne => {
    const sizer = document.createElement('div');
    sizer.className = 'confirm-size' + (cell.querySelector('.controls') ? ' controls' : '');
    sizer.setAttribute('aria-hidden', 'true');
    buttons.forEach(button => {
      const texts = button === armedOne ? [armedText(button.textContent.trim()), 'Cancel'] : [button.textContent.trim()];
      texts.forEach(text => { const fake = document.createElement('span'); fake.className = 'btn'; fake.textContent = text; sizer.append(fake); });
    });
    cell.append(sizer);
  });
});
document.querySelectorAll('form[data-confirm]').forEach(form => {
  const step = twoStep(form.dataset.confirm);
  form.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    if (step.armed()) {
      if (step.tooSoon()) event.preventDefault();
      return;
    }
    const button = event.submitter instanceof HTMLButtonElement ? event.submitter : form.querySelector('button:not([type=button])');
    if (!button) return;
    event.preventDefault();
    step.arm(button);
  });
  form.addEventListener('keydown', event => { if (event.key === 'Escape' && step.armed()) { event.stopPropagation(); step.disarm(true); } });
  // A page restored from the back/forward cache starts unarmed again.
  addEventListener('pageshow', event => { if (event.persisted) step.disarm(false); });
});
// Grant form: an action needs fields the chosen entry's type may not have. Options carry data-fields (entry: the
// fields it has; action: the fields it reads), so impossible actions are switched off before the server says no.
document.querySelectorAll('select[name=entry_id]').forEach(entrySelect => {
  const form = entrySelect.form;
  const actionSelect = form?.querySelector('select[name=action]');
  if (!actionSelect || !actionSelect.querySelector('option[data-fields]')) return;
  const lacking = ' — needs fields this entry lacks';
  // The empty "Choose an action" placeholder is not an action: it is never switched off or counted.
  const options = [...actionSelect.options].filter(option => option.value);
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
    // The chosen action cannot use the newly chosen entry: back to "Choose an action", never a silent swap.
    if (actionSelect.selectedOptions[0]?.disabled) {
      actionSelect.value = '';
      if (valid.length) say('The action was cleared: it needs fields this entry lacks. Choose an action.');
    }
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
// On phones the rows of these tables are cards (display:block/grid/flex), which drops the table semantics.
// Explicit roles keep the column and row heads tied to their cells for screen readers.
const tableRoles = (root = document) => root.querySelectorAll('table.board, table.facts, table.list.edit, table.list.import-format, table.list.import-ids, table.list.import-types').forEach(table => {
  table.setAttribute('role', 'table');
  table.querySelectorAll('thead, tbody, tfoot').forEach(el => el.setAttribute('role', 'rowgroup'));
  table.querySelectorAll('tr').forEach(el => el.setAttribute('role', 'row'));
  table.querySelectorAll('th').forEach(el => el.setAttribute('role', el.scope === 'row' ? 'rowheader' : 'columnheader'));
  table.querySelectorAll('td').forEach(el => el.setAttribute('role', 'cell'));
});
tableRoles();
// Requests are time-critical. Every waiting row carries data-expires (Unix seconds) and shows how long it still
// waits; the table's data-now is the server's clock, so this device's clock does not matter. A row past its deadline
// is switched off and reads "expired"; when the focus was in that row it moves to the row's expired status, never
// out of the row, and the announcement names the action and the entry.
// The page is never reloaded. While the page is visible (also while nothing waits, so a new request shows up), the
// page's address (data-refresh-url) is fetched every 30 seconds and only the content of the [data-refresh] regions
// is replaced: the request sections, the waiting heading and the header's waiting count. A hidden tab pauses and
// catches up when it is looked at again. A region that holds the focus or a started input is left alone until the
// next round. What changed is said in the live region; "Refresh requests" beside the waiting heading does the same
// on demand, and "Checked HH:MM UTC" next to it says when the page last matched the server.
const regions = () => [...document.querySelectorAll('[data-refresh]')];
const refreshUrl = regions().map(region => region.dataset.refreshUrl).find(Boolean);
if (refreshUrl) {
  const waitingRows = (root = document) => [...root.querySelectorAll('table.requests tr[data-expires]')];
  // The offset between the server's clock and this device's, taken once per table when it arrives.
  const skews = new WeakMap();
  const skewOf = table => {
    if (!skews.has(table)) skews.set(table, table.dataset.now ? Number(table.dataset.now) * 1000 - Date.now() : 0);
    return skews.get(table);
  };
  const tick = () => {
    const expired = [];
    waitingRows().forEach(row => {
      if (row.dataset.late) return;
      const left = Number(row.dataset.expires) * 1000 - (Date.now() + skewOf(row.closest('table')));
      const out = row.querySelector('.left');
      if (left > 0) {
        const minutes = Math.floor(left / 60000);
        if (out) { out.textContent = minutes >= 60 ? Math.floor(minutes / 60) + ' h ' + (minutes % 60) + ' min left' : minutes >= 1 ? minutes + ' min left' : 'under 1 min left'; out.hidden = false; }
        return;
      }
      row.dataset.late = '1';
      row.classList.remove('is-wait');
      row.classList.add('is-idle', 'is-late');
      if (out) out.hidden = true;
      row.querySelectorAll('.k.keep').forEach(label => { if (label.textContent.trim() === 'Expires') label.textContent = 'Expired'; });
      const state = row.querySelector('td.state');
      const chip = document.createElement('span');
      chip.className = 'chip muted';
      chip.textContent = 'expired';
      chip.tabIndex = -1;
      if (state) state.replaceChildren(chip);
      const inside = row.contains(document.activeElement);
      row.querySelectorAll('button, input:not([type=hidden])').forEach(control => { control.disabled = true; });
      // The focus stays in the row, on what just changed.
      if (inside && state) chip.focus({ preventScroll: true });
      expired.push(row.dataset.what || '');
    });
    if (expired.length === 1) say('Request expired before a decision: ' + (expired[0] || 'one request') + '. It can no longer be allowed.');
    else if (expired.length) say(expired.length + ' requests expired before a decision: ' + expired.filter(Boolean).join('; ') + '. They can no longer be allowed.');
  };
  tick();
  setInterval(tick, 5000);
  // In use: the focus is inside, or a field holds something that was typed and not sent.
  const inUse = region => region.contains(document.activeElement) && document.activeElement !== document.body
    || [...region.querySelectorAll('input, textarea')].some(field => !['hidden', 'checkbox', 'radio'].includes(field.type) && field.value !== field.defaultValue);
  // The rows that still wait: id => row. A row past its deadline no longer counts.
  const waitingIn = root => new Map(waitingRows(root).filter(row => !row.dataset.late).map(row => [row.dataset.request, row]));
  const count = n => n === 1 ? '1 request waiting' : n + ' requests waiting';
  const status = text => document.querySelectorAll('[data-refresh-status]').forEach(el => { el.textContent = text; });
  // The server's clock, for "Checked HH:MM UTC": the offset comes from the status line's data-now.
  const stamp = document.querySelector('[data-refresh-status][data-now]');
  const clockSkew = stamp ? Number(stamp.dataset.now) * 1000 - Date.now() : 0;
  const checked = () => 'Checked ' + new Date(Date.now() + clockSkew).toISOString().slice(11, 16) + ' UTC';
  // The waiting heading row and its jump link (the entry page) show only while their region holds something. A row
  // that holds the focus stays until the focus has left it.
  const showWith = () => document.querySelectorAll('[data-shown-with]').forEach(el => {
    const region = document.querySelector('[data-refresh="' + el.dataset.shownWith + '"]');
    const empty = !region?.children.length;
    if (!empty || !el.contains(document.activeElement)) el.hidden = empty;
  });
  let due = Date.now() + 30000;
  let running = false;
  const refresh = async manual => {
    if (running || (!manual && (document.hidden || Date.now() < due))) return;
    running = true;
    due = Date.now() + 30000;
    try {
      const response = await fetch(refreshUrl, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
      // Signed out or sent elsewhere: the page itself has to follow.
      if (response.redirected || [401, 403, 419].includes(response.status)) { location.assign(refreshUrl); return; }
      if (!response.ok) throw new Error('refresh failed');
      const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
      const before = waitingIn(document);
      let skipped = false;
      regions().forEach(region => {
        const next = fresh.querySelector('[data-refresh="' + region.dataset.refresh + '"]');
        if (!next) return;
        if (inUse(region)) { skipped = true; return; }
        region.replaceChildren(...next.childNodes);
        tableRoles(region);
      });
      tick();
      const after = waitingIn(document);
      showWith();
      const arrived = [...after.keys()].filter(id => !before.has(id)).length;
      const gone = [...before.keys()].filter(id => !after.has(id));
      const parts = [];
      if (gone.length === 1) {
        // Its row in the history says how it ended: expired, denied, cancelled, succeeded, failed, running.
        const state = document.querySelector('table.requests tr[data-request="' + CSS.escape(gone[0]) + '"]:not([data-expires])')?.dataset.state;
        parts.push('Request ' + ({ approved: 'allowed', running: 'allowed and running' }[state] || state || 'no longer waiting'));
      } else if (gone.length) parts.push(gone.length + ' requests no longer waiting');
      if (arrived || gone.length) parts.push(after.size ? count(after.size) : 'No requests waiting');
      if (skipped && manual) parts.push('The section you are working in was left as it is');
      const text = parts.length ? parts.join('. ') + '.' : '';
      if (text) say(text); else if (manual) say('Requests are up to date.');
      // A press always gets an answer in words; a quiet round only moves the time.
      status((text ? text + ' ' : manual ? 'Up to date. ' : '') + checked());
    } catch {
      // No connection or a server error: the next round tries again; a press says so.
      if (manual) { status('Could not refresh. Check the connection and try again.'); say('Could not refresh requests.'); }
    } finally { running = false; }
  };
  setInterval(() => refresh(false), 5000);
  document.addEventListener('visibilitychange', () => refresh(false));
  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-refresh-now]');
    if (!button || event.ctrlKey || event.metaKey || event.shiftKey || event.button) return;
    event.preventDefault();
    refresh(true);
  });
}
// Filter bars: an inline bar on wide screens, a closed drawer on phones. A resize follows along until the user
// opens or closes the bar themselves.
const wide = matchMedia('(min-width: 901px)');
const filterBars = document.querySelectorAll('details[data-wide-open]');
// data-wide-static (the entry page's jump links): on wide screens the bar is always open and its summary is a plain
// label, out of the tab order; on phones it is an ordinary closed drawer.
const fixedBar = bar => {
  if (!bar.hasAttribute('data-wide-static')) return;
  const label = bar.querySelector('summary');
  if (wide.matches) { bar.open = true; label?.setAttribute('tabindex', '-1'); } else label?.removeAttribute('tabindex');
};
filterBars.forEach(bar => {
  if (wide.matches) bar.open = true;
  fixedBar(bar);
  bar.querySelector('summary')?.addEventListener('click', event => { if (wide.matches && bar.hasAttribute('data-wide-static')) event.preventDefault(); else bar.dataset.toggled = '1'; });
});
wide.addEventListener('change', () => filterBars.forEach(bar => { if (!bar.dataset.toggled || bar.hasAttribute('data-wide-static')) bar.open = wide.matches; fixedBar(bar); }));
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
addEventListener('hashchange', openTarget);
openTarget();
// Error summary of a form that failed validation. The server prints it under the page head; here it moves to the
// form that was sent, each message links to its field and is repeated under it, and the summary takes focus so it
// is read and seen first. Server names use dots: "field_key.0".
const summary = document.getElementById('form-errors');
if (summary) {
  // The forms that post to the address that failed; several rows or cards may share it ("Add an environment" in
  // every project). data-sent holds the short non-secret values that were posted: a form whose hidden inputs say
  // something else (another project_id) is not the one that was sent.
  let values = {};
  try { values = JSON.parse(summary.dataset.sent || '{}') || {}; } catch { values = {}; }
  const hiddenMatch = form => [...form.elements].every(el => el.type !== 'hidden' || !el.name || el.name === '_token' || !(el.name in values) || values[el.name] === el.value);
  const posted = [...document.forms].filter(form => { try { return new URL(form.action).pathname === summary.dataset.form; } catch { return false; } });
  const matching = posted.filter(hiddenMatch);
  const sent = matching.length ? matching : posted;
  // A control the person can reach: not hidden, not disabled, not inside a hidden block.
  const usable = el => el.type !== 'hidden' && !el.disabled && !el.closest('[hidden]');
  const findIn = (scope, key) => {
    const [head, ...rest] = key.split('.');
    const names = [key, head + rest.map(part => '[' + part + ']').join(''), head + '[]', head];
    for (const name of names) {
      const hits = [...scope.querySelectorAll('input, select, textarea')].filter(el => el.name === name && usable(el));
      // "field_key.2" is the third field_key[] row.
      const field = name === head + '[]' && /^\d+$/.test(rest[0] || '') ? hits[Number(rest[0])] || hits[0] : hits[0];
      if (field) return field;
    }
    return null;
  };
  const items = [...summary.querySelectorAll('li[data-field]')];
  // The failing form is the sent form holding the most of the named fields; the first one wins a tie.
  let form = null;
  let best = 0;
  sent.forEach(candidate => {
    const score = new Set(items.map(item => item.dataset.field).filter(key => findIn(candidate, key))).size;
    if (score > best) { best = score; form = candidate; }
  });
  // No named field found (a message about the form as a whole): the one form left, or the one the hidden values picked.
  form ??= sent.length === 1 || (matching.length && matching.length < posted.length) ? sent[0] : null;
  if (form) {
    // A form inside a collapsed editor is opened, so the summary and the field can be seen.
    for (let el = form.parentElement; el; el = el.parentElement) if (el.tagName === 'DETAILS' && !el.classList.contains('menu')) el.open = true;
    const table = form.closest('table');
    if (table) (table.closest('.scroll') || table).before(summary);
    else if (form.matches('form.action') || form.parentElement.matches('.inner')) {
      // Inside the card, under its heading.
      const heading = form.querySelector(':scope > h3');
      if (heading) heading.after(summary); else form.prepend(summary);
    } else (form.closest('.card-head, .fold-foot, .controls') || form).before(summary);
  }
  const seen = new Set();
  items.forEach((item, index) => {
    const field = (form && findIn(form, item.dataset.field)) || (form ? null : findIn(document, item.dataset.field));
    if (!field) return;
    if (!field.id) field.id = 'field-error-' + index;
    field.setAttribute('aria-invalid', 'true');
    // The first message of a field is repeated right under it.
    if (!seen.has(field) && field.type !== 'checkbox' && field.type !== 'radio') {
      seen.add(field);
      const note = document.createElement('small');
      note.className = 'field-error';
      note.id = field.id + '-error';
      note.textContent = item.textContent;
      const holder = field.closest('label, .field, td');
      if (holder) holder.append(note); else field.after(note);
      // Inside a label the message is already part of the field's name, unless an aria-label names the field.
      if (!holder?.matches('label') || field.hasAttribute('aria-label')) field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), note.id].filter(Boolean).join(' '));
    }
    const link = document.createElement('a');
    link.href = '#' + field.id;
    link.textContent = item.textContent;
    link.addEventListener('click', event => {
      event.preventDefault();
      // A field inside a collapsed editor is opened first.
      for (let el = field.parentElement; el; el = el.parentElement) if (el.tagName === 'DETAILS') el.open = true;
      field.scrollIntoView({ block: 'center' });
      field.focus({ preventScroll: true });
    });
    item.replaceChildren(link);
  });
  summary.focus({ preventScroll: true });
  summary.scrollIntoView({ block: 'center' });
}
