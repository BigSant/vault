const hideSecrets = (selector = '[data-secret]') => document.querySelectorAll(selector).forEach(el => { el.textContent = 'Hidden. Reveal again to see it.'; el.dataset.hidden = '1'; });
// MFA enrollment keys stay visible until the form is submitted; other secrets auto-hide.
if (document.querySelector('[data-secret]:not([data-keep])')) setTimeout(() => hideSecrets('[data-secret]:not([data-keep])'), 30000);
// The enrollment QR encodes the same key, so it goes too.
addEventListener('pagehide', () => { hideSecrets(); document.querySelectorAll('.totp-qr').forEach(img => img.remove()); });
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
  const el = document.getElementById(button.dataset.copy);
  if (!el || el.dataset.hidden) return;
  try { await navigator.clipboard.writeText(el.textContent); button.textContent = 'Copied'; }
  catch { button.textContent = 'Copy blocked'; }
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
  button.textContent = 'Generated';
}));
// Entry type editor: add another empty field row.
document.querySelectorAll('[data-add-row]').forEach(button => button.addEventListener('click', () => {
  const rows = document.getElementById(button.dataset.addRow);
  const template = rows?.querySelector('[data-row-template]');
  if (!template) return;
  const row = template.cloneNode(true);
  row.removeAttribute('data-row-template');
  row.querySelectorAll('input').forEach(input => { if (input.type !== 'hidden') input.value = ''; });
  row.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
  rows.appendChild(row);
  row.querySelector('input:not([type=hidden])')?.focus();
}));
document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
  if (!confirm(form.dataset.confirm)) event.preventDefault();
}));
