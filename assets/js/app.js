// Exotic Lane Limo - public helpers (Axios where useful)
document.addEventListener('DOMContentLoaded', () => {
  // Disable duplicate submission on financial forms
  document.querySelectorAll('form[data-once]').forEach((f) => {
    f.addEventListener('submit', () => {
      const btn = f.querySelector('[type=submit]');
      if (btn) { btn.disabled = true; btn.dataset.orig = btn.textContent; btn.textContent = 'Processing…'; }
    });
  });
});
async function ellPost(url, data) {
  const token = document.querySelector('input[name=_csrf]')?.value || '';
  const res = await axios.post(url, data, { headers: { 'X-CSRF-TOKEN': token } });
  return res.data;
}
