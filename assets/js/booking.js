// Booking page pickers: location popup, calendar, time spinner (same UX as homepage widget).
function bookingPage(pref) {
  pref = pref || {};
  return {
    service: pref.service || 'point_to_point',
    trp: pref.trip || 'one_way',
    dir: pref.direction || '',
    hours: pref.hours || 2,
    pickup: pref.pickup || '',
    dest: pref.dest || '',
    pax: pref.pax || 1,
    lug: pref.bags || 0,
    adMG: false, adCS: false, adBS: false,
    csQ: 1, bsQ: 1,
    cp: '',
    sameDrop: true,
    guest: !!pref.guest,
    step: 1,
    maxReached: 1,
    stepErr: '',
    vehOpen: false,
    vehicleId: pref.vid || 0,
    vehicleName: pref.vname || '',
    vehicleMeta: pref.vmeta || '',
    pickVehicle(id, name, meta) {
      this.vehicleId = id;
      this.vehicleName = name;
      this.vehicleMeta = meta;
      this.vehOpen = false;
    },
    saveProgress() {
      try {
        sessionStorage.setItem('ell-booking', JSON.stringify({q: window.location.search, step: this.step, max: this.maxReached}));
      } catch (e) {}
    },
    restoreProgress() {
      try {
        const raw = sessionStorage.getItem('ell-booking');
        if (!raw) return;
        const s = JSON.parse(raw);
        if (s && s.q === window.location.search && s.step >= 1 && s.step <= this.totalSteps()) {
          this.step = Math.min(s.step, this.totalSteps());
          this.maxReached = Math.min(Math.max(s.max || 1, this.maxReached), this.totalSteps());
        }
      } catch (e) {}
    },
    init() {
      const clearStops = () => { if (!(this.service === 'point_to_point' && this.trp === 'one_way')) this.stops = []; };
      this.$watch('service', clearStops);
      this.$watch('trp', clearStops);
      this.$watch('step', () => this.saveProgress());
      // Resume field-by-field: land on the first stage that still needs input.
      const q = new URLSearchParams(window.location.search);
      const svcDone = this.service === 'airport' ? !!this.dir : true;
      const routeDone = !!this.pickup && (this.service === 'hourly' || !!this.dest);
      const schedDone = routeDone && !!this.dateVal && !!this.timeVal && q.has('luggage');
      const hasAny = this.pickup || this.dest || this.dateVal || q.has('luggage') || !svcDone;
      let s = 1;
      if (hasAny) {
        s = !svcDone ? 1 : (!routeDone ? 2 : (!schedDone ? 3 : 4));
      }
      this.step = s;
      this.maxReached = s;
      // A refresh on the same URL restores the saved step (takes precedence).
      this.restoreProgress();
    },
    stepLabels() {
      const base = ['Service', 'Route', 'Schedule', 'Vehicle'];
      if (this.guest) base.push('Contact');
      return base;
    },
    totalSteps() { return this.stepLabels().length; },
    go(n) {
      if (n >= 1 && n <= this.maxReached) { this.step = n; this.stepErr = ''; window.scrollTo({top: 0, behavior: 'smooth'}); }
    },
    back() { if (this.step > 1) { this.step--; this.stepErr = ''; window.scrollTo({top: 0, behavior: 'smooth'}); } },
    validStep(n) {
      const root = document.querySelector('[data-stage="' + n + '"]');
      if (!root) return null;
      if (n === 1 && this.service === 'airport' && !this.dir) {
        return document.getElementById('dirBox') || root;
      }
      const fields = root.querySelectorAll('input[required], select[required], textarea[required]');
      for (const el of fields) {
        if (el.type === 'hidden') continue;
        if (el.offsetParent === null) continue;
        const v = (el.value || '').trim();
        if (!v) return el;
        if (el.type === 'email' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) return el;
      }
      if (n === 3 && (!this.dateVal || !this.timeVal)) {
        return document.getElementById('pickup_date') || document.getElementById('pickup_time');
      }
      return null;
    },
    next() {
      const bad = this.validStep(this.step);
      if (bad) {
        this.stepErr = 'Please complete the highlighted step before continuing.';
        if (bad.focus) bad.focus();
        return;
      }
      this.stepErr = '';
      this.maxReached = Math.max(this.maxReached, this.step + 1);
      this.step = Math.min(this.step + 1, this.totalSteps());
      window.scrollTo({top: 0, behavior: 'smooth'});
    },
    stops: Array.isArray(pref.stops) ? pref.stops.slice(0, 6) : [],
    dateVal: pref.date || '',
    timeVal: pref.time || '',
    tmpH: 12, tmpM: 0, tmpAP: 'PM',
    dateOpen: false, timeOpen: false, calY: null, calM: null,
    dateTimeErr: false,
    locOpen: false, locFor: 'pickup', stopIdx: null,
    locQuery: '', locTypes: [],
    /*
     * Locations come from the database, handed over by the page as JSON in
     * #locData. They used to be this literal list, duplicated in index.php, and
     * neither copy could be edited from the admin. The fallback below only runs
     * if the payload is missing, so the picker is never empty.
     *
     * Its `t` must be a real kind - the chips are built from these values, so
     * a kind that matches nothing would leave this row invisible the moment any
     * chip was switched on.
     */
    locs: (function () {
      var el = document.getElementById('locData');
      if (el) { try { var d = JSON.parse(el.textContent); if (Array.isArray(d) && d.length) return d; } catch (e) {} }
      return [{v: 'Times Square', a: 'Manhattan', t: 'place'}];
    })(),
    // ---- locations ----
    routeErr: '',
    openLoc(which, i) {
      this.locFor = which; this.stopIdx = (i === undefined ? null : i);
        this.locQuery = ''; this.locTypes = []; this.routeErr = ''; this.locOpen = true;
      this.$nextTick(() => { if (this.$refs.locSearch) this.$refs.locSearch.focus(); });
    },
    setLocValue(v) {
      if (this.locFor === 'stop' && this.stopIdx !== null && this.stops[this.stopIdx] !== undefined) {
        this.stops.splice(this.stopIdx, 1, v);
      } else if (this.$refs[this.locFor]) {
        this.$refs[this.locFor].value = v;
        this.$refs[this.locFor].dispatchEvent(new Event('change'));
      }
    },
    sameAsOther(v) {
      if (this.locFor !== 'pickup' && this.locFor !== 'destination') return false;
      const other = this.locFor === 'pickup' ? this.$refs.destination : this.$refs.pickup;
      const norm = s => (s || '').trim().toLowerCase();
      const nv = norm(v);
      return nv !== '' && other && norm(other.value) === nv;
    },
    useSearchAsCustom() {
      const v = (this.locQuery || '').trim();
      if (!v) return;
      if (this.sameAsOther(v)) {
        this.routeErr = 'Pickup and destination cannot be the same location.';
        return;
      }
      this.routeErr = '';
      this.setLocValue(v); this.locOpen = false;
    },
    /*
      The type chips, built from the data rather than hardcoded in the markup.

      The picker used to offer exactly two - Road and Airport - while the office
      manages five kinds in Settings, so rail, bus, cruise and place had no
      filter at all. These are derived from `locs`, so a category that has
      locations gets a chip with a count and one that has none simply does not
      appear. Order is the order riders think in: airports first, then the
      terminals, then plain landmarks.
    */
    locKinds() {
      const order = ['airport', 'rail', 'bus', 'cruise', 'place'];
      const labels = { airport: 'Airports', rail: 'Rail', bus: 'Bus', cruise: 'Cruise', place: 'Places' };
      const counts = {};
      (this.locs || []).forEach(l => { counts[l.t] = (counts[l.t] || 0) + 1; });
      return order
        .filter(k => counts[k])
        .map(k => ({ v: k, l: labels[k] || k, n: counts[k] }));
    },
    toggleLocType(k) {
      const i = (this.locTypes || []).indexOf(k);
      if (i === -1) this.locTypes = [...(this.locTypes || []), k];
      else this.locTypes = this.locTypes.filter(t => t !== k);
    },
    locTypeOn(k) { return (this.locTypes || []).includes(k); },
    filteredLocs() {
      const q = (this.locQuery || '').toLowerCase().trim();
      const types = this.locTypes || [];
      /*
        A typed search always wins over the type chips.

        The chips are a way to narrow what you are *browsing*. They are not a
        hard gate: with a chip on, searching "Kennedy" used to return nothing at
        all - even though JFK is the first row of the list - and the rider was
        pushed to type the airport as a free-text address instead of picking
        the saved location. A filter that hides a literal match for what
        someone just typed is always wrong.
      */
      return this.locs.filter(l => {
        if (q) return (l.v + ' ' + l.a).toLowerCase().includes(q);
        if (!types.length) return true;
        return types.includes(l.t);
      });
    },
    pickLoc(v) {
      if (this.sameAsOther(v)) {
        this.routeErr = 'Pickup and destination cannot be the same location.';
        return;
      }
      this.routeErr = '';
      this.setLocValue(v); this.locOpen = false;
    },
    addStop() { if (this.stops.length < 6) this.stops.push(''); },
    rmStop(i) { this.stops.splice(i, 1); },
    // ---- date ----
    todayStr() {
      const t = new Date();
      return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
    },
    openDate() {
      const base = this.dateVal || this.todayStr();
      const p = base.split('-');
      this.calY = +p[0]; this.calM = +p[1] - 1;
      this.dateTimeErr = false; this.dateOpen = true;
    },
    calTitle() { return new Date(this.calY, this.calM, 1).toLocaleDateString('en-US', {month: 'long', year: 'numeric'}); },
    calGrid() {
      const first = new Date(this.calY, this.calM, 1).getDay();
      const days = new Date(this.calY, this.calM + 1, 0).getDate();
      const cells = [];
      for (let i = 0; i < first; i++) cells.push(null);
      for (let d = 1; d <= days; d++) cells.push(d);
      return cells;
    },
    dayStr(d) { return this.calY + '-' + String(this.calM + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0'); },
    dayDisabled(d) { return this.dayStr(d) < this.todayStr(); },
    isPickedDay(d) { return this.dayStr(d) === this.dateVal; },
    isToday(d) { return this.dayStr(d) === this.todayStr(); },
    pickDay(d) {
      if (this.dayDisabled(d)) return;
      this.dateVal = this.dayStr(d); this.dateTimeErr = false; this.dateOpen = false;
    },
    stepMonth(n) {
      const dt = new Date(this.calY, this.calM + n, 1);
      const now = new Date();
      if (new Date(dt.getFullYear(), dt.getMonth(), 1) < new Date(now.getFullYear(), now.getMonth(), 1)) return;
      this.calY = dt.getFullYear(); this.calM = dt.getMonth();
    },
    fmtDate() {
      if (!this.dateVal) return '';
      const p = this.dateVal.split('-');
      return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString('en-US', {weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'});
    },
    // ---- time ----
    openTime() {
      this.dateTimeErr = false;
      if (this.timeVal) {
        const p = this.timeVal.split(':');
        const h = +p[0];
        this.tmpAP = h < 12 ? 'AM' : 'PM';
        this.tmpH = h % 12 === 0 ? 12 : h % 12;
        this.tmpM = +p[1];
      } else {
        const now = new Date();
        this.tmpAP = now.getHours() < 12 ? 'AM' : 'PM';
        this.tmpH = now.getHours() % 12 === 0 ? 12 : now.getHours() % 12;
        this.tmpM = 0;
      }
      this.timeOpen = true;
    },
    spinH(d) { let h = this.tmpH + d; if (h > 12) h = 1; if (h < 1) h = 12; this.tmpH = h; },
    spinM(d) { let m = this.tmpM + d * 5; if (m > 55) m = 0; if (m < 0) m = 55; this.tmpM = m; },
    confirmTime() {
      const h24 = this.tmpAP === 'AM' ? this.tmpH % 12 : (this.tmpH % 12) + 12;
      this.timeVal = String(h24).padStart(2, '0') + ':' + String(this.tmpM).padStart(2, '0');
      this.dateTimeErr = false; this.timeOpen = false;
    },
    fmtTime() {
      if (!this.timeVal) return '';
      const p = this.timeVal.split(':');
      const h = +p[0];
      const ap = h < 12 ? 'AM' : 'PM';
      const h12 = h % 12 === 0 ? 12 : h % 12;
      return h12 + ':' + p[1] + ' ' + ap;
    },
    // ---- submit ----
    heroSubmit(e) {
      if (this.service === 'hourly' && this.sameDrop && this.$refs.pickup && this.$refs.destination) {
        this.$refs.destination.value = this.$refs.pickup.value;
        this.dest = this.pickup;
      }
      if (this.service !== 'hourly' || !this.sameDrop) {
        const p = (this.pickup || '').trim().toLowerCase();
        const d = (this.dest || '').trim().toLowerCase();
        if (p && d && p === d) {
          this.step = 2;
          this.maxReached = Math.max(this.maxReached, 2);
          this.stepErr = 'Pickup and destination cannot be the same location.';
          e.preventDefault();
          return;
        }
      }
      for (let n = 1; n <= this.totalSteps(); n++) {
        const bad = this.validStep(n);
        if (bad) {
          this.step = n;
          this.maxReached = Math.max(this.maxReached, n);
          this.stepErr = 'Please complete the highlighted step before continuing.';
          if (bad.focus) bad.focus();
          e.preventDefault();
          return;
        }
      }
      if (!this.dateVal || !this.timeVal) { this.dateTimeErr = true; e.preventDefault(); }
    }
  };
}
