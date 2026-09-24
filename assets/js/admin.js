// Admin helpers: confirm destructive actions, async status UI
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (ev) => {
      if (!confirm(el.dataset.confirm || 'Are you sure?')) ev.preventDefault();
    });
  });
});
