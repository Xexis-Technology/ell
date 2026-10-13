// Driver trip controls: loading/success/error states, prevent double taps
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form[data-once]').forEach((f) => {
    f.addEventListener('submit', () => {
      f.querySelectorAll('[type=submit]').forEach((b) => { b.disabled = true; });
    });
  });

  // ---- the two credential cameras live in their own dialogs on the Account page.
  // Opening is deliberately not inline: a driver who taps "Replace my licence"
  // gets the lens and the document they are replacing in one view, and the
  // permission prompt only arrives once they have asked to take something.
  const openCam = (id, opener) => {
    const dlg = document.getElementById(id);
    if (!dlg) return;
    dlg._opener = opener || null;
    if (typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', '');
  };
  document.querySelectorAll('[data-open-cam]').forEach((btn) => {
    btn.addEventListener('click', () => openCam(btn.dataset.openCam, btn));
  });
  document.querySelectorAll('dialog').forEach((dlg) => {
    dlg.querySelectorAll('[data-cam-close]').forEach((b) => {
      b.addEventListener('click', () => {
        if (typeof dlg.close === 'function') dlg.close();
        else dlg.removeAttribute('open');
        // Send focus back to the button that opened it, or the driver is dropped
        // at the top of the page after closing the camera.
        if (dlg._opener && dlg._opener.focus) dlg._opener.focus();
      });
    });
  });
});