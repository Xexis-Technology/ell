<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();

$vehicles = $pdo->query('SELECT v.*, c.name AS category, pr.per_mile_rate, pr.hourly_rate FROM vehicles v LEFT JOIN vehicle_categories c ON c.id = v.category_id LEFT JOIN pricing_rates pr ON pr.vehicle_id = v.id AND pr.active = 1 WHERE v.status = "active" AND v.is_temporary = 0 ORDER BY v.id')->fetchAll();
$mode = PricingService::activeMode($pdo);
$mapsCfg = require APP_ROOT . '/config/maps.php';
$mapsEnabled = $mapsCfg['enabled'] || setting($pdo, 'maps_enabled', '0') === '1';

// JSON quote endpoint (Alpine.js)
if (($_POST['action'] ?? '') === 'quote' || ($_GET['action'] ?? '') === 'quote') {
    header('Content-Type: application/json');
    $in = $_POST;
    $in['stops'] = isset($in['stops']) ? (array)$in['stops'] : [];
    $in['addons'] = $in['addons'] ?? [];
    $calc = PricingService::calculate($pdo, $in);
    echo json_encode($calc);
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    require_csrf();
    $in = [
        'service_type' => $_POST['service_type'] ?? '',
        'trip_type' => $_POST['trip_type'] ?? 'one_way',
        'airport_direction' => $_POST['airport_direction'] ?? null,
        'pickup_location' => trim($_POST['pickup_location'] ?? ''),
        'destination_location' => trim($_POST['destination_location'] ?? ''),
        'pickup_date' => $_POST['pickup_date'] ?? '',
        'pickup_time' => $_POST['pickup_time'] ?? '',
        'passengers' => (int)($_POST['passengers'] ?? 1),
        'luggage' => (int)($_POST['luggage'] ?? 0),
        'vehicle_id' => (int)($_POST['vehicle_id'] ?? 0),
        'mileage' => ($_POST['mileage'] ?? '') !== '' ? (float)$_POST['mileage'] : null,
        'hours' => ($_POST['hours'] ?? '') !== '' ? (float)$_POST['hours'] : null,
        'stops' => array_values(array_filter(array_map('trim', (array)($_POST['stops'] ?? [])))),
        'addons' => [
            'meet_greet' => !empty($_POST['addon_meet_greet']),
            'child_seat' => !empty($_POST['addon_child_seat']) ? ['qty' => max(1, min(8, (int)($_POST['addon_child_seat_qty'] ?? 1)))] : false,
            'booster_seat' => !empty($_POST['addon_booster_seat']) ? ['qty' => max(1, min(8, (int)($_POST['addon_booster_seat_qty'] ?? 1)))] : false,
        ],
        'coupon_code' => trim($_POST['coupon_code'] ?? ''),
        'guest_name' => trim($_POST['guest_name'] ?? ''),
        'guest_email' => trim($_POST['guest_email'] ?? ''),
        'guest_phone' => trim($_POST['guest_phone'] ?? ''),
    ];
    if ($in['service_type'] === 'airport' && empty($in['airport_direction'])) {
        $errors['airport_direction'] = 'Choose a direction.';
    } else {
        $cust = current_user('customer');
        $res = BookingService::create($pdo, $in, $cust ? (int)$cust['id'] : null);
        if (isset($res['errors'])) {
            $errors = $res['errors'];
        } else {
            // Notify
            $b = $pdo->query('SELECT * FROM bookings WHERE id = ' . (int)$res['booking_id'])->fetch();
            $email = $cust ? $pdo->query('SELECT email FROM customers WHERE id = ' . (int)$cust['id'])->fetch()['email'] : ($in['guest_email'] ?: null);
            if ($email) {
                $tpl = $res['status'] === 'awaiting_pricing' ? 'booking-received-awaiting-pricing' : 'booking-created';
                NotificationService::bookingEmail($pdo, $tpl, $b, $email, $cust ? (int)$cust['id'] : null, $cust ? 'customer' : 'guest');
            }
            if ($res['status'] === 'awaiting_pricing') {
                redirect('services/booking-confirmation.php?n=' . $res['booking_number']);
            }
            // Online payment path: create intent then go to payment page
            $pi = PaymentService::createIntent($pdo, (int)$res['booking_id']);
            if (isset($pi['error'])) {
                redirect('services/booking-confirmation.php?n=' . $res['booking_number']);
            }
            redirect('services/payment.php?booking=' . $res['booking_number']);
        }
    }
}

