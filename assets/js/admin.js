// Admin helpers: confirmations, disclosure panels, modal dialogs, rich text
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (ev) => {
      if (!confirm(el.dataset.confirm || 'Are you sure?')) ev.preventDefault();
    });
  });

  // Any element with [data-fl-toggle="panel-id"] shows or hides that panel.
  // Only one panel per card stays open, so the roster does not grow endless.
  const openPanels = new Map();
  document.querySelectorAll('[data-fl-toggle]').forEach((trigger) => {
    trigger.addEventListener('click', (ev) => {
      ev.preventDefault();
      const panel = document.getElementById(trigger.dataset.flToggle);
      if (!panel) return;
      const card = trigger.closest('.fl-card');
      const open = panel.hasAttribute('hidden');
      if (card) {
        if (!openPanels.has(card)) openPanels.set(card, []);
        const list = openPanels.get(card);
        list.forEach((other) => {
          if (other === panel || !other.isConnected) return;
          other.setAttribute('hidden', '');
          const t = card.querySelector('[data-fl-toggle="' + other.id + '"]');
          if (t) t.setAttribute('aria-expanded', 'false');
        });
        if (open && !list.includes(panel)) list.push(panel);
        if (!open) list.splice(list.indexOf(panel), 1);
      }
      panel.toggleAttribute('hidden', !open);
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  // ---- modal dialogs ------------------------------------------------------
  // <dialog>.showModal() gives focus trapping, Escape and the backdrop for free.
  const openModal = (dlg) => {
    if (!dlg) return;
    if (typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', '');
    // showModal() focuses the first focusable element, which is the close
    // button. Land on the first real field instead, or a space pressed while
    // typing activates that button and closes the dialog.
    const first = dlg.querySelector('input:not([type=hidden]):not([type=file]),select,textarea,.ql-editor');
    if (first) {
      if (first.focus) first.focus();
      if (first.select) first.select();
    }
  };
  const closeModal = (dlg) => {
    if (!dlg) return;
    if (typeof dlg.close === 'function') dlg.close();
    else dlg.removeAttribute('open');
  };
  window.ellOpenModal = openModal;
  window.ellCloseModal = closeModal;

  document.querySelectorAll('[data-open-modal]').forEach((btn) => {
    btn.addEventListener('click', (ev) => {
      ev.preventDefault();
      openModal(document.getElementById(btn.dataset.openModal));
    });
  });
  document.querySelectorAll('dialog.modal').forEach((dlg) => {
    dlg.querySelectorAll('[data-close-modal]').forEach((b) =>
      b.addEventListener('click', (ev) => {
        ev.preventDefault();
        closeModal(dlg);
      })
    );
    // Clicking the backdrop (the dialog element itself) closes it.
    dlg.addEventListener('click', (ev) => { if (ev.target === dlg) closeModal(dlg); });
  });

  // ---- Quill rich text ----------------------------------------------------
  // One editor per .ql-host textarea. A hidden <input> carries the HTML so the
  // value posts with the form even if Quill never initialises.
  //
  // Quill now comes from a CDN, so it is no longer guaranteed to have executed
  // by DOMContentLoaded (both scripts are `defer`, and script order alone is not
  // a contract). Wait for it briefly rather than bailing out and silently
  // leaving plain textareas.
  const initRichText = () => {
    document.querySelectorAll('.ql-host').forEach((host) => {
      if (host.dataset.qlReady) return;
      host.dataset.qlReady = '1';
      const ta = host;
      // Read the stored HTML first: Quill consumes and empties the element it
      // initialises on, so reading ta.value afterwards yields '' and the admin
      // sees a blank editor for a description that is on file.
      const stored = ta.value || '';

      const mirror = document.createElement('input');
      mirror.type = 'hidden';
      mirror.name = ta.name;
      mirror.value = stored;
      // Keep the textarea out of the submission under its own name, but leave
      // it in the DOM: it is the only fallback if Quill fails to load.
      ta.name = 'ql_src_' + ta.name;

      // Quill gets its own element rather than the textarea. Initialising Quill
      // ON the textarea makes the textarea itself the .ql-container, so hiding
      // it hides the editing surface with it - the toolbar renders and the
      // typing area collapses to zero height.
      const mount = document.createElement('div');
      mount.className = 'ql-mount';
      // Quill clears its container on init, so the mirror goes after it.
      ta.parentNode.insertBefore(mount, ta);
      ta.parentNode.insertBefore(mirror, mount.nextSibling);
      ta.style.display = 'none';

      let q;
      try {
        q = new Quill(mount, {
          theme: 'snow',
          placeholder: ta.getAttribute('placeholder') || 'Write something…',
          modules: { toolbar: [['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], ['link'], ['clean']] },
        });
      } catch (err) {
        mount.remove();
        host.dataset.qlReady = ''; // restore the plain textarea
        ta.style.display = '';
        return;
      }
      if (stored) q.clipboard.dangerouslyPasteHTML(stored);
      q.on('text-change', () => { mirror.value = q.root.innerHTML; });
      // Sync from the editor only when it actually holds something, so an empty
      // editor never replaces a description that is on file.
      if (q.getText().trim()) mirror.value = q.root.innerHTML;
    });
  };

  if (typeof window.Quill !== 'undefined') {
    initRichText();
  } else {
    let waited = 0;
    const poll = setInterval(() => {
      if (typeof window.Quill !== 'undefined') { clearInterval(poll); initRichText(); }
      else if ((waited += 100) > 8000) { clearInterval(poll); initRichText(); }
    }, 100);
  }
});