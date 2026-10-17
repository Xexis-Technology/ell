/* ==========================================================================
   "Use where I am now" for the pickup field, shared by every page that has the
   location picker.

   This lives in one file on purpose. The homepage runs its own `tripSlip()`
   component and the booking page runs `bookingPage()` from booking.js - two
   separate implementations of the same picker, neither loading the other. Adding
   this behaviour to each of them would have meant writing it twice, and the two
   copies would have drifted. It is defined once here and mixed into whichever
   component is on the page, which is the smallest change that does not make the
   duplication worse.

   Why the coordinates go to our own endpoint rather than to a geocoder from the
   page: the browser gives latitude and longitude and nothing else, and a
   browser cannot send the User-Agent Nominatim asks every caller to identify
   itself with. More to the point, the coordinates are the single most revealing
   thing a visitor could hand to a third party, so they are not handed to one.
   They arrive here, become an address, and are not stored.

   The permission prompt appears only because of the click. That is the consent
   gesture, and nothing asks before it.
   ========================================================================== */
(function () {
  'use strict';

  /* The token is read from the DOM rather than passed in: every page already
     renders csrf_field() on its forms, the value is per-session, and plumbing a
     second copy through two different component constructors would be a way to
     end up posting the wrong one. */
  function csrfToken() {
    var el = document.querySelector('input[name="_csrf"]');
    return el ? el.value : '';
  }

  window.__locateMixin = {
    locBusy: false,
    locError: '',

    /* url comes from the button's own data-locate-url, so the markup states
       where it posts rather than the script guessing at a path that may or may
       not sit in a subdirectory. */
    useMyLocation: function (url) {
      var self = this;
      if (this.locBusy || !url) return;
      this.locError = '';

      if (!navigator.geolocation) {
        this.locError = 'This browser cannot work out where you are.';
        return;
      }
      this.locBusy = true;

      navigator.geolocation.getCurrentPosition(
        function (pos) {
          self.postCoords(url, pos.coords.latitude, pos.coords.longitude);
        },
        function (err) {
          self.locBusy = false;
          /* Refusal is by far the commonest outcome and it is the visitor's
             decision rather than a fault to report, so it says what to do next
             instead of echoing a raw browser message like "Error 1". */
          self.locError = (err && err.code === 1)
            ? 'Location is blocked for this site. Allow it in your browser settings, or type the address.'
            : 'Could not work out where you are. Type the address instead.';
        },
        /* Coarse accuracy on purpose: a street is enough to fill a pickup field,
           and a precise fix costs battery and a second. maximumAge means someone
           standing still does not pay for a fresh fix on every tap. */
        { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
      );
    },

    postCoords: function (url, lat, lng) {
      var self = this;
      var body = new URLSearchParams();
      body.set('_csrf', csrfToken());
      body.set('lat', String(lat));
      body.set('lng', String(lng));

      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (data) {
          self.locBusy = false;
          if (!data || !data.ok) {
            self.locError = (data && data.error) || 'The address lookup failed.';
            return;
          }
          /* Reuses the setter the result rows use, so the address lands in the
             field, fires change for anything bound with x-model, and the picker
             closes exactly as tapping a result would. */
          self.setLocValue(data.address);
          self.locOpen = false;
        })
        .catch(function () {
          self.locBusy = false;
          self.locError = 'Could not reach us to look up that address.';
        });
    }
  };
})();