$prefService = $_GET['service'] ?? $_POST['service_type'] ?? 'point_to_point';
$prefPickup = $_GET['pickup'] ?? '';
$prefDest = $_GET['destination'] ?? '';
$prefDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : '';
$prefPax = min(20, max(1, (int)($_GET['passengers'] ?? 1)));
$prefBags = max(0, min(20, (int)($_GET['luggage'] ?? 0)));
$hasParam = fn($k) => array_key_exists($k, $_GET);
$prefHours = min(24, max(2, (float)($_GET['hours'] ?? 2)));
$prefDir = in_array($_GET['direction'] ?? '', ['to_airport', 'from_airport'], true) ? $_GET['direction'] : '';
$prefStops = [];
foreach ((array)($_GET['stops'] ?? []) as $ps) {
    $ps = trim((string)$ps);
    if ($ps !== '') $prefStops[] = mb_substr($ps, 0, 255);
    if (count($prefStops) >= 6) break;
}
$prefTime = preg_match('/^\d{2}:\d{2}$/', (string)($_GET['time'] ?? '')) ? $_GET['time'] : '';
$prefTrip = in_array($_GET['trip'] ?? '', ['one_way', 'round_trip'], true) ? $_GET['trip'] : 'one_way';
$firstVeh = $vehicles[0] ?? null;
$firstVehMeta = $firstVeh ? (($firstVeh['category'] ?? 'Vehicle') . ' · up to ' . (int)$firstVeh['passenger_capacity'] . ' guests · ' . ($mode === 'hourly' ? '$' . money($firstVeh['hourly_rate'] ?? 0) . '/hr' : '$' . money($firstVeh['per_mile_rate'] ?? 0) . '/mi')) : '';
ob_start();
?>
<div class="max-w-6xl mx-auto px-4 py-10" x-data="bookingPage({service: '<?= e($prefService) ?>', pickup: <?= e(json_encode($prefPickup)) ?>, dest: <?= e(json_encode($prefDest)) ?>, date: '<?= e($prefDate) ?>', time: '<?= e($prefTime) ?>', pax: <?= $prefPax ?>, bags: <?= $prefBags ?>, trip: '<?= e($prefTrip) ?>', direction: '<?= e($prefDir) ?>', hours: <?= $prefHours ?>, hasLuggage: <?= $hasParam('luggage') ? 'true' : 'false' ?>, guest: <?= current_user('customer') ? 'false' : 'true' ?>, vid: <?= (int)($firstVeh['id'] ?? 0) ?>, vname: <?= e(json_encode(trim(($firstVeh['make'] ?? '') . ' ' . ($firstVeh['model'] ?? '')) ?: 'Select vehicle')) ?>, vmeta: <?= e(json_encode($firstVehMeta)) ?>, stops: <?= json_encode(array_values($prefStops), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>})">
<script src="<?= asset('js/booking.js') ?>"></script>
  <p class="eyebrow">Booking</p>
  <h1 class="font-display text-4xl md:text-5xl text-[#F9F9F9] mt-2">Book your ride</h1>
  <p class="text-sm text-[#AB8868] mt-2">Pricing mode: <strong><?= e($mode === 'hourly' ? 'Hourly' : 'Per-Mile') ?></strong><?= $mapsEnabled ? ' · Maps enabled' : ' · Manual mileage verified by our team' ?></p>
  <?php if ($errors): ?><div class="alert alert-err mt-4"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
  <?php if (!$vehicles): ?><div class="alert alert-err mt-4">No vehicles are currently available. Please contact us.</div><?php endif; ?>
  <!-- Step tracker: horizontal on all devices -->
  <nav class="mt-6 flex gap-2 overflow-x-auto no-scrollbar" aria-label="Booking steps">
    <template x-for="(label, i) in stepLabels()" :key="i">
      <button type="button" @click="go(i + 1)" :disabled="i + 1 > maxReached"
        :aria-current="step === i + 1 ? 'step' : null"
        :class="step === i + 1 ? 'bg-[#D9B978] text-[#0A0A0C]' : ((i + 1) <= maxReached ? 'border border-[#C8A96B] text-[#F3D4A6]' : 'border border-[#2a2a2b] text-[#AB8868]')"
        class="shrink-0 whitespace-nowrap tabular rounded-full px-4 py-2 text-xs font-semibold disabled:cursor-not-allowed">
        <span x-text="(i + 1) + ' · ' + label"></span>
      </button>
    </template>
  </nav>
  <p x-show="stepErr" x-cloak class="text-xs text-[#f3c1bd] mt-3" x-text="stepErr" role="alert"></p>
  <div class="grid lg:grid-cols-[1fr_300px] gap-6 mt-6 items-start">
  <form method="post" data-once class="space-y-4 min-w-0" id="bookingForm" @submit="heroSubmit($event)">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <!-- 01 Service -->
    <section class="card rounded-2xl p-5 md:p-6" aria-label="Step 1: service" data-stage="1" x-show="step === 1">
      <p class="tabular text-xs tracking-widest text-[#D9B978]">01 · SERVICE</p>
      <input type="hidden" name="service_type" :value="service">
      <input type="hidden" name="trip_type" :value="trp">
      <input type="hidden" name="airport_direction" :value="service === 'airport' ? dir : ''">
      <div class="grid sm:grid-cols-3 gap-3 mt-3" role="radiogroup" aria-label="Service">
        <button type="button" role="radio" :aria-checked="service === 'point_to_point'" @click="service = 'point_to_point'"
          :class="service === 'point_to_point' ? 'border-[#D9B978] bg-[#D9B978]/10' : 'border-[#2a2a2b] hover:border-[#3a3a3d]'"
          class="text-left border rounded-2xl p-4">
          <span class="font-display block text-lg text-[#F9F9F9]">Point-to-Point</span>
          <span class="block text-xs text-[#AB8868] mt-1">Pickup → stops → destination</span>
        </button>
        <button type="button" role="radio" :aria-checked="service === 'airport'" @click="service = 'airport'"
          :class="service === 'airport' ? 'border-[#D9B978] bg-[#D9B978]/10' : 'border-[#2a2a2b] hover:border-[#3a3a3d]'"
          class="text-left border rounded-2xl p-4">
          <span class="font-display block text-lg text-[#F9F9F9]">Airport</span>
          <span class="block text-xs text-[#AB8868] mt-1">Terminal ↔ address</span>
        </button>
        <button type="button" role="radio" :aria-checked="service === 'hourly'" @click="service = 'hourly'"
          :class="service === 'hourly' ? 'border-[#D9B978] bg-[#D9B978]/10' : 'border-[#2a2a2b] hover:border-[#3a3a3d]'"
          class="text-left border rounded-2xl p-4">
          <span class="font-display block text-lg text-[#F9F9F9]">Hourly</span>
          <span class="block text-xs text-[#AB8868] mt-1">Chauffeur on standby</span>
        </button>
      </div>
      <!-- Point-to-Point: trip type -->
      <div x-show="service === 'point_to_point'" class="mt-4">
        <p class="label">Trip type</p>
        <div class="grid grid-cols-2 gap-2" role="group" aria-label="Trip type">
          <button type="button" @click="trp = 'one_way'" :class="trp === 'one_way' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'border border-[#3a3a3d] text-[#F5F5F3]'" class="rounded-xl py-2.5 text-xs font-semibold">One Way</button>
          <button type="button" @click="trp = 'round_trip'" :class="trp === 'round_trip' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'border border-[#3a3a3d] text-[#F5F5F3]'" class="rounded-xl py-2.5 text-xs font-semibold">Round Trip</button>
        </div>
      </div>
      <!-- Airport: direction -->
      <div x-show="service === 'airport'" class="mt-4" id="dirBox" tabindex="-1">
        <p class="label">Direction</p>
        <div class="grid grid-cols-2 gap-2" role="group" aria-label="Airport direction">
          <button type="button" @click="dir = 'to_airport'" :class="dir === 'to_airport' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'border border-[#3a3a3d] text-[#F5F5F3]'" class="rounded-xl py-2.5 text-xs font-semibold">Address → Airport</button>
          <button type="button" @click="dir = 'from_airport'" :class="dir === 'from_airport' ? 'bg-[#D9B978] text-[#0A0A0C]' : 'border border-[#3a3a3d] text-[#F5F5F3]'" class="rounded-xl py-2.5 text-xs font-semibold">Airport → Address</button>
        </div>
      </div>
      <!-- Hourly: hours -->
      <div x-show="service === 'hourly'" class="mt-4">
        <p class="label">Hours <span class="text-[#AB8868]">(2 minimum)</span></p>
        <div class="flex items-center gap-3">
          <button type="button" @click="hours = Math.max(2, hours - 0.5)" aria-label="Fewer hours" class="w-10 h-10 rounded-full border border-[#3a3a3d] text-[#F3D4A6]">−</button>
          <span class="tabular text-lg font-semibold w-12 text-center" x-text="hours"></span>
          <button type="button" @click="hours = Math.min(24, hours + 0.5)" aria-label="More hours" class="w-10 h-10 rounded-full border border-[#3a3a3d] text-[#F3D4A6]">+</button>
          <input type="hidden" name="hours" :value="service === 'hourly' ? hours : ''">
        </div>
      </div>
      <div class="flex justify-end mt-5">
        <button type="button" @click="next()" class="btn-gold rounded-full text-sm px-8 py-3">Continue →</button>
      </div>
    </section>
    <!-- 02 Route -->
    <section class="card rounded-2xl p-5 md:p-6" aria-label="Step 2: route" data-stage="2" x-show="step === 2">
      <p class="tabular text-xs tracking-widest text-[#D9B978]">02 · ROUTE</p>
      <div class="grid grid-cols-2 gap-3 md:gap-4 mt-3">
        <div><label class="label" for="pickup_location">Pickup — tap to choose</label>
          <div class="relative">
            <input id="pickup_location" x-ref="pickup" name="pickup_location" class="input rounded-xl pr-11 cursor-pointer" required readonly @click="openLoc('pickup'); $el.blur()" placeholder="Tap to choose location" x-model="pickup">
            <button type="button" @click="openLoc('pickup')" aria-label="Browse pickup locations" class="absolute right-2 top-1/2 -translate-y-1/2 text-[#C8A96B] hover:text-[#F3D4A6] p-1">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
            </button>
          </div></div>
        <div x-show="service !== 'hourly' || !sameDrop"><label class="label" for="destination_location">Destination — tap to choose</label>
          <div class="relative">
            <input id="destination_location" x-ref="destination" name="destination_location" class="input rounded-xl pr-11 cursor-pointer" required readonly @click="openLoc('destination'); $el.blur()" placeholder="Tap to choose location" x-model="dest">
            <button type="button" @click="openLoc('destination')" aria-label="Browse destination locations" class="absolute right-2 top-1/2 -translate-y-1/2 text-[#C8A96B] hover:text-[#F3D4A6] p-1">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
            </button>
          </div></div>
        <div x-show="service === 'hourly'" class="col-span-2">
          <label class="flex items-center gap-2.5 text-sm text-[#F5F5F3] cursor-pointer">
            <input type="checkbox" x-model="sameDrop" class="w-4 h-4 accent-[#D9B978]">
            Drop-off same as pick-up
          </label>
        </div>
      </div>
      <p x-show="routeErr" class="text-xs text-[#f3c1bd] mt-2" x-text="routeErr" role="alert"></p>
      <div class="mt-4" x-show="service === 'point_to_point' && trp === 'one_way'"><label class="label">Additional stops (max 6) — tap a row to choose</label>
        <div class="space-y-2">
          <template x-for="(s, i) in stops" :key="i">
            <div class="flex gap-2">
              <input name="stops[]" x-model="stops[i]" readonly @click="openLoc('stop', i); $el.blur()" :placeholder="'Stop ' + (i + 1) + ' — tap to choose'" :aria-label="'Extra stop ' + (i + 1)" class="input cursor-pointer">
              <button type="button" @click="rmStop(i)" aria-label="Remove stop" class="shrink-0 text-[#AB8868] hover:text-[#f3c1bd] px-2">✕</button>
            </div>
          </template>
        </div>
        <button type="button" @click="addStop()" x-show="stops.length < 6" class="w-full mt-2 border border-dashed border-[#C8A96B] text-[#F3D4A6] hover:bg-[#D9B978]/10 rounded-xl py-3 text-sm font-semibold inline-flex items-center justify-center gap-2">
          <span class="w-5 h-5 rounded-full bg-[#D9B978] text-[#0A0A0C] inline-flex items-center justify-center text-sm font-bold" aria-hidden="true">+</span>
          Add stop <span class="tabular font-normal" x-show="stops.length > 0" x-text="'(' + stops.length + ' of 6 max)'"></span>
        </button></div>
      <div class="flex justify-between mt-5">
        <button type="button" @click="back()" class="rounded-full text-sm px-6 py-3 border border-[#3a3a3d] text-[#F5F5F3]">← Back</button>
        <button type="button" @click="next()" class="btn-gold rounded-full text-sm px-8 py-3">Continue →</button>
      </div>
    </section>
    <!-- 03 Schedule -->
    <section class="card rounded-2xl p-5 md:p-6" aria-label="Step 3: schedule and party" data-stage="3" x-show="step === 3">
      <p class="tabular text-xs tracking-widest text-[#D9B978]">03 · SCHEDULE &amp; PARTY</p>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-3">
        <div><label class="label" for="pickup_date">Date — tap to choose</label>
          <input id="pickup_date" :value="fmtDate()" readonly @click="openDate(); $el.blur()" placeholder="Select date" aria-label="Pickup date, tap to choose" class="input cursor-pointer">
          <input type="hidden" name="pickup_date" :value="dateVal"></div>
        <div><label class="label" for="pickup_time">Time — tap to choose</label>
          <input id="pickup_time" :value="fmtTime()" readonly @click="openTime(); $el.blur()" placeholder="Select time" aria-label="Pickup time, tap to choose" class="input cursor-pointer">
          <input type="hidden" name="pickup_time" :value="timeVal"></div>
        <div><label class="label" for="passengers">Passengers</label><input id="passengers" type="number" min="1" max="20" name="passengers" class="input" required x-model.number="pax"></div>
        <div><label class="label" for="luggage">Luggage</label><input id="luggage" type="number" min="0" max="20" name="luggage" class="input" x-model.number="lug"></div>
      </div>
      <div class="flex justify-between mt-5">
        <button type="button" @click="back()" class="rounded-full text-sm px-6 py-3 border border-[#3a3a3d] text-[#F5F5F3]">← Back</button>
        <button type="button" @click="next()" class="btn-gold rounded-full text-sm px-8 py-3">Continue →</button>
      </div>
    </section>
    <!-- 04 Vehicle & price -->
    <section class="card rounded-2xl p-5 md:p-6" aria-label="Step 4: vehicle and price" data-stage="4" x-show="step === 4">
      <p class="tabular text-xs tracking-widest text-[#D9B978]">04 · VEHICLE &amp; PRICE</p>
      <div class="mt-3"><label class="label" id="vehicle_label">Vehicle — tap to choose</label>
        <input type="hidden" name="vehicle_id" :value="vehicleId">
        <button type="button" @click="vehOpen = true" aria-labelledby="vehicle_label" class="w-full flex items-center gap-3 bg-[#0A0A0C] border border-[#2a2a2b] rounded-xl px-4 py-3 text-left">
          <span class="w-2.5 h-2.5 rounded-full bg-[#D9B978] shrink-0" aria-hidden="true"></span>
          <span class="flex-1 min-w-0"><span class="block text-sm text-white truncate" x-text="vehicleName || 'Select vehicle'"></span><span class="block text-[11px] text-[#AB8868]" x-text="vehicleMeta"></span></span>
          <span class="text-[#C8A96B] text-xs underline shrink-0">Change</span>
        </button></div>
      <!-- Vehicle picker popup -->
      <div x-show="vehOpen" x-cloak class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" aria-label="Choose a vehicle">
        <div class="absolute inset-0 bg-black/70" @click="vehOpen = false"></div>
        <div class="relative w-full sm:max-w-3xl bg-[#181819] border border-[#2a2a2b] rounded-t-2xl sm:rounded-2xl p-4 md:p-5 max-h-[85vh] flex flex-col" @keydown.escape.window="vehOpen = false">
          <div class="flex items-center justify-between">
            <h3 class="font-display text-xl text-[#F3D4A6]">Choose vehicle</h3>
            <button type="button" @click="vehOpen = false" aria-label="Close" class="text-[#AB8868] hover:text-white text-xl px-2">✕</button>
          </div>
          <div class="overflow-y-auto no-scrollbar grid md:grid-cols-2 gap-3 mt-3">
            <?php foreach ($vehicles as $v):
              [$vpId, $vpAlt] = vehicle_photo($v['category'] ?? null);
            ?>
            <div @click="pickVehicle(<?= (int)$v['id'] ?>, <?= e(json_encode($v['make'] . ' ' . $v['model'])) ?>, <?= e(json_encode(($v['category'] ?? 'Vehicle') . ' · up to ' . (int)$v['passenger_capacity'] . ' guests')) ?>)" @keydown.enter="pickVehicle(<?= (int)$v['id'] ?>, <?= e(json_encode($v['make'] . ' ' . $v['model'])) ?>, <?= e(json_encode(($v['category'] ?? 'Vehicle') . ' · up to ' . (int)$v['passenger_capacity'] . ' guests')) ?>)" role="button" tabindex="0" :aria-pressed="vehicleId === <?= (int)$v['id'] ?>"
              :class="vehicleId === <?= (int)$v['id'] ?> ? 'border-[#D9B978]' : 'border-[#2a2a2b]'"
              class="w-full text-left flex items-center gap-3 rounded-2xl border hover:border-[#C8A96B] bg-[#0A0A0C] p-3 cursor-pointer">
              <span class="w-24 h-20 shrink-0 rounded-xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868]">
                <img src="https://images.unsplash.com/<?= $vpId ?>?auto=format&fit=crop&w=400&q=60" alt="<?= e($vpAlt) ?>" class="w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
              </span>
              <span class="flex-1 min-w-0">
                <span class="block text-sm text-[#F9F9F9] font-semibold truncate"><?= e($v['make'] . ' ' . $v['model']) ?></span>
                <span class="tabular block text-[11px] text-[#AB8868] mt-0.5"><?= e(($v['category'] ?? 'Vehicle') . ' · up to ' . (int)$v['passenger_capacity'] . ' guests') ?></span>
                <span class="tabular block text-[11px] font-semibold text-[#F3D4A6] mt-0.5">$<?= money($v['per_mile_rate'] ?? 0) ?>/mi · $<?= money($v['hourly_rate'] ?? 0) ?>/hr</span>
              </span>
              <a href="<?= url('services/fleet.php?vehicle=' . urlencode($v['slug'] ?: ('vehicle-' . (int)$v['id']))) ?>" @click.stop class="shrink-0 text-[11px] font-semibold text-[#F3D4A6] underline px-1 py-2">Read more</a>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php if ($mode === 'per_mile' && $mapsEnabled): ?>
      <div class="mt-4"><label class="label" for="mileage">Trip mileage</label><input id="mileage" type="number" step="0.1" min="0" name="mileage" class="input" placeholder="Calculated via Maps"></div>
      <?php elseif ($mode === 'hourly'): ?>
      <div class="mt-4"><label class="label" for="hours">Hours (min 2)</label><input id="hours" type="number" step="0.5" min="2" value="<?= e((string)$prefHours) ?>" name="hours" class="input"></div>
      <?php else: ?>
      <p class="text-xs text-[#AB8868] mt-4">Mileage is verified by our team after booking — you will receive a secure payment link once pricing is finalized.</p>
      <?php endif; ?>
      <div class="mt-4" x-data="{open:false}">
        <button type="button" @click="open = !open" :aria-expanded="open" class="w-full flex items-center justify-between bg-[#0A0A0C] border border-[#2a2a2b] hover:border-[#C8A96B] rounded-xl px-4 py-3">
          <span class="text-sm text-[#F5F5F3] font-semibold">Add-ons <span class="text-[11px] font-normal text-[#AB8868]">(optional)</span></span>
          <span class="text-[#C8A96B] transition-transform" :class="open ? 'rotate-180' : ''" aria-hidden="true">▾</span>
        </button>
        <div x-show="open" x-cloak class="grid sm:grid-cols-3 gap-3 mt-3">
          <label class="card rounded-2xl p-4 cursor-pointer flex gap-3 has-checked:border-[#D9B978] has-checked:bg-[#D9B978]/5">
            <input type="checkbox" name="addon_meet_greet" value="1" x-model="adMG" class="peer sr-only">
            <span class="mt-0.5 w-5 h-5 shrink-0 rounded-md border border-[#3a3a3d] inline-flex items-center justify-center text-transparent peer-checked:bg-[#D9B978] peer-checked:text-[#0A0A0C] peer-checked:border-[#D9B978]" aria-hidden="true">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
            </span>
            <span class="min-w-0">
              <span class="font-display block text-lg text-[#F9F9F9]">Meet &amp; Greet</span>
              <span class="block text-xs text-[#AB8868] mt-1">Your chauffeur meets you at the designated pickup area.</span>
              <span class="tabular inline-block text-xs font-semibold text-[#0A0A0C] bg-[#D9B978] rounded-full px-2.5 py-1 mt-2">+$<?= money(PricingService::chargeAmount($pdo, 'meet_greet') ?? 25) ?></span>
            </span>
          </label>
          <div class="card rounded-2xl p-4">
            <label class="flex gap-3 cursor-pointer">
              <input type="checkbox" name="addon_child_seat" value="1" x-model="adCS" class="peer sr-only">
              <span class="mt-0.5 w-5 h-5 shrink-0 rounded-md border border-[#3a3a3d] inline-flex items-center justify-center text-transparent peer-checked:bg-[#D9B978] peer-checked:text-[#0A0A0C] peer-checked:border-[#D9B978]" aria-hidden="true">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
              </span>
              <span class="font-display text-lg text-[#F9F9F9]">Child Seat</span>
            </label>
            <p class="text-xs text-[#AB8868] mt-1 ml-8">Safety seat for young children, fitted on arrival.</p>
            <div class="flex items-center gap-3 mt-3 ml-8">
              <span class="text-[11px] text-[#AB8868]">How many?</span>
              <button type="button" @click="csQ = Math.max(1, csQ - 1)" aria-label="Fewer child seats" class="w-8 h-8 rounded-full border border-[#3a3a3d] text-[#F3D4A6]">−</button>
              <span class="tabular text-sm font-bold w-6 text-center" x-text="csQ"></span>
              <button type="button" @click="csQ = Math.min(8, csQ + 1)" aria-label="More child seats" class="w-8 h-8 rounded-full border border-[#3a3a3d] text-[#F3D4A6]">+</button>
              <input type="hidden" name="addon_child_seat_qty" :value="csQ">
            </div>
            <p class="tabular text-xs font-semibold text-[#F3D4A6] mt-2 ml-8">+$<?= money(PricingService::chargeAmount($pdo, 'child_seat') ?? 15) ?> each</p>
          </div>
          <div class="card rounded-2xl p-4">
            <label class="flex gap-3 cursor-pointer">
              <input type="checkbox" name="addon_booster_seat" value="1" x-model="adBS" class="peer sr-only">
              <span class="mt-0.5 w-5 h-5 shrink-0 rounded-md border border-[#3a3a3d] inline-flex items-center justify-center text-transparent peer-checked:bg-[#D9B978] peer-checked:text-[#0A0A0C] peer-checked:border-[#D9B978]" aria-hidden="true">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
              </span>
              <span class="font-display text-lg text-[#F9F9F9]">Booster Seat</span>
            </label>
            <p class="text-xs text-[#AB8868] mt-1 ml-8">Extra support and height for older children.</p>
            <div class="flex items-center gap-3 mt-3 ml-8">
              <span class="text-[11px] text-[#AB8868]">How many?</span>
              <button type="button" @click="bsQ = Math.max(1, bsQ - 1)" aria-label="Fewer booster seats" class="w-8 h-8 rounded-full border border-[#3a3a3d] text-[#F3D4A6]">−</button>
              <span class="tabular text-sm font-bold w-6 text-center" x-text="bsQ"></span>
              <button type="button" @click="bsQ = Math.min(8, bsQ + 1)" aria-label="More booster seats" class="w-8 h-8 rounded-full border border-[#3a3a3d] text-[#F3D4A6]">+</button>
              <input type="hidden" name="addon_booster_seat_qty" :value="bsQ">
            </div>
            <p class="tabular text-xs font-semibold text-[#F3D4A6] mt-2 ml-8">+$<?= money(PricingService::chargeAmount($pdo, 'booster_seat') ?? 10) ?> each</p>
          </div>
        </div>
      </div>
      <div class="mt-4"><label class="label" for="coupon_code">Coupon (optional)</label><input id="coupon_code" name="coupon_code" class="input" placeholder="Discount code" x-model="cp"></div>
      <div class="flex justify-between mt-5">
        <button type="button" @click="back()" class="rounded-full text-sm px-6 py-3 border border-[#3a3a3d] text-[#F5F5F3]">← Back</button>
        <template x-if="step === totalSteps()"><button type="submit" class="btn-cta rounded-full text-sm px-8 py-3">Continue</button></template>
        <template x-if="step !== totalSteps()"><button type="button" @click="next()" class="btn-gold rounded-full text-sm px-8 py-3">Continue →</button></template>
      </div>
    </section>
    <?php if (!current_user('customer')): ?>
    <!-- 05 Contact -->
    <section class="card rounded-2xl p-5 md:p-6" aria-label="Step 5: contact" data-stage="5" x-show="step === 5">
      <p class="tabular text-xs tracking-widest text-[#D9B978]">05 · CONTACT</p>
      <div class="grid md:grid-cols-3 gap-4 mt-3">
        <div><label class="label" for="guest_name">Full name</label><input id="guest_name" name="guest_name" class="input" required placeholder="Jane Smith"></div>
        <div><label class="label" for="guest_email">Email</label><input id="guest_email" type="email" name="guest_email" class="input" required placeholder="you@example.com"></div>
        <div><label class="label" for="guest_phone">Phone</label><input id="guest_phone" name="guest_phone" class="input" placeholder="+1 555 010 2030"></div>
      </div>
      <p class="text-xs text-[#AB8868] mt-3">Booking as guest. <a class="underline" href="<?= url('auth/register.php') ?>">Create an account</a> to manage bookings. See <a class="underline" href="<?= url('legal/terms.php') ?>">Terms</a>.</p>
      <div class="flex justify-between mt-5">
        <button type="button" @click="back()" class="rounded-full text-sm px-6 py-3 border border-[#3a3a3d] text-[#F5F5F3]">← Back</button>
        <button type="submit" class="btn-cta rounded-full text-sm px-8 py-3">Continue</button>
      </div>
    </section>
    <?php endif; ?>
    <p x-show="dateTimeErr" x-cloak class="text-xs text-[#f3c1bd]">Please choose a pick-up date and time.</p>
  </form>
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
      <p x-show="routeErr" class="text-xs text-[#f3c1bd] px-1 mt-2" x-text="routeErr" role="alert"></p>
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
    <div class="relative w-full sm:max-w-sm bg-[#181819] border border-[#2a2a2b] rounded-t-2xl sm:rounded-2xl p-4 md:p-5" @keydown.escape.window="timeOpen = false">
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
  <!-- Live trip summary: mirrors every field in real time -->
  <aside class="card rounded-2xl p-5 lg:sticky lg:top-20 h-fit" aria-label="Your trip summary" aria-live="polite">
    <p class="tabular text-xs tracking-widest text-[#D9B978]">YOUR TRIP</p>
    <p class="font-display text-xl text-[#F9F9F9] mt-2" x-text="service === 'airport' ? 'Airport transfer' : (service === 'hourly' ? 'Hourly charter' : 'Point-to-Point')"></p>
    <p class="tabular text-[11px] text-[#AB8868]" x-text="service === 'point_to_point' ? (trp === 'round_trip' ? 'Round trip' : 'One way') : (service === 'airport' ? (dir === 'from_airport' ? 'Airport → Address' : 'Address → Airport') : (hours + ' hours (min 2)'))"></p>
    <div class="mt-3 space-y-2 text-sm">
      <p class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-[#D9B978] shrink-0" aria-hidden="true"></span><span class="text-[#E5E5E3]" x-text="pickup || 'Pickup…'"></span></p>
      <template x-for="(s, i) in stops" :key="i">
        <p class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full border border-[#C8A96B] shrink-0 ml-0.5" aria-hidden="true"></span><span class="text-[#E5E5E3] text-[13px]" x-text="s || ('Stop ' + (i + 1))"></span></p>
      </template>
      <p class="flex items-center gap-2" x-show="service !== 'hourly' || !sameDrop"><span class="w-2 h-2 bg-[#AB8868] shrink-0" aria-hidden="true"></span><span class="text-[#E5E5E3]" x-text="dest || 'Destination…'"></span></p>
      <p class="tabular text-xs text-[#AB8868]" x-text="(fmtDate() || 'Date…') + ' · ' + (fmtTime() || 'Time…')"></p>
      <p class="tabular text-xs text-[#AB8868]" x-text="pax + ' pax · ' + lug + ' bags'"></p>
    </div>
    <div class="hairline-t mt-3 pt-3 space-y-1.5 text-sm">
      <p class="flex items-center gap-2"><span class="text-[10px] tracking-widest text-[#AB8868] w-16 shrink-0">VEHICLE</span><span class="text-[#E5E5E3] truncate" x-text="vehicleName || 'Not chosen'"></span></p>
      <div x-show="adMG || adCS || adBS">
        <p class="text-[10px] tracking-widest text-[#AB8868]">ADD-ONS</p>
        <p class="text-xs text-[#E5E5E3] mt-0.5" x-show="adMG">Meet &amp; Greet</p>
        <p class="text-xs text-[#E5E5E3] mt-0.5" x-show="adCS" x-text="'Child Seat × ' + csQ"></p>
        <p class="text-xs text-[#E5E5E3] mt-0.5" x-show="adBS" x-text="'Booster Seat × ' + bsQ"></p>
      </div>
      <p x-show="cp.trim() !== ''" class="text-xs text-[#E5E5E3]">Coupon: <span class="tabular font-semibold text-[#F3D4A6]" x-text="cp.trim().toUpperCase()"></span></p>
    </div>
    <div class="hairline-t mt-3 pt-3 text-xs text-[#AB8868] space-y-1">
      <p>✓ Price locked before you pay</p>
      <p>✓ Free waiting included</p>
      <p>✓ Invoice on every booking</p>
    </div>
    <p class="text-xs mt-3"><a class="underline" href="<?= url('legal/faq.php') ?>">Questions?</a> · <a class="underline" href="<?= url('legal/contact.php') ?>">Contact us</a></p>
  </aside>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Book | Exotic Lane Limo';
$metaDesc = 'Book your luxury chauffeur ride online: instant server-side pricing, secure payment, email confirmation.';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';