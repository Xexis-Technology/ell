/* ==========================================================================
   alerts.js — .alert as a toast, everywhere.

   Loaded by the public, admin and driver layouts. One copy on purpose: this
   behaviour used to live in three places or, more often, in none, and "the
   message vanished on the driver portal but not on the booking page" is not a
   bug anyone can report usefully.

   Every alert is moved into one fixed stack in the top-right corner:

     - one stack, not one floating box per alert, so several messages queue
       instead of landing on top of each other;
     - moved into a container on <body>, so a transformed ancestor (Tailwind
       CDN puts those around all over this site) cannot turn position:fixed
       into position:absolute and drop the toast into the middle of the page;
     - the stack is pointer-events:none and only the toasts themselves are
       pointer-events:auto, so an empty stack never eats a click aimed at
       whatever lives in that corner.

   Safe to move: no .alert in this codebase carries an Alpine directive and
   none sits inside a <form>. Both were checked before writing this.

   Two rules govern dismissal, and the second is the important one:

     1. Every toast gets a close button. Always. That is never wrong.
     2. A toast removes itself after 5s ONLY when it is safe to do so.

   "Safe" excludes three cases, each because getting it wrong costs the user
   something they cannot get back:

     - Errors. A confirmation read twice costs nothing. An error that vanishes
       before you finish reading it makes you repeat the action.
     - Toasts holding a link, button or field. auth/reset-password.php says
       "Password updated" and puts the only link out of the page inside it.
     - Anything marked data-alert-sticky, for a case we have not hit yet.

   The countdown pauses while the pointer or keyboard focus is inside the toast,
   so a message cannot be yanked away mid-read.
   ========================================================================== */
(function () {
  'use strict';

  var AFTER = 5000;                                   // ms
  var FADE = 220;                                    // keep in step with .is-going
  var STICKY_SEL = '.alert-err,[data-alert-sticky]';
  var HOLDS_UI = 'a,button,input,select,textarea,form,details[open]';

  var reduceMotion = window.matchMedia
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false;

  var stack = null;

  function getStack() {
    if (stack && stack.isConnected) return stack;
    stack = document.querySelector('.toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'toast-stack';
      /* polite, not assertive: a toast arriving while you are typing should
         wait its turn rather than interrupting. Errors announce themselves by
         being important enough to still be on screen five seconds later. */
      stack.setAttribute('aria-live', 'polite');
      stack.setAttribute('aria-atomic', 'false');
      document.body.appendChild(stack);
      syncOffset();
      window.addEventListener('resize', syncOffset, { passive: true });
      window.addEventListener('orientationchange', syncOffset);
    }
    return stack;
  }

  /* Anything marked data-toast-clear sits under a sticky header and wants the
     stack pushed below it. Measured rather than hardcoded: the driver top bar
     is 108px on a phone and 49px on a desktop, and it grows again when a trip
     banner appears in it, so no single number in a stylesheet is correct. */
  function syncOffset() {
    if (!stack) return;
    var clear = document.querySelector('[data-toast-clear]');
    var h = 0;
    if (clear) {
      var r = clear.getBoundingClientRect();
      if (r.height > 0) h = r.height;
    }
    /* A hair over the header so the toast does not sit flush against it. */
    stack.style.setProperty('--toast-top', (h + 12) + 'px');
  }

  function dismiss(el) {
    if (el.dataset.alertGone === '1') return;
    el.dataset.alertGone = '1';
    clearInterval(el._alertTimer);
    el.classList.add('is-going');
    var hide = function () {
      /* hidden, not removed. A display:none flex child drops out of the stack
         and the ones below close the gap, so the stack collapses correctly —
         but the node keeps its place in the DOM for anything holding a
         reference to it. A removed node buys nothing here. */
      el.hidden = true;
      el.classList.remove('is-going');
    };
    if (reduceMotion) hide();
    else setTimeout(hide, FADE);
  }

  function arm(el) {
    if (el.dataset.alertArmed === '1') return;
    el.dataset.alertArmed = '1';

    /* Decide this BEFORE the close button goes in. The button is a <button>,
       so asking "does this toast hold a button?" afterwards always answers yes
       — and then nothing on the site would ever dismiss itself. */
    var sticky = el.matches(STICKY_SEL) || !!el.querySelector(HOLDS_UI);

    var x = document.createElement('button');
    x.type = 'button';                 // never submit a form this sits in
    x.className = 'alert-x';
    x.setAttribute('aria-label', 'Dismiss this message');
    x.title = 'Dismiss';
    /* The glyph is aria-hidden so the live region announces the message and not
       "times". A text x, not an icon font: FontAwesome is not on every layout
       and a missing glyph is worse than a multiplication sign. */
    var g = document.createElement('span');
    g.setAttribute('aria-hidden', 'true');
    g.textContent = '\u00D7';
    x.appendChild(g);
    x.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      dismiss(el);
    });

    getStack().appendChild(el);
    el.classList.add('is-toast');
    el.appendChild(x);

    if (sticky) { el.dataset.alertSticky = '1'; return; }

    /* A deadline, not a countdown. Subtracting elapsed time on every tick
       double-counts it — after ten 100ms ticks the counter had burned through
       5.5s instead of 1s — and a pause would have to be summed back by hand.
       One timestamp that moves only when it means to is the whole thing. */
    var deadline = Date.now() + AFTER;
    var timer = null;

    function run() {
      timer = setInterval(function () {
        if (Date.now() >= deadline) { clearInterval(timer); timer = null; dismiss(el); }
      }, 100);
    }
    function pause() {
      if (timer === null) return;
      clearInterval(timer);
      timer = null;
    }
    function resume() {
      if (timer !== null || el.hidden) return;
      if (Date.now() >= deadline) { dismiss(el); return; }
      run();
    }
    el.addEventListener('mouseenter', pause);
    el.addEventListener('mouseleave', resume);
    el.addEventListener('focusin', pause);
    el.addEventListener('focusout', resume);
    run();
  }

  function scan(node) {
    if (!node || node.nodeType !== 1) return;
    var found = [];
    if (node.matches && node.matches('.alert')) found.push(node);
    if (node.querySelectorAll) {
      var list = node.querySelectorAll('.alert');
      for (var i = 0; i < list.length; i++) found.push(list[i]);
    }
    for (var j = 0; j < found.length; j++) arm(found[j]);
  }

  function start() {
    scan(document.body);

    /* Toasts that arrive after load: Alpine x-show blocks, fetch handlers, the
       settings autosave. Without this the behaviour silently does not apply to
       half the alerts on the site, which is worse than not having it. */
    if (window.MutationObserver) {
      new MutationObserver(function (records) {
        for (var i = 0; i < records.length; i++) {
          var added = records[i].addedNodes;
          for (var j = 0; j < added.length; j++) scan(added[j]);
        }
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();