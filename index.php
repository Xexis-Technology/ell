<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$pdo = Database::pdo();
$vehicles = [];
$minMile = null;
$minHourly = null;
try {
    $vehicles = $pdo->query('SELECT v.*, c.name AS category, pr.per_mile_rate, pr.hourly_rate FROM vehicles v LEFT JOIN vehicle_categories c ON c.id = v.category_id LEFT JOIN pricing_rates pr ON pr.vehicle_id = v.id AND pr.active = 1 WHERE v.status = "active" AND v.is_temporary = 0 ORDER BY v.id LIMIT 6')->fetchAll();
    $rates = $pdo->query('SELECT MIN(per_mile_rate) AS m, MIN(hourly_rate) AS h FROM pricing_rates WHERE active = 1')->fetch();
    $minMile = $rates['m'] !== null ? (float)$rates['m'] : null;
    $minHourly = $rates['h'] !== null ? (float)$rates['h'] : null;
} catch (Throwable) {}
$roster = [];
foreach ($vehicles as $v) {
    $roster[] = e($v['make'] . ' ' . $v['model']) . ' · ' . (int)$v['passenger_capacity'] . ' seats';
}
if (!$roster) $roster = ['Chauffeured sedans', 'Luxury SUVs', 'Sprinters', 'Stretch limousines'];
ob_start();
?>
<!-- STICKY HEADER (appears after scrolling past the hero nav; plain JS so it works even if Alpine fails) -->
<header id="stickyHeader" class="fixed top-0 inset-x-0 z-50 bg-[#0F0F0E]/95 backdrop-blur border-b border-[#262628]" style="display:none">
<script>
(function () {
  var bar = document.getElementById('stickyHeader');
  function onScroll() {
    const y = window.scrollY || document.documentElement.scrollTop;
    bar.style.display = y > 160 ? 'block' : 'none';
  }
  window.addEventListener('scroll', onScroll, {passive: true});
  onScroll();
})();
</script>
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
    <a href="<?= url('index.php') ?>" class="font-display text-xl text-[#F3D4A6]">Exotic Lane Limo</a>
    <nav class="hidden md:flex items-center gap-6 text-sm" aria-label="Sticky">
      <a href="<?= url('services/index.php') ?>" class="hover:text-[#F3D4A6]">Services</a>
      <a href="#fleet" class="hover:text-[#F3D4A6]">Fleet</a>
      <a href="<?= url('services/airport.php') ?>" class="hover:text-[#F3D4A6]">Airport</a>
      <a href="<?= url('services/hourly.php') ?>" class="hover:text-[#F3D4A6]">Hourly</a>
      <a href="<?= url('legal/contact.php') ?>" class="hover:text-[#F3D4A6]">Contact</a>
    </nav>
    <?php if (current_user('customer')): ?>
      <a href="<?= url('account/index.php') ?>" class="btn-gold text-sm rounded-full px-5 py-2">My Account</a>
    <?php else: ?>
      <a href="<?= url('services/booking.php') ?>" class="btn-cta text-sm rounded-full px-5 py-2">Book Now</a>
    <?php endif; ?>
  </div>
</header>

