const hideSecrets = () => document.querySelectorAll('[data-secret]').forEach(el => { el.textContent = 'Paslėpta. Atskleiskite iš naujo.'; el.dataset.hidden = '1'; });
if (document.querySelector('[data-secret]')) setTimeout(hideSecrets, 30000);
addEventListener('pagehide', hideSecrets);
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
  const el = document.getElementById(button.dataset.copy);
  if (!el || el.dataset.hidden) return;
  try { await navigator.clipboard.writeText(el.textContent); button.textContent = 'Nukopijuota'; }
  catch { button.textContent = 'Kopijavimas neleidžiamas'; }
}));
const type = document.getElementById('entry-type');
const fields = () => document.querySelectorAll('[data-types]').forEach(el => {
  const active = el.dataset.types.split(',').includes(type.value);
  el.hidden = !active;
  el.querySelectorAll('input,textarea').forEach(input => { input.disabled = !active; input.required = active && input.closest('form').dataset.editing !== '1' && !input.hasAttribute('data-optional'); });
});
if (type) { type.addEventListener('change', fields); fields(); }
document.querySelector('[data-generate]')?.addEventListener('click', () => {
  const bytes = crypto.getRandomValues(new Uint8Array(24));
  const password = btoa(String.fromCharCode(...bytes));
  document.querySelector('[data-generated-password]').value = password;
});
