// Screen readers hear short status changes through the polite live region in the layout.
const say = text => { const live = document.getElementById('live'); if (live) { live.textContent = ''; setTimeout(() => { live.textContent = text; }, 50); } };
// Date fields are text in ISO form (placeholder YYYY-MM-DD). A phone keyboard may make "-" awkward, so eight digits
// (20261109) and the same date with "/" or "." become 2026-11-09 when the field changes, loses the focus or its
// form is sent (capture: before the other submit handlers read the value). Anything else is left for the pattern
// and the server to refuse.
const isoDate = value => { const m = String(value).trim().match(/^(\d{4})([-\/.]?)(\d{2})\2(\d{2})$/); return m ? m[1] + '-' + m[3] + '-' + m[4] : value; };
const isoField = field => { if (field?.matches?.('input[placeholder="YYYY-MM-DD"]')) { const next = isoDate(field.value); if (next !== field.value) field.value = next; } };
['change', 'focusout'].forEach(type => document.addEventListener(type, event => isoField(event.target)));
// Enter in the field: the value is put right before the browser checks the pattern.
document.addEventListener('keydown', event => { if (event.key === 'Enter') isoField(event.target); }, true);
document.addEventListener('submit', event => event.target.querySelectorAll?.('input[placeholder="YYYY-MM-DD"]').forEach(isoField), true);
// The buttons that send a form. form.elements, not a query inside the form: a submit button may stand outside its
// form (form="…": Save project in a form foot).
const submitters = form => [...form.elements].filter(el => el.matches('button:not([type=button])'));
// A hidden value cannot be copied any more, so its Copy button is switched off too and gets its first wording back.
// data-hidden-text says what to do when the value cannot be revealed again (the one-time agent token).
document.querySelectorAll('[data-copy]').forEach(button => { button.dataset.label = button.textContent; });
const hideSecrets = (selector = '[data-secret]') => document.querySelectorAll(selector).forEach(el => {
  el.textContent = el.dataset.hiddenText || 'Hidden. Reveal again to see it.'; el.dataset.hidden = '1';
  if (el.id) document.querySelectorAll('[data-copy]').forEach(button => { if (button.dataset.copy === el.id) { button.disabled = true; button.textContent = button.dataset.label; } });
  if (el.id) document.getElementById(el.id + '-copy-note')?.remove();
});
// MFA enrollment keys stay visible until the form is submitted; other secrets auto-hide.
// An older version on screen (data-version) is named in everything that is said and shown after the hide; the note
// above the values (data-hidden-note) changes from "hide again after 30 seconds" to what happened.
// "Hide values" ([data-hide-values], shown by script while values are on screen) does the same at once, on demand:
// every revealed field is concealed, its Copy is switched off, it is said, and the focus moves to the button that
// reveals the values again. The button goes with the values, also when the 30 seconds end first.
if (document.querySelector('[data-secret]:not([data-keep])')) {
  const hideNow = [...document.querySelectorAll('[data-hide-values]')];
  let concealed = false;
  const conceal = manual => {
    if (concealed) return;
    concealed = true;
    clearTimeout(timer);
    const version = document.querySelector('[data-secret][data-version]')?.dataset.version;
    hideSecrets('[data-secret]:not([data-keep])');
    document.querySelectorAll('[data-hidden-note]').forEach(note => { note.textContent = note.dataset.hiddenNote; });
    // With the values gone, revealing them again is the page's main action: its button turns solid.
    // (Not while an action is held: the held button is then the one solid button, and the release restores this one.)
    const holding = document.querySelector('.confirm-inline [data-held-row]:not([hidden])');
    const again = [...document.querySelectorAll('[data-go-when-hidden]')];
    again.forEach(button => { if (holding) button.dataset.heldGo = '1'; else button.classList.add('go'); });
    // A password block that only points to the page's one field keeps its card's button plain: it looks again.
    document.dispatchEvent(new Event('vault:solid'));
    // The focus never stays on a button that is gone: it moves to the way back to the values.
    const focused = manual || hideNow.includes(document.activeElement);
    hideNow.forEach(button => { button.hidden = true; });
    if (focused) again[0]?.focus();
    const tail = version ? 'Copy is off until you reveal version ' + version + ' again.' : 'Copy is off until you reveal them again.';
    say((version ? 'Version ' + version + ' values hidden' : 'Secret values hidden') + (manual ? '. ' : ' again. ') + tail);
  };
  const timer = setTimeout(() => conceal(false), 30000);
  hideNow.forEach(button => { button.hidden = false; button.addEventListener('click', () => conceal(true)); });
}
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
    // The one-time token page lifts its leave guard on this.
    button.dispatchEvent(new CustomEvent('vault:copied', { bubbles: true }));
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
      // In a form foot the note goes under the whole row, so the note and buttons of the foot keep their places.
      // A button in a block label ("Copy example") gets the note under that label, never inside it.
      (button.closest('.form-foot') || button.closest('.controls') || button.closest('.block-label') || button).after(note);
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
    const { box, button, nodes, label } = step;
    step = null;
    box.before(button);
    box.remove();
    button.replaceChildren(...nodes);
    button.classList.remove('armed');
    if (label === null) button.removeAttribute('aria-label'); else button.setAttribute('aria-label', label);
    if (focus) button.focus();
  };
  const arm = button => {
    const text = button.textContent.trim();
    // What the control held (a link may hold a count chip) comes back as it was.
    const nodes = [...button.childNodes];
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
    step = { box, button, nodes, label };
    armedAt = Date.now();
    button.focus();
    say(question + ' Press Confirm to go on, or Cancel.');
  };
  // A double click must not confirm what its first click only asked for.
  return { arm, disarm, armed: () => Boolean(step), tooSoon: () => Date.now() - armedAt < 500 };
};
// Generate fills a secret field with a random value. A field that already holds a value is only replaced after a
// second press ("Replace value?").
const wireGenerate = (root = document) => root.querySelectorAll('[data-generate]').forEach(button => {
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
wireGenerate();
// Every secret field (a password input in .with-button) gets a Reveal/Hide button right after it, before Generate
// when the field has one, so a generated or typed value can be checked before saving.
const wireReveal = (root = document) => root.querySelectorAll('.with-button > input[type=password]').forEach(input => {
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
  // An empty field has nothing to reveal: the button is off until something is typed or generated, and a field
  // that is emptied while shown is masked again.
  const fit = () => {
    const empty = input.value === '';
    if (empty && input.type !== 'password') mask();
    show.disabled = empty;
  };
  sync();
  fit();
  ['input', 'change'].forEach(type => input.addEventListener(type, fit));
  // A value the browser put back (back/forward, autofill on load) counts too.
  addEventListener('pageshow', fit);
  show.addEventListener('click', () => {
    if (input.value === '') { fit(); return; }
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
wireReveal();
// "Reload types" on the Add an entry form, "Reload fields" on the Edit entry form. Entry types are edited in Setup,
// which the form's link opens in a new tab; back here the button fetches this page again and swaps only the Type
// options (Add) and the type field rows (#type-fields). Nothing else is touched, so the draft stays: a value typed
// into a type field is put back into the field with the same id (the same type and key) or, for the chosen type,
// the same name, and every "Clear the current … value" box (id clear-<type>-<key>) gets back the state it had,
// checked or not. Values never leave this page: they are copied from the old fields to the new ones. The status
// line says what was kept and what could not be: it never claims more than happened.
document.querySelectorAll('[data-reload-types]').forEach(button => {
  const row = button.closest('[data-reload-types-row]');
  const status = row?.querySelector('[data-reload-status]');
  const rows = document.getElementById('type-fields');
  if (!type || !rows) return;
  // Add: the type is a select. Edit: the type is fixed (a hidden input) and only its fields are reloaded.
  const select = type.matches('select');
  button.hidden = false;
  const tell = text => { if (status) status.textContent = text; say(text); };
  let busy = false;
  button.addEventListener('click', async () => {
    if (busy) return;
    busy = true;
    try {
      const response = await fetch(location.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
      if (response.redirected || !response.ok) throw new Error('reload failed');
      const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
      const nextType = fresh.getElementById('entry-type');
      const nextRows = fresh.getElementById('type-fields');
      if (!nextType || nextType.matches('select') !== select || !nextRows) throw new Error('reload failed');
      const controls = scope => [...scope.querySelectorAll('input, textarea')].filter(el => el.name && el.type !== 'hidden');
      const byId = new Map();
      const byName = new Map();
      // Every clear box by id: checked or not. marked: the checked ones of the type on screen.
      const boxes = new Map();
      const marked = new Set();
      controls(rows).forEach(el => {
        if (el.type === 'checkbox') {
          if (!el.id) return;
          boxes.set(el.id, el.checked);
          if (el.checked && !el.disabled) marked.add(el.id);
          return;
        }
        if (el.value === '') return;
        if (el.id) byId.set(el.id, el.value);
        if (!el.disabled) byName.set(el.name, el.value);
      });
      const chosen = type.value;
      const before = select ? [...type.options].filter(option => option.value).length : 0;
      if (select) {
        type.replaceChildren(...[...nextType.options].map(option => document.importNode(option, true)));
        type.value = [...type.options].some(option => option.value === chosen) ? chosen : '';
      }
      rows.replaceChildren(...[...nextRows.childNodes].map(node => document.importNode(node, true)));
      const placed = new Set();
      let stillMarked = 0;
      controls(rows).forEach(el => {
        if (el.type === 'checkbox') {
          if (!el.id || !boxes.has(el.id)) return;
          el.checked = boxes.get(el.id);
          if (marked.has(el.id)) stillMarked++;
          return;
        }
        const mine = (el.closest('[data-types]')?.dataset.types || '').split(',').includes(type.value);
        const kept = el.id && byId.has(el.id) ? byId.get(el.id) : mine ? byName.get(el.name) : undefined;
        if (kept === undefined) return;
        el.value = kept;
        placed.add(el.name);
      });
      let lost = 0;
      byName.forEach((value, name) => { if (!placed.has(name)) lost++; });
      const unmarked = marked.size - stillMarked;
      wireGenerate(rows);
      wireReveal(rows);
      fields();
      const after = select ? [...type.options].filter(option => option.value).length : 0;
      const parts = [select ? 'Types reloaded: ' + (after === 1 ? '1 type' : after + ' types') + (after > before ? ', ' + (after - before) + ' new' : '') + '.' : 'Fields reloaded.'];
      if (chosen && !type.value) parts.push('The type you chose is gone: choose a type.');
      if (lost) parts.push(lost === 1 ? '1 typed value belonged to a field that no longer exists.' : lost + ' typed values belonged to fields that no longer exist.');
      if (unmarked) parts.push(unmarked === 1 ? '1 value marked to clear is no longer marked: its field is gone or is now required.' : unmarked + ' values marked to clear are no longer marked: their fields are gone or are now required.');
      const typed = byName.size - lost;
      if (typed && stillMarked) parts.push('What you typed was kept, and the values marked to clear are still marked.');
      else if (typed) parts.push(lost ? 'The other typed values were kept.' : 'What you typed was kept.');
      else if (stillMarked) parts.push(stillMarked === 1 ? 'The value marked to clear is still marked.' : 'The values marked to clear are still marked.');
      else if (!lost && !unmarked) parts.push('The rest of the form is unchanged.');
      tell(parts.join(' '));
    } catch {
      tell('Could not reload' + (select ? ' the types' : ' the fields') + '. Nothing was changed. Check the connection and try again.');
    } finally { busy = false; }
  });
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
// Inline password confirmation (confirm-inline). While the confirmation is valid the block is the line "Confirmed
// until HH:MM UTC." and its field is hidden and switched off. When it has expired (the server's clock decides;
// data-now turns it into an offset from this device's clock) the field is shown and required: on a page left open,
// when the tab is looked at again, and at the latest on submit. 5 seconds early, so a submit never arrives late.
// A block inside a form guards that form. A shared block (.shared: Reveal on the entry page, the decisions of the
// waiting requests) stands outside its forms; they name it with data-confirm-from, and its password is copied into
// the form that is sent. A submit that needs the password and has none is held: the focus moves to the field, and
// the block shows a button that names the held action ([data-held]: "Allow once: shop.admin-login, request
// 1a2b3c4d", from the pressed button's data-held-name), with the request it belongs to beside it ([data-held-what],
// the button's description). That button, or Enter in the field, sends the held form
// (Enter without one sends the block's default form, data-confirm-default). A password entered first where there
// is neither gets the visible line "Now choose an action below." (.pending), which is also said.
// A page states its confirmation once. A shared block that names an earlier one (data-primary: Reveal on the entry
// page names the waiting requests' block) is deferred while that one is on screen: it shows nothing while the
// confirmation is valid and, once it has expired, a line pointing to the one password field, which then serves this
// block's forms too. A block marked data-quiet-valid (Members, above the rows) shows nothing while the confirmation
// is valid, because a form on the same page states it. A block is not deferred while its own field holds the focus
// or something typed (the primary block may appear under a person who is typing here); it is looked at again when
// the field loses the focus. While a deferred block points to the one field, the solid button of its own form or
// card ("Add member", "Reveal current") is plain: nothing on the page is solid until the password is there
// (data-deferred-go marks it, and it is solid again when the pointer goes).
// The held button's wording: the pressed button's data-held-name, plus the chosen option of the form field named by
// data-held-field ("Change Dana's role to " + "Editor"; a change of that field while held changes the wording).
// Data in it keeps its case: what follows ": ", the data-held-data text after data-held-lead ("Change " + "Dana"),
// or the field's value after a wording without a colon ("Add entry type " + "FTP server").
// While an action is held, the held button is the page's one solid button: every other solid button or link in the
// page ("Reveal current", "Add member", "Add project") is plain until the hold ends, and solid again after it.
const confirmBoxes = new Map();
document.querySelectorAll('.confirm-inline[data-confirmed-until]').forEach(box => {
  const input = box.querySelector('input[type=password]');
  if (!input) return;
  const shared = box.classList.contains('shared');
  const skew = box.dataset.now ? Number(box.dataset.now) * 1000 - Date.now() : 0;
  const until = Number(box.dataset.confirmedUntil) * 1000;
  const verb = box.dataset.verb || 'continue';
  const ask = box.dataset.ask || 'Your password confirmation has expired. Enter your current password to ' + verb + '.';
  // With script the shared field works, so the line asks for the password instead of pointing at the confirm page.
  box.querySelectorAll('[data-script-text]').forEach(el => { el.textContent = el.dataset.scriptText; });
  const primary = shared && box.dataset.primary ? document.getElementById(box.dataset.primary) : null;
  const quietValid = box.hasAttribute('data-quiet-valid');
  let shown = null;
  let wasExpired = null;
  const own = primary ? box.closest('form, .card') : null;
  const plainOwn = pointing => {
    if (!own) return;
    own.querySelectorAll(':is(button, .btn):is(.go, [data-held-go], [data-deferred-go]):not([data-held])').forEach(button => {
      if (pointing) { button.classList.remove('go'); button.dataset.deferredGo = '1'; return; }
      if (!button.dataset.deferredGo) return;
      delete button.dataset.deferredGo;
      // While an action is held, the held button stays the only solid one; the release makes this one solid.
      if (!button.dataset.heldGo) button.classList.add('go');
    });
  };
  const check = () => {
    const expired = until - (Date.now() + skew) <= 5000;
    const busy = !input.disabled && !input.closest('[hidden]') && (document.activeElement === input || input.value !== '');
    const deferred = Boolean(primary && !primary.hidden) && !busy;
    plainOwn(deferred && expired);
    const key = expired + '/' + deferred;
    if (key === shown) return expired;
    shown = key;
    input.disabled = !expired || deferred;
    input.required = expired && !deferred;
    box.classList.toggle('is-needed', expired && !deferred);
    box.classList.toggle('is-deferred', deferred);
    box.classList.toggle('is-quiet', !expired && (deferred || quietValid));
    box.querySelectorAll('[data-when]').forEach(el => {
      const when = el.dataset.when;
      el.hidden = when === 'deferred' ? !(deferred && expired) : deferred || (when === 'expired') !== expired;
    });
    // Said once, by the block that shows the field.
    // Not by a block nobody can see: inside something hidden, or inside a closed <details> (a collapsed editor).
    if (wasExpired === false && expired && !deferred && !box.closest('[hidden], details:not([open])')) say(ask);
    wasExpired = expired;
    return expired;
  };
  check();
  if (primary) {
    new MutationObserver(check).observe(primary, { attributes: true, attributeFilter: ['hidden'] });
    // The field was left, or emptied: the block may be deferred now.
    input.addEventListener('focusout', () => setTimeout(check, 0));
  }
  // The pointer of a deferred block leads to the one field and puts the focus in it.
  box.querySelectorAll('a[data-confirm-goto]').forEach(link => link.addEventListener('click', event => {
    const field = document.getElementById(link.dataset.confirmGoto)?.querySelector('input[type=password]');
    if (!field || field.disabled) return;
    event.preventDefault();
    field.scrollIntoView({ block: 'center' });
    field.focus({ preventScroll: true });
  }));
  setInterval(check, 5000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
  document.addEventListener('vault:solid', check);
  // Neither a typed password nor a copy of it stays in a page that is left or restored.
  addEventListener('pagehide', () => { input.value = ''; document.querySelectorAll('input[type=hidden][data-confirm-copy]').forEach(copy => copy.remove()); });
  if (!shared) {
    box.closest('form')?.addEventListener('submit', event => {
      if (!check() || input.value) return;
      event.preventDefault();
      input.focus();
      say(ask);
    });
    return;
  }
  const hint = box.querySelector('[data-pending]');
  const state = { box, input, check, held: null, primary: box.dataset.primary || null, deferred: () => box.classList.contains('is-deferred') };
  // The held form may have been replaced by a refresh: the same action (and button) is looked up again.
  const heldForm = () => {
    const held = state.held;
    if (held && !held.form.isConnected) {
      const form = [...document.querySelectorAll('form[data-confirm-from="' + box.id + '"]')].find(candidate => candidate.getAttribute('action') === held.action && [...candidate.elements].every(el => el.type !== 'hidden' || el.name !== 'version' || el.value === held.version));
      const button = form && submitters(form).find(candidate => candidate.value === held.value);
      state.held = form && button && !button.disabled ? { ...held, form, button } : null;
    }
    return state.held;
  };
  const heldRow = box.querySelector('[data-held-row]');
  const heldButton = box.querySelector('[data-held]');
  // What the held action is about (a request: agent, action, entry, project / environment, id), copied from the
  // <template data-held-summary> beside the held form. It is the held button's description.
  const heldWhat = box.querySelector('[data-held-what]');
  const showWhat = form => {
    if (!heldWhat) return;
    const source = form?.parentElement?.querySelector(':scope > template[data-held-summary]');
    heldWhat.replaceChildren(...(source ? [document.importNode(source.content, true)] : []));
    heldWhat.hidden = !source;
    // The summary is the held button's description only while it says something.
    if (source && heldWhat.id) heldButton?.setAttribute('aria-describedby', heldWhat.id); else heldButton?.removeAttribute('aria-describedby');
  };
  const solid = on => {
    if (on) document.querySelectorAll('main :is(button, .btn).go:not([data-held])').forEach(button => { button.classList.remove('go'); button.dataset.heldGo = '1'; });
    else document.querySelectorAll('main [data-held-go]').forEach(button => { if (!button.dataset.deferredGo) button.classList.add('go'); delete button.dataset.heldGo; });
  };
  const line = text => { if (hint) { hint.textContent = text; hint.hidden = !text; } };
  const showHeld = (name, button) => {
    if (!heldRow || !heldButton) return;
    heldRow.hidden = !name;
    if (!name) { heldButton.replaceChildren(); showWhat(null); return; }
    // "Allow once: " stays a button word; what follows is data and keeps its case. So does a name inside the
    // wording: the data-held-data text right after data-held-lead ("Change " + "Dana" + "'s role to Editor").
    const text = document.createElement('span');
    const data = value => { const el = document.createElement('span'); el.className = 'name'; el.textContent = value; return el; };
    const lead = button?.dataset.heldLead || '';
    const marked = button?.dataset.heldData || '';
    const cut = name.indexOf(': ');
    if (marked && name.startsWith(lead + marked)) text.append(lead, data(marked), name.slice(lead.length + marked.length));
    else if (cut >= 0) text.append(name.slice(0, cut + 2), data(name.slice(cut + 2)));
    else if (button?.dataset.heldField && button.dataset.heldName && name.length > button.dataset.heldName.length && name.startsWith(button.dataset.heldName)) text.append(button.dataset.heldName, data(name.slice(button.dataset.heldName.length)));
    else text.append(name);
    heldButton.replaceChildren(text);
  };
  const heldName = (form, button) => {
    const field = button?.dataset.heldField ? form.elements[button.dataset.heldField] : null;
    const chosen = field?.selectedOptions ? field.selectedOptions[0]?.textContent.trim() : field?.value;
    return (button?.dataset.heldName || button?.getAttribute('aria-label') || button?.textContent.trim() || 'the action') + (chosen || '');
  };
  // why: what is said instead of "…has expired" (a password that was not right holds the action again).
  state.hold = (form, button, why = 'Your password confirmation has expired. Enter your current password') => {
    const name = heldName(form, button);
    state.held = { form, button, name, action: form.getAttribute('action'), value: button?.value || '', version: form.elements.version?.value };
    line('');
    showHeld(name, button);
    showWhat(form);
    solid(true);
    box.scrollIntoView({ block: 'center' });
    input.focus({ preventScroll: true });
    say(why + ', then press: ' + name + '.');
  };
  state.release = () => { state.held = null; showHeld(''); line(''); solid(false); };
  // The field that is part of the wording was changed while its action is held: the button says the new choice.
  document.addEventListener('change', event => {
    const held = state.held;
    if (!held?.button?.dataset.heldField || !held.form.contains(event.target)) return;
    held.name = heldName(held.form, held.button);
    showHeld(held.name, held.button);
  });
  const send = () => {
    const held = heldForm();
    const form = held?.form || document.querySelector('form[data-confirm-from="' + box.id + '"][data-confirm-default]');
    if (!form) {
      const gone = Boolean(heldRow && !heldRow.hidden);
      state.release();
      line(gone ? 'That action is no longer available. Choose an action below.' : 'Now choose an action below.');
      say(gone ? 'That action is no longer available. Choose an action below.' : 'Password entered. Now choose an action below.');
      return;
    }
    const button = held?.button || submitters(form)[0];
    if (button?.disabled) { state.release(); line('That action is no longer available. Choose an action below.'); say('That action is no longer available. Choose an action below.'); return; }
    // The held button names the action and was pressed on purpose: a form that asks first (data-confirm: Remove)
    // does not ask again.
    form.dataset.heldSend = '1';
    form.requestSubmit(button || undefined);
    delete form.dataset.heldSend;
  };
  input.addEventListener('keydown', event => {
    if (event.key !== 'Enter' || !input.value) return;
    event.preventDefault();
    send();
  });
  // The line belongs to the password it answered: it goes when the field is emptied.
  input.addEventListener('input', () => { if (!input.value && !state.held) line(''); });
  heldButton?.addEventListener('click', () => {
    if (!input.value) { input.focus(); say('Enter your current password first.'); return; }
    send();
  });
  confirmBoxes.set(box.id, state);
});
// The forms may arrive with a refresh, so one listener on the page serves them all.
document.addEventListener('submit', event => {
  const form = event.target instanceof HTMLFormElement && event.target.matches('form[data-confirm-from]') ? event.target : null;
  let state = form && confirmBoxes.get(form.dataset.confirmFrom);
  if (!state || event.defaultPrevented) return;
  // A deferred block's forms use the one password field that is on screen.
  state.check();
  const first = state.primary && confirmBoxes.get(state.primary);
  if (first && !first.box.hidden && state.deferred()) state = first;
  form.querySelectorAll('input[type=hidden][data-confirm-copy]').forEach(copy => copy.remove());
  if (!state.check()) return;
  if (!state.input.value) {
    event.preventDefault();
    state.hold(form, event.submitter instanceof HTMLButtonElement ? event.submitter : submitters(form)[0]);
    return;
  }
  const copy = document.createElement('input');
  copy.type = 'hidden';
  copy.name = 'confirm_password';
  copy.value = state.input.value;
  copy.setAttribute('data-confirm-copy', '');
  form.append(copy);
  state.release();
});
// A page whose content cannot be shown again (the one-time token) guards every way out inside the app while the
// token has not been copied. [data-leave-guard] holds the question ("Token copied?"); every link that leaves the
// page, and Sign out, asks it first in the same two steps. A copy that worked lifts the guard, and so does "I've
// saved it" ([data-leave-clear]) for a token that was selected and copied by hand. Without script the links are
// plain links.
// The ways out that are not links of the page (Back, reload, closing the tab) get the browser's own "Leave site?"
// question from a beforeunload handler. It is registered only while the token is unsaved, and taken away by a copy
// that worked, by "I've saved it", and by a departure the person confirmed in the page (the second press of an
// armed link or of Sign out), so nobody is asked twice.
const guard = document.querySelector('[data-leave-guard]');
if (guard) {
  let guarding = true;
  const beforeLeave = event => { event.preventDefault(); event.returnValue = ''; };
  addEventListener('beforeunload', beforeLeave);
  const allowLeave = () => removeEventListener('beforeunload', beforeLeave);
  const steps = [];
  const step = armed => { const made = twoStep(guard.dataset.leaveGuard, armed); steps.push(made); return made; };
  const only = keep => steps.forEach(other => { if (other !== keep) other.disarm(false); });
  document.querySelectorAll('a[href]:not(.skip)').forEach(link => {
    if (link.getAttribute('href').startsWith('#')) return;
    const mine = step(() => 'Leave this page');
    link.addEventListener('click', event => {
      if (!guarding || event.ctrlKey || event.metaKey || event.shiftKey || event.button) return;
      if (mine.armed()) { if (mine.tooSoon()) event.preventDefault(); else allowLeave(); return; }
      event.preventDefault();
      only(mine);
      mine.arm(link);
    });
  });
  document.querySelectorAll('form').forEach(form => {
    if (guard.contains(form)) return;
    const mine = step(armedText);
    form.addEventListener('submit', event => {
      if (!guarding || event.defaultPrevented) return;
      if (mine.armed()) { if (mine.tooSoon()) event.preventDefault(); else allowLeave(); return; }
      const button = event.submitter instanceof HTMLButtonElement ? event.submitter : submitters(form)[0];
      if (!button) return;
      event.preventDefault();
      only(mine);
      mine.arm(button);
    });
  });
  // Capture: the question is withdrawn before an open header menu hears the key and closes around it.
  addEventListener('keydown', event => { if (event.key !== 'Escape') return; const open = steps.find(made => made.armed()); if (open) { event.stopPropagation(); open.disarm(true); } }, true);
  addEventListener('pageshow', event => { if (event.persisted) only(null); });
  const saved = [...guard.querySelectorAll('[data-leave-clear]')];
  const lift = () => { guarding = false; allowLeave(); only(null); saved.forEach(button => { button.hidden = true; }); };
  saved.forEach(button => {
    button.hidden = false;
    button.addEventListener('click', () => {
      lift();
      // The button is gone: the focus goes to the way on.
      guard.querySelector('[data-leave-done]')?.focus();
      say('Marked as saved. You can leave this page now.');
    });
  });
  guard.addEventListener('vault:copied', lift);
}
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
    if (event.defaultPrevented || form.dataset.heldSend) return;
    if (step.armed()) {
      if (step.tooSoon()) event.preventDefault();
      return;
    }
    const button = event.submitter instanceof HTMLButtonElement ? event.submitter : submitters(form)[0];
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
  const submit = submitters(form)[0];
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
// catches up when it is looked at again. A request board is merged row by row (rows match by data-request): only
// a row that holds the focus or a started input is left as it is, and its siblings are updated, added and removed
// around it, so a new request shows up while the owner types a code into another one. The waiting heading counts
// the rows shown. What changed is said in the live region; "Refresh requests" beside the waiting heading does the
// same on demand, and "Checked HH:MM UTC" next to it says when the page last matched the server. A round
// that failed, or a row in use that no longer matches the server, reads "Check delayed · last checked HH:MM UTC":
// the time does not advance. The status uses the one verb, check.
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
  const clock = () => new Date(Date.now() + clockSkew).toISOString().slice(11, 16);
  // When the page last matched the server: the time the server printed, then every round that went through.
  let last = stamp?.textContent.match(/\d\d:\d\d/)?.[0] || clock();
  const checked = () => 'Checked ' + (last = clock()) + ' UTC';
  const delayed = (why = 'Check delayed') => why + ' \u00B7 last checked ' + last + ' UTC';
  // One board, row by row. The rows in use stay where they are (moving a row would drop its focus); every other row
  // is taken from the fresh page and put before or after them in the fresh order. Returns null when no row is in
  // use (the region is then replaced whole), else how many kept rows no longer match the server: gone from this
  // board, or in another state.
  const mergeRows = (region, next) => {
    // An expired row is switched off: what was typed in it no longer holds it, only the focus does.
    const kept = [...region.querySelectorAll('table.requests tbody tr[data-request]')].filter(row => row.dataset.late ? row.contains(document.activeElement) : inUse(row));
    if (!kept.length) return null;
    const body = kept[0].parentNode;
    const fresh = [...next.querySelectorAll('table.requests tbody tr[data-request]')];
    const states = new Map(fresh.map(row => [row.dataset.request, row.dataset.state]));
    [...body.children].forEach(row => { if (!kept.includes(row)) row.remove(); });
    let at = 0;
    fresh.forEach(row => {
      const i = kept.findIndex(mine => mine.dataset.request === row.dataset.request);
      if (i >= 0) { at = Math.max(at, i + 1); return; }
      body.insertBefore(document.adoptNode(row), kept[at] || null);
    });
    return kept.filter(row => !row.dataset.late && states.get(row.dataset.request) !== row.dataset.state).length;
  };
  // The waiting heading says how many rows are shown under it.
  const heading = () => {
    const title = document.querySelector('[data-refresh="waiting-title"]');
    const board = document.querySelector('[data-refresh="waiting"]');
    if (!title || !board || title.firstChild?.textContent.trim() !== 'Requests waiting for you') return;
    const n = waitingIn(board).size;
    let chip = title.querySelector('[data-waiting-count]');
    if (!n) { if (chip) { chip.previousSibling?.nodeType === 3 && chip.previousSibling !== title.firstChild && chip.previousSibling.remove(); chip.remove(); } return; }
    if (!chip) {
      chip = document.createElement('span');
      chip.className = 'chip warn';
      chip.setAttribute('data-waiting-count', '');
      const spoken = document.createElement('span');
      spoken.className = 'sr-only';
      spoken.textContent = ' waiting';
      chip.append('', spoken);
      title.append(' ', chip);
    }
    if (chip.firstChild.textContent !== String(n)) chip.firstChild.textContent = String(n);
  };
  const plain = el => el.textContent.replace(/\s+/g, ' ').trim();
  // The waiting heading row and its jump link (the entry page) show only while their region holds something. A row
  // that holds the focus stays until the focus has left it.
  const showWith = () => document.querySelectorAll('[data-shown-with]').forEach(el => {
    const region = document.querySelector('[data-refresh="' + el.dataset.shownWith + '"]');
    // data-shown-rows (the decisions' password block): only waiting rows count, not the "nothing waits" text.
    const empty = el.hasAttribute('data-shown-rows') ? !waitingRows(region || undefined).some(row => !row.dataset.late) : !region?.children.length;
    if (!empty || !el.contains(document.activeElement)) el.hidden = empty;
  });
  let due = Date.now() + 30000;
  let running = false;
  // Automatic rounds stop after 15 minutes without a key or pointer press, so an open tab never keeps an idle
  // session alive; a press on "Refresh requests" still works.
  let used = Date.now();
  ['keydown', 'pointerdown'].forEach(type => addEventListener(type, () => { used = Date.now(); }, { passive: true, capture: true }));
  const refresh = async manual => {
    // Stopped for want of a press: the status says so instead of standing still without a word.
    if (!manual && !running && !document.hidden && Date.now() - used > 900000) status(delayed('Automatic check paused'));
    if (running || (!manual && (document.hidden || Date.now() < due || Date.now() - used > 900000))) return;
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
        const stale = mergeRows(region, next);
        if (stale !== null) { if (stale) skipped = true; tableRoles(region); return; }
        // In use outside a request row (the nav, a board's frame): left alone; that only counts when it differs.
        if (inUse(region)) { if (plain(region) !== plain(next)) skipped = true; return; }
        region.replaceChildren(...next.childNodes);
        tableRoles(region);
      });
      tick();
      heading();
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
      if (skipped && manual) parts.push('The part you are working in was left as it is');
      const text = parts.length ? parts.join('. ') + '.' : '';
      if (text) say(text); else if (manual && !skipped) say('Requests are up to date.');
      // A press always gets an answer in words; a quiet round only moves the time.
      // The time advances only when everything shown matches the server.
      status((text ? text + ' ' : manual && !skipped ? 'Up to date. ' : '') + (skipped ? delayed() : checked()));
    } catch {
      // No connection or a server error: the next round tries again, and the time of the last good round stays.
      if (manual) { status('No answer from the server: try again. ' + delayed()); say('Could not check for requests.'); }
      else status(delayed());
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
} else {
  // Every other page of an owner: the header's Agents count stays current without a reload. The nav's
  // data-waiting-url answers {"waiting": N}; it is asked once a minute while the page is visible (a hidden tab pauses
  // and catches up when it is looked at again), and only the count chip changes, never while the focus is in the
  // nav. A count that grew is said in the live region. After 15 minutes without a key or pointer press the asking
  // stops, so an unattended page does not keep the session alive.
  const nav = document.querySelector('nav.nav[data-waiting-url]');
  const agents = nav?.querySelector('a[href$="/requests"]');
  if (agents) {
    const words = n => n === 1 ? '1 request waiting' : n + ' requests waiting';
    const shown = () => Number(agents.querySelector('.chip')?.firstChild?.textContent) || 0;
    const show = n => {
      const chip = agents.querySelector('.chip');
      if (!n) { if (chip) { chip.previousSibling?.nodeType === 3 && chip.previousSibling.remove(); chip.remove(); } return; }
      const next = document.createElement('span');
      next.className = 'chip warn';
      next.title = 'Requests waiting for you';
      const spoken = document.createElement('span');
      spoken.className = 'sr-only';
      spoken.textContent = n === 1 ? ' request waiting' : ' requests waiting';
      next.append(String(n), spoken);
      if (chip) chip.replaceWith(next); else agents.append(' ', next);
    };
    let due = Date.now() + 60000;
    let used = Date.now();
    let running = false;
    let stopped = false;
    ['keydown', 'pointerdown'].forEach(type => addEventListener(type, () => { used = Date.now(); }, { passive: true, capture: true }));
    const ask = async () => {
      if (stopped || running || document.hidden || Date.now() < due || Date.now() - used > 900000) return;
      running = true;
      due = Date.now() + 60000;
      try {
        const response = await fetch(nav.dataset.waitingUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        // Signed out, or no longer an owner here: the next page load says so; the count is left as it is.
        if (response.redirected || [401, 403, 404, 419].includes(response.status)) { stopped = true; return; }
        if (!response.ok) return;
        const n = Number((await response.json()).waiting);
        if (!Number.isInteger(n) || n < 0 || nav.contains(document.activeElement)) { if (nav.contains(document.activeElement)) due = Date.now() + 5000; return; }
        const before = shown();
        if (n === before) return;
        show(n);
        if (n > before) say(words(n) + ' for you. Open Agents to decide.');
      } catch {
        // No connection: the next round tries again.
      } finally { running = false; }
    };
    setInterval(ask, 5000);
    document.addEventListener('visibilitychange', ask);
  }
}
// Bars that are drawers on phones (details[data-wide-open]: the filter bars, the entry page's jump links). On wide
// screens a bar is always open and never a toggle: its summary is out of the tab order and a press on it does
// nothing (the filter bars do not show it at all). On phones it is an ordinary drawer, closed until it is opened;
// a resize to wide and back keeps what the person chose there.
const wide = matchMedia('(min-width: 901px)');
const bars = document.querySelectorAll('details[data-wide-open]');
const syncBar = bar => {
  const label = bar.querySelector('summary');
  if (wide.matches) { bar.open = true; label?.setAttribute('tabindex', '-1'); } else { bar.open = bar.dataset.phone === 'open'; label?.removeAttribute('tabindex'); }
};
bars.forEach(bar => {
  if (!wide.matches && bar.open) bar.dataset.phone = 'open';
  syncBar(bar);
  bar.querySelector('summary')?.addEventListener('click', event => { if (wide.matches) event.preventDefault(); });
  bar.addEventListener('toggle', () => { if (!wide.matches) bar.dataset.phone = bar.open ? 'open' : 'closed'; });
});
wide.addEventListener('change', () => bars.forEach(syncBar));
// Header focus order. On wide screens the header is one row: mark, workspace switcher, nav, account menu, which
// is the order of the source. On phones (the 900 px layout) the account menu shares the first row with the
// switcher and the nav is the second row, so the account menu is moved before the nav in the document there: Tab
// and a screen reader follow what is seen at both widths. An open menu stays open and the focus stays where it was.
const topBar = document.querySelector('header.top');
const accountMenu = topBar?.querySelector(':scope > details.menu.account');
const mainNav = topBar?.querySelector(':scope > nav.nav');
if (accountMenu && mainNav) {
  const placeAccount = () => {
    const first = !wide.matches;
    if (first === (accountMenu.nextElementSibling === mainNav)) return;
    const focused = accountMenu.contains(document.activeElement) ? document.activeElement : null;
    const open = accountMenu.open;
    if (first) mainNav.before(accountMenu); else topBar.append(accountMenu);
    accountMenu.open = open;
    focused?.focus({ preventScroll: true });
  };
  placeAccount();
  wide.addEventListener('change', placeAccount);
}
// Header menus are <details>: only one stays open, and a click outside or Escape closes it.
const menus = document.querySelectorAll('details.menu');
menus.forEach(menu => menu.addEventListener('toggle', () => {
  if (menu.open) menus.forEach(other => { if (other !== menu) other.open = false; });
}));
// composedPath, not contains: a press on something inside the menu that the press itself removed (Cancel of a
// two-step question on the token page) is still a press inside, so the menu stays open and the focus stays on its item.
addEventListener('click', event => menus.forEach(menu => { if (menu.open && !event.composedPath().includes(menu)) menu.open = false; }));
addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  menus.forEach(menu => {
    if (!menu.open) return;
    menu.open = false;
    if (menu.contains(document.activeElement)) menu.querySelector('summary').focus();
  });
});
// A link to something collapsed opens it: the target itself when it is a <details> (#environment-ids on Import),
// and every <details> around it (#type-KEY, a field inside an editor).
const openTarget = () => {
  let target = null;
  try { target = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null; } catch { return; }
  let opened = false;
  for (let el = target; el; el = el.parentElement) if (el.tagName === 'DETAILS' && !el.classList.contains('menu')) { el.open = true; opened = true; }
  if (opened) target.scrollIntoView();
};
addEventListener('hashchange', openTarget);
// A second press on the same link changes no hash: a target that was closed by hand in between is opened again.
document.addEventListener('click', event => { const link = event.target.closest?.('a[href^="#"]'); if (link && link.getAttribute('href') === location.hash) openTarget(); });
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
  // A form that names itself (hidden "form": Members' add form and each row) is picked by that name alone: its other
  // hidden values may differ from what was sent.
  const named = 'form' in values ? posted.filter(form => form.querySelector('input[type=hidden][name="form"]')?.value === values.form) : [];
  const matching = named.length ? named : posted.filter(hiddenMatch);
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
  // A password that was not right (confirm_password). A form that names a shared block (data-confirm-from: a
  // decision, Reveal, a Members row) has its password field there, or in the block that one defers to while that
  // is on screen; the message belongs to that field.
  const sharedBlock = candidate => {
    let state = candidate?.dataset.confirmFrom ? confirmBoxes.get(candidate.dataset.confirmFrom) : null;
    const first = state?.primary && confirmBoxes.get(state.primary);
    if (first && !first.box.hidden && state.deferred()) state = first;
    return state && !state.input.disabled && !state.input.closest('[hidden]') ? state : null;
  };
  const fieldOf = (candidate, key) => findIn(candidate, key) || (key === 'confirm_password' ? sharedBlock(candidate)?.input || null : null);
  // The failing form is the sent form holding the most of the named fields; the first one wins a tie. Among forms
  // that differ only in a hidden version (Reveal), the one with the version that was sent comes first.
  const exact = candidate => (candidate.elements.version?.value ?? null) === (values.version ?? null);
  let form = null;
  let best = 0;
  [...sent.filter(exact), ...sent.filter(candidate => !exact(candidate))].forEach(candidate => {
    const score = new Set(items.map(item => item.dataset.field).filter(key => fieldOf(candidate, key))).size;
    if (score > best) { best = score; form = candidate; }
  });
  const wrongPassword = form && items.some(item => item.dataset.field === 'confirm_password') && !findIn(form, 'confirm_password') ? sharedBlock(form) : null;
  // No named field found (a message about the form as a whole): the one form left, or the one the hidden values picked.
  form ??= sent.length === 1 || (matching.length && matching.length < posted.length) ? sent[0] : null;
  if (form) {
    // A form inside a collapsed editor is opened, so the summary and the field can be seen.
    for (let el = form.parentElement; el; el = el.parentElement) if (el.tagName === 'DETAILS' && !el.classList.contains('menu')) el.open = true;
    const table = form.closest('table');
    // The password of a shared block was not right: the summary stands right above that block.
    if (wrongPassword) wrongPassword.box.before(summary);
    // Members: the summary stays in the row, right before the form that failed.
    else if (table?.matches('.members')) form.before(summary);
    else if (table) (table.closest('.scroll') || table).before(summary);
    else if (form.matches('form.action') || form.parentElement.matches('.inner')) {
      // Inside the card, under its heading.
      const heading = form.querySelector(':scope > h3');
      if (heading) heading.after(summary); else form.prepend(summary);
    // A form among the buttons of a form foot (Remove project): the summary stands above the foot, never inside it.
    } else (form.closest('.form-foot') || form.closest('.card-head, .fold-foot, .controls') || form).before(summary);
  }
  const seen = new Set();
  items.forEach((item, index) => {
    const field = (form && fieldOf(form, item.dataset.field)) || (form ? null : findIn(document, item.dataset.field));
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
      // Never inside the <label>: the field keeps the name it had before the submit, and the message is its
      // description. It follows the label, or the row the label shares with a button (.with-button, .inline).
      const holder = field.closest('label, .field, td');
      if (holder?.matches('label')) {
        let anchor = holder;
        while (anchor.parentElement?.matches('.inline:not(form), .with-button, .toolbar:not(form), .controls:not(form)')) anchor = anchor.parentElement;
        if (anchor.parentElement?.matches('form.inline, form.toolbar, form.controls')) anchor.parentElement.after(note); else anchor.after(note);
      } else if (holder) holder.append(note); else field.after(note);
      field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), note.id].filter(Boolean).join(' '));
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
  if (wrongPassword) {
    // The password is typed again right away: the focus goes to the field (the message is its description), and
    // the action that failed is held again under its name, so the new password and Enter send it. Not when the
    // form needs something else that was not refilled (a site MFA code): then the row is filled in first.
    const buttons = submitters(form);
    const button = buttons.find(candidate => candidate.name && values[candidate.name] === candidate.value) || (buttons.length === 1 ? buttons[0] : null);
    const message = items.find(item => item.dataset.field === 'confirm_password')?.textContent.trim().replace(/\.$/, '') || 'Current password is not right';
    // data-unfilled: something was typed into a secret field of the form (an initial password) and came back
    // empty. The action is not held again, or the new password and Enter would send the form without it: the
    // focus goes to that secret field, which is filled in first.
    const unfilled = summary.hasAttribute('data-unfilled');
    const cleared = unfilled ? [...form.elements].find(el => el.matches('input[type=password]') && el.name !== 'confirm_password' && usable(el) && el.value === '') : null;
    if (!unfilled && button && !button.disabled && form.checkValidity()) wrongPassword.hold(form, button, message + '. Enter your current password again');
    else if (cleared) {
      say(message + '. Secret fields are not refilled: enter ' + fieldName(cleared) + ' again, then your current password.');
      cleared.scrollIntoView({ block: 'center' });
      cleared.focus({ preventScroll: true });
    } else { say(message + '.'); wrongPassword.input.focus({ preventScroll: true }); }
    if (!cleared) summary.scrollIntoView({ block: 'center' });
  } else {
    summary.focus({ preventScroll: true });
    summary.scrollIntoView({ block: 'center' });
  }
}