<!-- HERO: headline + booking card / chauffeur photo -->
<section class="bg-[#0A0A0C]">
  <div class="max-w-7xl mx-auto px-4" x-data="{menu:false}">
    <!-- Nav -->
    <header class="flex items-center justify-between py-5">
      <a href="<?= url('index.php') ?>" class="font-display text-2xl md:text-3xl tracking-wide text-[#F9F9F9]">Exotic Lane Limo</a>
      <nav class="hidden md:flex items-center gap-7 text-sm text-[#F5F5F3]" aria-label="Primary">
        <a href="<?= url('services/booking.php') ?>" class="text-[#F3D4A6]">Book a Ride</a>
        <a href="<?= url('services/index.php') ?>" class="hover:text-[#F3D4A6]">Services</a>
        <a href="<?= url('services/airport.php') ?>" class="hover:text-[#F3D4A6]">Airport Transfers</a>
        <a href="<?= url('legal/contact.php') ?>" class="hover:text-[#F3D4A6]">Support</a>
      </nav>
      <div class="flex items-center gap-3">
        <?php if (current_user('customer')): ?>
          <a href="<?= url('account/index.php') ?>" class="hidden md:inline-block text-sm hover:text-[#F3D4A6]">My Account</a>
        <?php else: ?>
          <a href="<?= url('auth/login.php') ?>" class="hidden md:inline-block text-sm hover:text-[#F3D4A6]">Log in</a>
        <?php endif; ?>
        <a href="<?= url('services/booking.php') ?>" class="hidden md:inline-flex items-center gap-1 bg-[#F9F9F9] text-[#0A0A0C] text-sm font-semibold px-5 py-2 rounded-full">Book Now <span aria-hidden="true">↗</span></a>
        <button class="md:hidden text-[#F9F9F9] p-2" @click="menu = !menu" aria-label="Open menu" aria-expanded="false">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="7" x2="21" y2="7"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="17" x2="21" y2="17"/></svg>
        </button>
      </div>
    </header>
    <!-- Mobile menu -->
    <div class="md:hidden" x-show="menu" x-cloak>
      <nav class="bg-[#181819] border border-[#2a2a2b] rounded-2xl p-4 mb-4 space-y-1 text-[#F5F5F3] text-sm font-medium" aria-label="Mobile">
        <a href="<?= url('services/booking.php') ?>" class="block px-3 py-2">Book a Ride</a>
        <a href="<?= url('services/index.php') ?>" class="block px-3 py-2">Services</a>
        <a href="<?= url('services/airport.php') ?>" class="block px-3 py-2">Airport Transfers</a>
        <a href="<?= url('legal/contact.php') ?>" class="block px-3 py-2">Support</a>
        <a href="<?= url('services/booking.php') ?>" class="btn-gold block text-center rounded-full mt-2">Book Now</a>
      </nav>
    </div>

    <!-- Hero grid -->
    <div id="heroBooking" class="grid lg:grid-cols-2 gap-8 xl:gap-14 items-stretch pt-6 md:pt-10 pb-12 md:pb-16" x-data="tripSlip()">
      <div class="flex flex-col justify-center">
        <h1 class="text-4xl md:text-5xl font-semibold text-[#F9F9F9] mt-4">Premium car service. <span class="text-[#F3D4A6] italic font-display">Booked in minutes.</span></h1>
        <p class="mt-3 text-[#E5E5E3] max-w-md text-sm md:text-base">Fixed pricing. Professional chauffeurs.</p>

        <!-- Booking card -->
        <div class="bg-[#181819] border border-[#2a2a2b] rounded-2xl p-4 md:p-6 mt-8">
          <!-- Trip tabs -->
          <div class="flex gap-6 border-b border-[#2a2a2b]" role="tablist" aria-label="Trip type">
            <template x-for="t in [{v:'point_to_point',l:'Point-to-Point'},{v:'airport',l:'Airport'},{v:'hourly',l:'Hourly'}]" :key="t.v">
              <button type="button" role="tab" :aria-selected="tab === t.v" @click="tab = t.v"
                :class="tab === t.v ? 'text-[#F9F9F9]' : 'text-[#AB8868] hover:text-[#F5F5F3]'"
                class="relative pb-3 text-sm font-semibold whitespace-nowrap">
                <span x-text="t.l"></span>
                <span x-show="tab === t.v" class="absolute inset-x-0 -bottom-px h-0.5 bg-[#D9B978]"></span>
              </button>
            </template>
          </div>

          <form action="<?= url('services/booking.php') ?>" method="get" class="mt-5 space-y-3" @submit="heroSubmit($event)">
            <input type="hidden" name="service" :value="tab">
            <input type="hidden" name="trip" :value="trp">
            <!-- Trip type, inside Point-to-Point -->
            <div x-show="tab === 'point_to_point'" class="inline-flex bg-[#0A0A0C] border border-[#2a2a2b] rounded-full p-1 gap-1" role="group" aria-label="Trip type">
              <button type="button" @click="trp = 'one_way'" :class="trp === 'one_way' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'text-[#F5F5F3]'" class="text-xs font-semibold px-4 py-1.5 rounded-full">One Way</button>
              <button type="button" @click="trp = 'round_trip'" :class="trp === 'round_trip' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'text-[#F5F5F3]'" class="text-xs font-semibold px-4 py-1.5 rounded-full">Round Trip</button>
            </div>
            <input type="hidden" name="direction" :value="dir" x-show="tab === 'airport'">
            <input type="hidden" name="hours" :value="hours" x-show="tab === 'hourly'">
            <input type="hidden" name="passengers" :value="pax">

            <p class="font-semibold text-[#F9F9F9] text-sm">Where to?</p>

            <!-- Pickup → Destination with connector line on the left -->
            <div class="relative space-y-3">
              <span x-show="tab !== 'hourly' || !sameDrop" aria-hidden="true" class="absolute left-[21px] top-[26px] bottom-[26px] w-px bg-[#3a3a3d]"></span>
              <!-- Pickup -->
              <div class="relative flex items-center gap-3 bg-[#0A0A0C] border border-[#2a2a2b] rounded-xl px-4">
                <span class="w-2.5 h-2.5 rounded-full bg-[#D9B978] shrink-0" aria-hidden="true"></span>
              <input x-ref="pickup" name="pickup" required readonly @click="openLoc('pickup'); $el.blur()" placeholder="Enter Pickup Location" aria-label="Pickup location"
                class="no-focus-ring w-full bg-transparent py-3.5 text-sm text-white placeholder-[#6b6b6b] focus:outline-none cursor-pointer">
                <button type="button" @click="openLoc('pickup')" aria-label="Browse pickup locations" class="text-[#C8A96B] hover:text-[#F3D4A6] p-1 shrink-0">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                </button>
              </div>

              <!-- Extra stops between pickup and destination (one-way only, max 6) -->
              <div x-show="tab === 'point_to_point' && trp === 'one_way'">
                <template x-for="(s, i) in stops" :key="i">
                  <div class="relative flex items-center gap-3 bg-[#0A0A0C] border border-[#2a2a2b] rounded-xl px-4 mt-3">
                    <span class="w-2 h-2 rounded-full border border-[#C8A96B] shrink-0" aria-hidden="true"></span>
                    <input name="stops[]" x-model="stops[i]" readonly @click="openLoc('stop', i); $el.blur()" :placeholder="'Stop ' + (i + 1) + ' — tap to choose'" :aria-label="'Extra stop ' + (i + 1)"
                      class="no-focus-ring w-full bg-transparent py-3 text-sm text-white placeholder-[#6b6b6b] focus:outline-none cursor-pointer">
                    <button type="button" @click="rmStop(i)" aria-label="Remove stop" class="text-[#AB8868] hover:text-[#f3c1bd] p-1 shrink-0">✕</button>
                  </div>
                </template>
                <button type="button" @click="addStop()" x-show="stops.length < 6"
                  class="w-full mt-3 border border-dashed border-[#3a3a3d] hover:border-[#C8A96B] rounded-xl py-2.5 text-xs text-[#AB8868] hover:text-[#F3D4A6]">
                  + Add stop <span class="tabular" x-show="stops.length > 0" x-text="'(' + stops.length + ' of 6 max)'"></span>
                </button>
              </div>

              <!-- Destination (hourly: hidden while "same as pickup" is checked) -->
              <div x-show="tab !== 'hourly' || !sameDrop" class="relative flex items-center gap-3 bg-[#0A0A0C] border border-[#2a2a2b] rounded-xl px-4">
                <span class="w-2.5 h-2.5 bg-[#AB8868] shrink-0" aria-hidden="true"></span>
              <input x-ref="destination" name="destination" readonly @click="openLoc('destination'); $el.blur()" :required="tab !== 'hourly' || !sameDrop" placeholder="Enter Drop Off Location" aria-label="Drop off location"
                class="no-focus-ring w-full bg-transparent py-3.5 text-sm text-white placeholder-[#6b6b6b] focus:outline-none cursor-pointer">
                <button type="button" @click="openLoc('destination')" aria-label="Browse drop off locations" class="text-[#C8A96B] hover:text-[#F3D4A6] p-1 shrink-0">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                </button>
              </div>
            </div>

            <!-- Airport direction -->
            <div x-show="tab === 'airport'" class="grid grid-cols-2 gap-2">
              <button type="button" @click="dir = 'to_airport'" :class="dir === 'to_airport' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'border border-[#3a3a3d] text-[#F5F5F3]'" class="rounded-xl py-2.5 text-xs font-semibold">Address → Airport</button>
              <button type="button" @click="dir = 'from_airport'" :class="dir === 'from_airport' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'border border-[#3a3a3d] text-[#F5F5F3]'" class="rounded-xl py-2.5 text-xs font-semibold">Airport → Address</button>
            </div>

            <!-- Hourly: drop-off defaults to pickup unless unchecked -->
            <div x-show="tab === 'hourly'">
              <label class="flex items-center gap-2.5 text-sm text-[#F5F5F3] cursor-pointer">
                <input type="checkbox" x-model="sameDrop" class="w-4 h-4 accent-[#D9B978]">
                Drop-off same as pick-up
              </label>
            </div>

            <!-- Hourly hours stepper -->
            <div x-show="tab === 'hourly'" class="flex items-center justify-between bg-[#0A0A0C] border border-[#2a2a2b] rounded-xl px-4 py-2.5">
              <span class="text-sm text-[#F5F5F3]">Hours <span class="text-[11px] text-[#AB8868]">(2 minimum)</span></span>
              <span class="flex items-center gap-3">
                <button type="button" @click="hours = Math.max(2, hours - 0.5)" aria-label="Fewer hours" class="w-8 h-8 rounded-full border border-[#3a3a3d] text-[#F3D4A6]">−</button>
                <span class="tabular text-sm font-semibold w-8 text-center" x-text="hours"></span>
                <button type="button" @click="hours = Math.min(24, hours + 0.5)" aria-label="More hours" class="w-8 h-8 rounded-full border border-[#3a3a3d] text-[#F3D4A6]">+</button>
              </span>
            </div>

            <!-- Date / time / passengers (passengers full-width on mobile) -->
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
              <div class="bg-[#0A0A0C] border border-[#2a2a2b] rounded-xl px-3 py-2 cursor-pointer" @click="openDate()">
                <label class="block text-[10px] tracking-widest text-[#AB8868] whitespace-nowrap" for="w-date">Pick-up date</label>
                <input id="w-date" :value="fmtDate()" readonly placeholder="Select date" aria-label="Pick-up date, tap to choose"
                  class="no-focus-ring w-full bg-transparent text-sm text-white placeholder-[#6b6b6b] focus:outline-none cursor-pointer">
                <input type="hidden" name="date" :value="dateVal">
              </div>
              <div class="bg-[#0A0A0C] border border-[#2a2a2b] rounded-xl px-3 py-2 cursor-pointer" @click="openTime()">
                <label class="block text-[10px] tracking-widest text-[#AB8868] whitespace-nowrap" for="w-time">Pick-up time</label>
                <input id="w-time" :value="fmtTime()" readonly placeholder="Select time" aria-label="Pick-up time, tap to choose"
                  class="no-focus-ring w-full bg-transparent text-sm text-white placeholder-[#6b6b6b] focus:outline-none cursor-pointer">
                <input type="hidden" name="time" :value="timeVal">
              </div>
              <div class="col-span-2 sm:col-span-1 bg-[#0A0A0C] border border-[#2a2a2b] rounded-xl px-3 py-2">
                <label class="block text-[10px] tracking-widest text-[#AB8868] whitespace-nowrap" for="w-pax">Passengers</label>
                <input id="w-pax" name="passengers" type="number" min="1" max="20" value="1" class="w-full bg-transparent text-sm text-white focus:outline-none">
              </div>
            </div>
            <p x-show="dateTimeErr" x-cloak class="text-xs text-[#f3c1bd]">Please choose a pick-up date and time.</p>

            <button type="submit" class="btn-gold rounded-full w-full py-3.5 text-sm font-semibold inline-flex items-center justify-center gap-3">
              Get a Quote
              <span class="w-6 h-6 rounded-full bg-[#0A0A0C] text-[#D9B978] inline-flex items-center justify-center" aria-hidden="true">↗</span>
            </button>
          </form>
          <p class="text-xs text-[#AB8868] text-center mt-3">Booking for your company? <a class="underline text-[#F3D4A6]" href="<?= url('services/group-event.php') ?>">Request a group quote</a></p>
        </div>

        <!-- Location picker popup -->
        <div x-show="locOpen" x-cloak class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" aria-label="Choose a location">
          <div class="absolute inset-0 bg-black/70" @click="locOpen = false"></div>
          <div class="relative w-full sm:max-w-md bg-[#181819] border border-[#2a2a2b] rounded-t-2xl sm:rounded-2xl p-4 md:p-5 max-h-[85vh] flex flex-col" @keydown.escape.window="locOpen = false">
            <div class="flex items-center justify-between">
              <h3 class="font-display text-xl text-[#F3D4A6]">Choose location</h3>
              <button type="button" @click="locOpen = false" aria-label="Close" class="text-[#AB8868] hover:text-white text-xl px-2">✕</button>
            </div>
            <input x-ref="locSearch" x-model="locQuery" @keydown.enter.prevent="useSearchAsCustom()" placeholder="Search or enter any address…" aria-label="Search locations or enter an address" class="input rounded-xl mt-3">
            <div class="flex gap-2 mt-3" role="group" aria-label="Location type">
              <button type="button" @click="locType = locType === 'road' ? '' : 'road'" :class="locType === 'road' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'border border-[#3a3a3d] text-[#F5F5F3]'" class="text-xs font-semibold px-4 py-2 rounded-full">Road</button>
              <button type="button" @click="locType = locType === 'airport' ? '' : 'airport'" :class="locType === 'airport' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'border border-[#3a3a3d] text-[#F5F5F3]'" class="text-xs font-semibold px-4 py-2 rounded-full">Airport</button>
            </div>
            <button type="button" x-show="locQuery.trim() !== ''" @click="useSearchAsCustom()" class="w-full mt-2 border border-dashed border-[#C8A96B] rounded-xl py-2.5 text-xs text-[#F3D4A6]">Use “<span x-text="locQuery.trim()"></span>” as address</button>
            <div class="overflow-y-auto no-scrollbar mt-2 -mx-1 px-1">
              <template x-for="l in filteredLocs()" :key="l.v">
                <button type="button" @click="pickLoc(l.v)" class="w-full text-left px-3 py-2.5 rounded-xl hover:bg-[#212121] flex items-center gap-3">
                  <svg class="shrink-0 text-[#C8A96B]" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                  <span><span class="block text-sm text-[#F5F5F3]" x-text="l.v"></span><span class="block text-[11px] text-[#AB8868]" x-text="l.a"></span></span>
                </button>
              </template>
              <p x-show="filteredLocs().length === 0" class="text-sm text-[#AB8868] px-3 py-4">No matches — tap the button above to use your entry.</p>
            </div>
          </div>
        </div>

        <!-- Date picker popup -->
        <div x-show="dateOpen" x-cloak class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" aria-label="Choose a pick-up date">
          <div class="absolute inset-0 bg-black/70" @click="dateOpen = false"></div>
          <div class="relative w-full sm:max-w-sm bg-[#181819] border border-[#2a2a2b] rounded-t-2xl sm:rounded-2xl p-4 md:p-5" @keydown.escape.window="dateOpen = false">
            <div class="flex items-center justify-between">
              <button type="button" @click="stepMonth(-1)" aria-label="Previous month" class="text-[#F3D4A6] text-xl px-2">‹</button>
              <h3 class="font-display text-xl text-[#F3D4A6]" x-text="calTitle()"></h3>
              <button type="button" @click="stepMonth(1)" aria-label="Next month" class="text-[#F3D4A6] text-xl px-2">›</button>
            </div>
            <div class="grid grid-cols-7 gap-1 mt-3 text-center text-[10px] tracking-widest text-[#AB8868]">
              <span>SU</span><span>MO</span><span>TU</span><span>WE</span><span>TH</span><span>FR</span><span>SA</span>
            </div>
            <div class="grid grid-cols-7 gap-1 mt-1">
              <template x-for="(c, i) in calGrid()" :key="i">
                <button type="button" x-show="c !== null" @click="pickDay(c)" :disabled="dayDisabled(c)"
                  :class="isPickedDay(c) ? 'bg-[#D9B978] text-[#0A0A0C] font-bold' : (isToday(c) ? 'font-bold text-[#F3D4A6] ring-1 ring-[#D9B978]' : 'text-[#F5F5F3] hover:bg-[#212121]')"
                  class="aspect-square rounded-full text-sm disabled:opacity-20 disabled:pointer-events-none" x-text="c"></button>
              </template>
            </div>
            <button type="button" @click="dateOpen = false" aria-label="Close" class="w-full mt-3 text-xs text-[#AB8868] hover:text-white py-2">Close</button>
          </div>
        </div>

        <!-- Time picker popup -->
        <div x-show="timeOpen" x-cloak class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" aria-label="Choose a pick-up time">
          <div class="absolute inset-0 bg-black/70" @click="timeOpen = false"></div>
          <div class="relative w-full sm:max-w-sm bg-[#181819] border border-[#2a2a2b] rounded-t-2xl sm:rounded-2xl p-4 md:p-5 max-h-[85vh] flex flex-col" @keydown.escape.window="timeOpen = false">
            <div class="flex items-center justify-between">
              <h3 class="font-display text-xl text-[#F3D4A6]">Time</h3>
              <button type="button" @click="timeOpen = false" aria-label="Close" class="text-[#AB8868] hover:text-white text-xl px-2">✕</button>
            </div>
            <div class="grid grid-cols-2 gap-3 mt-2">
              <div class="flex flex-col items-center">
                <button type="button" @click="spinH(1)" aria-label="Hour up" class="w-12 h-12 rounded-xl border border-[#3a3a3d] text-[#F3D4A6] inline-flex items-center justify-center"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg></button>
                <p class="tabular text-4xl font-bold text-[#F9F9F9] my-1" x-text="tmpH"></p>
                <p class="text-[11px] text-[#AB8868]">hour</p>
                <button type="button" @click="spinH(-1)" aria-label="Hour down" class="w-12 h-12 rounded-xl border border-[#3a3a3d] text-[#F3D4A6] mt-1 inline-flex items-center justify-center"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>
              </div>
              <div class="flex flex-col items-center">
                <button type="button" @click="spinM(1)" aria-label="Minute up" class="w-12 h-12 rounded-xl border border-[#3a3a3d] text-[#F3D4A6] inline-flex items-center justify-center"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg></button>
                <p class="tabular text-4xl font-bold text-[#F9F9F9] my-1" x-text="String(tmpM).padStart(2, '0')"></p>
                <p class="text-[11px] text-[#AB8868]">min</p>
                <button type="button" @click="spinM(-1)" aria-label="Minute down" class="w-12 h-12 rounded-xl border border-[#3a3a3d] text-[#F3D4A6] mt-1 inline-flex items-center justify-center"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>
              </div>
            </div>
            <div class="flex justify-center mt-4" role="group" aria-label="AM or PM">
              <button type="button" @click="tmpAP = 'AM'" :class="tmpAP === 'AM' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'text-[#F5F5F3]'" class="text-xs font-semibold px-6 py-2 rounded-l-full border border-[#3a3a3d]">AM</button>
              <button type="button" @click="tmpAP = 'PM'" :class="tmpAP === 'PM' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'text-[#F5F5F3]'" class="text-xs font-semibold px-6 py-2 rounded-r-full border border-l-0 border-[#3a3a3d]">PM</button>
            </div>
            <p class="tabular text-center text-sm text-[#AB8868] mt-3" x-text="tmpH + ' : ' + String(tmpM).padStart(2, '0') + ' ' + tmpAP"></p>
            <button type="button" @click="confirmTime()" class="btn-gold rounded-full w-full py-3 text-sm font-semibold mt-3">OK</button>
            <button type="button" @click="timeOpen = false" class="w-full py-3 text-sm text-[#F5F5F3] border border-[#3a3a3d] rounded-full mt-2">Cancel</button>
          </div>
        </div>
      </div>

      <!-- Photo -->
      <div class="relative min-h-[300px] lg:min-h-full">
        <div class="absolute inset-0 rounded-2xl bg-gradient-to-br from-[#181819] to-[#AB8868]"></div>
        <img src="https://images.unsplash.com/photo-1555215695-3004980ad54e?auto=format&fit=crop&w=1200&q=60" alt="Black luxury sedan at night" class="absolute inset-0 w-full h-full object-cover rounded-2xl" loading="lazy" onerror="this.style.display='none'">
        <div class="absolute inset-0 rounded-2xl bg-gradient-to-t from-[#0A0A0C]/70 via-transparent to-transparent"></div>
        <div class="absolute bottom-4 right-4">
          <a href="#fleet" class="text-[11px] tracking-widest text-[#F3D4A6] underline">SEE FLEET</a>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
