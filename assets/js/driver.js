// Driver trip controls: loading/success/error states, prevent double taps
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form[data-once]').forEach((f) => {
    f.addEventListener('submit', () => {
      f.querySelectorAll('[type=submit]').forEach((b) => { b.disabled = true; });
    });
  });
});