function tripSlip() {
  return {
    tab: 'point_to_point',
    trp: 'one_way',
    dir: 'to_airport',
    hours: 2,
    sameDrop: true,
    stops: [],
    init() {
      const clearStops = () => { if (!(this.tab === 'point_to_point' && this.trp === 'one_way')) this.stops = []; };
      this.$watch('tab', clearStops);
      this.$watch('trp', clearStops);
    },
    dateVal: '',
    timeVal: '',
    tmpH: 12,
    tmpM: 0,
    tmpAP: 'PM',
    dateOpen: false,
    timeOpen: false,
    calY: null,
    calM: null,
    dateTimeErr: false,
    heroSubmit(e) {
      if (this.tab === 'hourly' && this.sameDrop && this.$refs.pickup && this.$refs.destination) {
        this.$refs.destination.value = this.$refs.pickup.value;
      }
      if (!this.dateVal || !this.timeVal) {
        this.dateTimeErr = true;
        e.preventDefault();
      }
    },
    todayStr() {
      const t = new Date();
      return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
    },
    openDate() {
      const base = this.dateVal || this.todayStr();
      const p = base.split('-');
      this.calY = +p[0];
      this.calM = +p[1] - 1;
      this.dateTimeErr = false;
      this.dateOpen = true;
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
      this.dateVal = this.dayStr(d);
      this.dateTimeErr = false;
      this.dateOpen = false;
    },
    stepMonth(n) {
      const dt = new Date(this.calY, this.calM + n, 1);
      const now = new Date();
      if (new Date(dt.getFullYear(), dt.getMonth(), 1) < new Date(now.getFullYear(), now.getMonth(), 1)) return;
      this.calY = dt.getFullYear();
      this.calM = dt.getMonth();
    },
    fmtDate() {
      if (!this.dateVal) return '';
      const p = this.dateVal.split('-');
      return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString('en-US', {weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'});
    },
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
    spinH(d) {
      let h = this.tmpH + d;
      if (h > 12) h = 1;
      if (h < 1) h = 12;
      this.tmpH = h;
    },
    spinM(d) {
      let m = this.tmpM + d * 5;
      if (m > 55) m = 0;
      if (m < 0) m = 55;
      this.tmpM = m;
    },
    confirmTime() {
      const h24 = this.tmpAP === 'AM' ? this.tmpH % 12 : (this.tmpH % 12) + 12;
      this.timeVal = String(h24).padStart(2, '0') + ':' + String(this.tmpM).padStart(2, '0');
      this.dateTimeErr = false;
      this.timeOpen = false;
    },
    fmtTime() {
      if (!this.timeVal) return '';
      const p = this.timeVal.split(':');
      const h = +p[0];
      const ap = h < 12 ? 'AM' : 'PM';
      const h12 = h % 12 === 0 ? 12 : h % 12;
      return h12 + ':' + p[1] + ' ' + ap;
    },
    locOpen: false,
    locFor: 'pickup',
    stopIdx: null,
    locQuery: '',
    locType: '',
    locs: [
      {v: 'John F. Kennedy International Airport (JFK)', a: 'Queens · Airport', t: 'airport'},
      {v: 'LaGuardia Airport (LGA)', a: 'Queens · Airport', t: 'airport'},
      {v: 'Newark Liberty International Airport (EWR)', a: 'New Jersey · Airport', t: 'airport'},
      {v: 'Moynihan Train Hall, Penn Station', a: 'Manhattan · Rail', t: 'road'},
      {v: 'Grand Central Terminal', a: 'Manhattan · Rail', t: 'road'},
      {v: 'Port Authority Bus Terminal', a: 'Manhattan · Bus', t: 'road'},
      {v: 'Manhattan Cruise Terminal', a: 'Manhattan · Cruise', t: 'road'},
      {v: 'Brooklyn Cruise Terminal', a: 'Brooklyn · Cruise', t: 'road'},
      {v: 'Wall Street Piers', a: 'Manhattan · Cruise', t: 'road'},
      {v: 'Times Square', a: 'Manhattan', t: 'road'},
      {v: 'Central Park South', a: 'Manhattan', t: 'road'},
      {v: 'World Trade Center', a: 'Manhattan', t: 'road'},
      {v: 'Empire State Building', a: 'Manhattan', t: 'road'},
      {v: 'Madison Square Garden', a: 'Manhattan', t: 'road'},
      {v: 'Barclays Center', a: 'Brooklyn', t: 'road'},
      {v: 'Yankee Stadium', a: 'Bronx', t: 'road'},
      {v: 'Citi Field', a: 'Queens', t: 'road'},
      {v: 'MetLife Stadium', a: 'New Jersey', t: 'road'}
    ],
    openLoc(which, i = null) {
      this.locFor = which;
      this.stopIdx = i;
      this.locQuery = '';
      this.locType = '';
      this.locOpen = true;
      this.$nextTick(() => { if (this.$refs.locSearch) this.$refs.locSearch.focus(); });
    },
    setLocValue(v) {
      if (this.locFor === 'stop' && this.stopIdx !== null && this.stops[this.stopIdx] !== undefined) {
        this.stops.splice(this.stopIdx, 1, v);
      } else if (this.$refs[this.locFor]) {
        this.$refs[this.locFor].value = v;
      }
    },
    useSearchAsCustom() {
      const v = (this.locQuery || '').trim();
      if (!v) return;
      this.setLocValue(v);
      this.locOpen = false;
    },
    filteredLocs() {
      const q = (this.locQuery || '').toLowerCase().trim();
      return this.locs.filter(l => (!this.locType || l.t === this.locType) && (!q || (l.v + ' ' + l.a).toLowerCase().includes(q)));
    },
    pickLoc(v) {
      this.setLocValue(v);
      this.locOpen = false;
    },
    addStop() { if (this.stops.length < 6) this.stops.push(''); },
    rmStop(i) { this.stops.splice(i, 1); }
  };
}
</script>

<!-- FLEET MARQUEE: tonight in service -->
<div class="hairline-t hairline-b mt-10 md:mt-14 py-4 marquee" aria-label="Vehicles in service">
  <div class="marquee-track tabular text-xs tracking-[0.18em] text-[#AB8868]">
    <?php for ($r = 0; $r < 2; $r++): foreach ($roster as $item): ?>
      <span><?= $item ?> <span class="text-[#D9B978]">◆</span></span>
    <?php endforeach; endfor; ?>
  </div>
</div>

<!-- SIGNATURE JOURNEYS: interactive showcase -->
<section class="mt-10 md:mt-14 pb-10 md:pb-14" x-data="{j:'p2p'}">
  <div class="max-w-7xl mx-auto px-4">
    <p class="eyebrow">Start with a route</p>
    <h2 class="font-display text-3xl md:text-4xl text-[#F9F9F9] mt-2">Signature journeys</h2>
    <div class="grid lg:grid-cols-[1fr_1.2fr] gap-6 lg:gap-10 mt-6 items-stretch">
      <!-- Service selector -->
      <div class="flex lg:flex-col gap-2 overflow-x-auto no-scrollbar" role="tablist" aria-label="Journeys">
        <button type="button" role="tab" :aria-selected="j === 'p2p'" @click="j = 'p2p'"
          :class="j === 'p2p' ? 'border-[#C8A96B] bg-[#181819]' : 'border-[#2a2a2b] hover:border-[#3a3a3d]'"
          class="shrink-0 lg:shrink text-left border rounded-2xl p-4 md:p-5 min-w-[220px] lg:min-w-0">
          <span class="tabular block text-xs tracking-[0.2em] text-[#D9B978]">ELL·101</span>
          <span class="font-display block text-xl text-[#F9F9F9] mt-1">Point-to-Point</span>
          <span class="block text-xs text-[#AB8868] mt-1">Pickup → stops → destination</span>
        </button>
        <button type="button" role="tab" :aria-selected="j === 'airport'" @click="j = 'airport'"
          :class="j === 'airport' ? 'border-[#C8A96B] bg-[#181819]' : 'border-[#2a2a2b] hover:border-[#3a3a3d]'"
          class="shrink-0 lg:shrink text-left border rounded-2xl p-4 md:p-5 min-w-[220px] lg:min-w-0">
          <span class="tabular block text-xs tracking-[0.2em] text-[#D9B978]">ELL·102</span>
          <span class="font-display block text-xl text-[#F9F9F9] mt-1">Airport transfer</span>
          <span class="block text-xs text-[#AB8868] mt-1">Terminal to door, door to terminal</span>
        </button>
        <button type="button" role="tab" :aria-selected="j === 'hourly'" @click="j = 'hourly'"
          :class="j === 'hourly' ? 'border-[#C8A96B] bg-[#181819]' : 'border-[#2a2a2b] hover:border-[#3a3a3d]'"
          class="shrink-0 lg:shrink text-left border rounded-2xl p-4 md:p-5 min-w-[220px] lg:min-w-0">
          <span class="tabular block text-xs tracking-[0.2em] text-[#D9B978]">ELL·103</span>
          <span class="font-display block text-xl text-[#F9F9F9] mt-1">Evening on standby</span>
          <span class="block text-xs text-[#AB8868] mt-1">Hourly charter, 2-hour minimum</span>
        </button>
        <button type="button" role="tab" :aria-selected="j === 'groups'" @click="j = 'groups'"
          :class="j === 'groups' ? 'border-[#C8A96B] bg-[#181819]' : 'border-[#3a3a3d] text-[#F5F5F3]'"
          class="shrink-0 lg:shrink text-left border rounded-2xl p-4 md:p-5 min-w-[220px] lg:min-w-0">
          <span class="tabular block text-xs tracking-[0.2em] text-[#D9B978]">ELL·104</span>
          <span class="font-display block text-xl text-[#F9F9F9] mt-1">Groups &amp; events</span>
          <span class="block text-xs text-[#AB8868] mt-1">Multi-vehicle, quoted by our team</span>
        </button>
      </div>
      <!-- Feature panel -->
      <div class="relative rounded-2xl overflow-hidden bg-[#181819] border border-[#2a2a2b] min-h-[380px] lg:min-h-[480px]">
        <template x-if="j === 'p2p'">
          <div>
            <img src="https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1200&q=60" alt="Luxury car on the road" class="absolute inset-0 w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
            <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C] via-[#0A0A0C]/40 to-transparent"></div>
            <div class="absolute inset-x-0 bottom-0 p-6 md:p-8">
              <h3 class="font-display text-2xl md:text-3xl text-white">Across town, your way</h3>
              <p class="text-sm text-[#E5E5E3] mt-2">Up to 6 stops · one-way or round-trip<?php if ($minMile !== null): ?> · from $<?= money($minMile) ?>/mi<?php endif; ?></p>
              <a href="<?= url('services/booking.php?service=point_to_point') ?>" class="btn-gold rounded-full inline-block mt-4 text-sm px-6 py-2.5">Book point-to-point</a>
            </div>
          </div>
        </template>
        <template x-if="j === 'airport'">
          <div>
            <img src="https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1200&q=60" alt="Airplane wing at sunset" class="absolute inset-0 w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
            <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C] via-[#0A0A0C]/40 to-transparent"></div>
            <div class="absolute inset-x-0 bottom-0 p-6 md:p-8">
              <h3 class="font-display text-2xl md:text-3xl text-white">Never wait for a ride again</h3>
              <p class="text-sm text-[#E5E5E3] mt-2">60 min waiting included · meet &amp; greet available<?php if ($minMile !== null): ?> · from $<?= money($minMile) ?>/mi<?php endif; ?></p>
              <a href="<?= url('services/booking.php?service=airport') ?>" class="btn-gold rounded-full inline-block mt-4 text-sm px-6 py-2.5">Book airport transfer</a>
            </div>
          </div>
        </template>
        <template x-if="j === 'hourly'">
          <div>
            <img src="https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?auto=format&fit=crop&w=1200&q=60" alt="City streets at night" class="absolute inset-0 w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
            <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C] via-[#0A0A0C]/40 to-transparent"></div>
            <div class="absolute inset-x-0 bottom-0 p-6 md:p-8">
              <h3 class="font-display text-2xl md:text-3xl text-white">The night is yours</h3>
              <p class="text-sm text-[#E5E5E3] mt-2">2-hour minimum · chauffeur on call<?php if ($minHourly !== null): ?> · from $<?= money($minHourly) ?>/hr<?php endif; ?></p>
              <a href="<?= url('services/booking.php?service=hourly') ?>" class="btn-gold rounded-full inline-block mt-4 text-sm px-6 py-2.5">Book hourly charter</a>
            </div>
          </div>
        </template>
        <template x-if="j === 'groups'">
          <div>
            <img src="https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?auto=format&fit=crop&w=1200&q=60" alt="Chauffeur driving at night" class="absolute inset-0 w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
            <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C] via-[#0A0A0C]/40 to-transparent"></div>
            <div class="absolute inset-x-0 bottom-0 p-6 md:p-8">
              <h3 class="font-display text-2xl md:text-3xl text-white">Bring everyone</h3>
              <p class="text-sm text-[#E5E5E3] mt-2">Weddings · corporate · occasions · free quote</p>
              <a href="<?= url('services/group-event.php') ?>" class="btn-gold rounded-full inline-block mt-4 text-sm px-6 py-2.5">Request a group quote</a>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</section>

<!-- HOW IT WORKS: centered header, photo + floating cards, icon steps -->
<section class="max-w-7xl mx-auto px-4 py-10 md:py-14 hairline-t">
  <p class="eyebrow text-center">How booking works</p>
  <h2 class="font-display text-3xl md:text-4xl text-[#F9F9F9] mt-2 text-center">Four steps, no surprises</h2>
  <p class="text-sm text-[#AB8868] mt-2 text-center">No confusion or delays. Just fixed pricing and reliable chauffeurs.</p>
  <div class="grid lg:grid-cols-2 gap-10 lg:gap-16 mt-10 items-center">
    <!-- Photo with floating cards -->
    <div class="relative">
      <div class="rounded-2xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868]">
        <img src="https://images.unsplash.com/photo-1555215695-3004980ad54e?auto=format&fit=crop&w=1000&q=60" alt="Black luxury sedan at night" class="w-full h-[320px] md:h-[420px] object-cover" loading="lazy" onerror="this.style.display='none'">
      </div>
      <span class="absolute top-6 -right-2 sm:right-6 bg-[#D9B978] text-[#0A0A0C] text-xs font-semibold px-4 py-2 rounded-full">Fixed pricing</span>
      <div class="absolute -bottom-6 left-4 right-4 sm:left-8 sm:right-auto sm:w-72 bg-[#181819] border border-[#2a2a2b] rounded-2xl p-4 shadow-2xl">
        <p class="tabular text-[10px] tracking-widest text-[#AB8868]">SAMPLE TRIP SLIP</p>
        <p class="text-sm text-[#F9F9F9] mt-1 font-semibold">JFK → Manhattan</p>
        <p class="text-xs text-[#AB8868] mt-1">60 min waiting · total locked before payment</p>
      </div>
    </div>
    <!-- Steps -->
    <ol class="relative mt-6 lg:mt-0 space-y-8" aria-label="Booking steps">
      <li class="relative flex gap-4">
        <span aria-hidden="true" class="absolute left-[26px] top-[52px] -bottom-8 w-px bg-[#2a2a2b]"></span>
        <span class="relative z-10 w-[52px] h-[52px] shrink-0 rounded-2xl bg-[#D9B978] text-[#0A0A0C] inline-flex items-center justify-center" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
        </span>
        <span><span class="font-display block text-xl text-[#F9F9F9]">Tell us the route</span>
        <span class="block text-sm text-[#AB8868] mt-1">Pickup, stops, date and vehicle. It takes about a minute.</span></span>
      </li>
      <li class="relative flex gap-4">
        <span aria-hidden="true" class="absolute left-[26px] top-[52px] -bottom-8 w-px bg-[#2a2a2b]"></span>
        <span class="relative z-10 w-[52px] h-[52px] shrink-0 rounded-2xl border border-[#3a3a3d] bg-[#0A0A0C] text-[#F3D4A6] inline-flex items-center justify-center" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
        </span>
        <span><span class="font-display block text-xl text-[#F9F9F9]">Lock the price</span>
        <span class="block text-sm text-[#AB8868] mt-1">We calculate on our servers and freeze the total before you pay.</span></span>
      </li>
      <li class="relative flex gap-4">
        <span aria-hidden="true" class="absolute left-[26px] top-[52px] -bottom-8 w-px bg-[#2a2a2b]"></span>
        <span class="relative z-10 w-[52px] h-[52px] shrink-0 rounded-2xl border border-[#3a3a3d] bg-[#0A0A0C] text-[#F3D4A6] inline-flex items-center justify-center" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        </span>
        <span><span class="font-display block text-xl text-[#F9F9F9]">Pay securely</span>
        <span class="block text-sm text-[#AB8868] mt-1">Card through Stripe or a secure link. You always get an invoice.</span></span>
      </li>
      <li class="relative flex gap-4">
        <span class="relative z-10 w-[52px] h-[52px] shrink-0 rounded-2xl border border-[#3a3a3d] bg-[#0A0A0C] text-[#F3D4A6] inline-flex items-center justify-center" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-6 8-6s8 2 8 6"/></svg>
        </span>
        <span><span class="font-display block text-xl text-[#F9F9F9]">Meet your chauffeur</span>
        <span class="block text-sm text-[#AB8868] mt-1">Dispatched and confirmed by email. We wait — that is the job.</span></span>
      </li>
    </ol>
  </div>
  <div class="text-center mt-10">
    <a href="<?= url('services/booking.php') ?>" class="btn-gold rounded-full inline-block text-sm px-8 py-3">Start step 01</a>
  </div>
</section>

<!-- FLEET -->
<section id="fleet" class="max-w-7xl mx-auto px-4 py-10 md:py-14 hairline-t">
  <div class="flex items-end justify-between gap-4">
    <div>
      <p class="eyebrow">The roster</p>
      <h2 class="font-display text-3xl md:text-4xl text-[#F9F9F9] mt-2">The fleet</h2>
    </div>
    <a href="<?= url('services/booking.php') ?>" class="text-sm underline hidden sm:inline text-[#E5E5E3] shrink-0">Book your ride</a>
  </div>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5 mt-6">
    <?php foreach ($vehicles as $v):
      [$photoId, $photoAlt] = vehicle_photo($v['category'] ?? null);
    ?>
    <article class="card rounded-2xl overflow-hidden group flex flex-col">
      <div class="h-48 bg-gradient-to-br from-[#0A0A0C] to-[#AB8868] overflow-hidden">
        <img src="https://images.unsplash.com/<?= $photoId ?>?auto=format&fit=crop&w=800&q=60" alt="<?= e($photoAlt) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" onerror="this.style.display='none'">
      </div>
      <div class="p-5 flex flex-col flex-1">
        <p class="flight-code"><?= e(strtoupper($v['category'] ?? 'VEHICLE')) ?></p>
        <h3 class="font-display text-xl text-[#F3D4A6] mt-1"><?= e($v['make'] . ' ' . $v['model']) ?></h3>
        <p class="tabular text-xs text-[#AB8868] mt-1"><?= e((string)$v['year']) ?> · UP TO <?= (int)$v['passenger_capacity'] ?> SEATS · <?= (int)$v['luggage_capacity'] ?> BAGS</p>
        <p class="tabular text-xs font-semibold text-[#F3D4A6] mt-2">
          <?php if ($v['per_mile_rate'] !== null): ?>$<?= money($v['per_mile_rate']) ?>/mi<?php endif; ?>
          <?php if ($v['per_mile_rate'] !== null && $v['hourly_rate'] !== null): ?> · <?php endif; ?>
          <?php if ($v['hourly_rate'] !== null): ?>$<?= money($v['hourly_rate']) ?>/hr<?php endif; ?>
        </p>
        <div class="grid grid-cols-2 gap-2 mt-4">
          <a href="<?= url('services/booking.php') ?>" class="btn-gold rounded-full text-center text-xs font-semibold px-4 py-2.5">Book</a>
          <a href="<?= url('services/fleet.php?vehicle=' . (int)$v['id']) ?>" class="rounded-full text-center text-xs font-semibold px-4 py-2.5 border border-[#C8A96B] text-[#F3D4A6] hover:bg-[#D9B978]/10">View</a>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
    <?php if (!$vehicles): ?><p class="text-sm text-[#AB8868]">Fleet details coming soon — <a class="underline" href="<?= url('services/booking.php') ?>">book anyway</a>.</p><?php endif; ?>
  </div>
</section>

<!-- TRUST + FAQ -->
<section class="max-w-7xl mx-auto px-4 pb-12 hairline-t pt-10">
  <p class="eyebrow">Good to know</p>
  <h2 class="font-display text-3xl md:text-4xl text-[#F9F9F9] mt-2">Why riders choose Exotic Lane</h2>
  <div class="grid md:grid-cols-2 gap-8 mt-6">
    <ul class="grid sm:grid-cols-2 gap-4">
      <li class="card rounded-2xl p-5">
        <span class="w-10 h-10 rounded-full bg-[#D9B978] text-[#0A0A0C] inline-flex items-center justify-center font-bold" aria-hidden="true">✓</span>
        <p class="font-display text-lg text-[#F9F9F9] mt-3">Professional chauffeurs</p>
        <p class="text-xs text-[#AB8868] mt-1">Flight-friendly airport pickups, every time.</p>
      </li>
      <li class="card rounded-2xl p-5">
        <span class="w-10 h-10 rounded-full bg-[#D9B978] text-[#0A0A0C] inline-flex items-center justify-center font-bold" aria-hidden="true">$</span>
        <p class="font-display text-lg text-[#F9F9F9] mt-3">Locked pricing</p>
        <p class="text-xs text-[#AB8868] mt-1">Server-side totals, frozen before you pay.</p>
      </li>
      <li class="card rounded-2xl p-5">
        <span class="w-10 h-10 rounded-full bg-[#D9B978] text-[#0A0A0C] inline-flex items-center justify-center font-bold" aria-hidden="true">◈</span>
        <p class="font-display text-lg text-[#F9F9F9] mt-3">Secure payment</p>
        <p class="text-xs text-[#AB8868] mt-1">Stripe checkout with invoice on every ride.</p>
      </li>
      <li class="card rounded-2xl p-5">
        <span class="w-10 h-10 rounded-full bg-[#D9B978] text-[#0A0A0C] inline-flex items-center justify-center font-bold" aria-hidden="true">◷</span>
        <p class="font-display text-lg text-[#F9F9F9] mt-3">Waiting included</p>
        <p class="text-xs text-[#AB8868] mt-1">Free allowances for airports and terminals.</p>
      </li>
    </ul>
    <div class="card rounded-2xl p-5 md:p-6">
      <h3 class="font-display text-xl text-[#F3D4A6]">Questions, answered</h3>
      <details class="hairline-b py-3 group">
        <summary class="flex items-center justify-between gap-3 text-sm text-[#F9F9F9] font-semibold cursor-pointer [&::-webkit-details-marker]:hidden">How many stops can I add?<span class="text-[#C8A96B] group-open:rotate-45 transition-transform" aria-hidden="true">＋</span></summary>
        <p class="text-sm text-[#AB8868] mt-2">Up to 6 additional stops on point-to-point rides.</p>
      </details>
      <details class="hairline-b py-3 group">
        <summary class="flex items-center justify-between gap-3 text-sm text-[#F9F9F9] font-semibold cursor-pointer [&::-webkit-details-marker]:hidden">What is the hourly minimum?<span class="text-[#C8A96B] group-open:rotate-45 transition-transform" aria-hidden="true">＋</span></summary>
        <p class="text-sm text-[#AB8868] mt-2">2 hours — add more, never less.</p>
      </details>
      <details class="hairline-b py-3 group">
        <summary class="flex items-center justify-between gap-3 text-sm text-[#F9F9F9] font-semibold cursor-pointer [&::-webkit-details-marker]:hidden">How much free waiting do I get?<span class="text-[#C8A96B] group-open:rotate-45 transition-transform" aria-hidden="true">＋</span></summary>
        <p class="text-sm text-[#AB8868] mt-2">Airports 60 minutes, bus/train/cruise terminals 30, point-to-point 15.</p>
      </details>
      <details class="py-3 group">
        <summary class="flex items-center justify-between gap-3 text-sm text-[#F9F9F9] font-semibold cursor-pointer [&::-webkit-details-marker]:hidden">Can I change my pickup time?<span class="text-[#C8A96B] group-open:rotate-45 transition-transform" aria-hidden="true">＋</span></summary>
        <p class="text-sm text-[#AB8868] mt-2">Yes — until 2 hours before pickup, from your account.</p>
      </details>
      <p class="text-sm mt-2"><a class="underline" href="<?= url('legal/faq.php') ?>">Read all FAQs</a> · <a class="underline" href="<?= url('legal/contact.php') ?>">Contact us</a></p>
    </div>
  </div>
</section>
<?php
$content = ob_get_clean();
$pageTitle = null;
$metaDesc = 'Exotic Lane Limo — luxury chauffeur, airport and hourly service. Search, book and pay online.';
$hideHeader = true;
require APP_ROOT . '/views/layouts/public.php';
