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
            'child_seat' => !empty($_POST['addon_child_seat']),
            'booster_seat' => !empty($_POST['addon_booster_seat']),
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
ob_start();
?>
<div class="max-w-4xl mx-auto px-4 py-10" x-data="{service: '<?= e($prefService) ?>', quoteState: 'idle', quoteHtml: ''}">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Book Your Ride</h1>
  <p class="text-sm text-[#AB8868] mt-1">Pricing mode: <strong><?= e($mode === 'hourly' ? 'Hourly' : 'Per-Mile') ?></strong><?= $mapsEnabled ? ' · Maps enabled' : ' · Manual mileage verified by our team' ?></p>
  <?php if ($errors): ?><div class="alert alert-err mt-4"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
  <?php if (!$vehicles): ?><div class="alert alert-err mt-4">No vehicles are currently available. Please contact us.</div><?php endif; ?>
  <form method="post" data-once class="card p-6 mt-6 space-y-5" id="bookingForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="grid md:grid-cols-2 gap-4">
      <div><label class="label" for="service_type">Service</label>
        <select id="service_type" name="service_type" class="input" x-model="service" required>
          <option value="point_to_point">Point-to-Point</option>
          <option value="airport">Airport</option>
          <option value="hourly">Hourly</option>
        </select></div>
      <div><label class="label" for="trip_type">Trip type</label>
        <select id="trip_type" name="trip_type" class="input"><option value="one_way" <?= $prefTrip === 'one_way' ? 'selected' : '' ?>>One-Way</option><option value="round_trip" <?= $prefTrip === 'round_trip' ? 'selected' : '' ?>>Round-Trip</option></select></div>
    </div>
    <div x-show="service === 'airport'"><label class="label" for="airport_direction">Airport direction</label>
      <select id="airport_direction" name="airport_direction" class="input"><option value="">Select…</option><option value="to_airport" <?= $prefDir === 'to_airport' ? 'selected' : '' ?>>Address → Airport</option><option value="from_airport" <?= $prefDir === 'from_airport' ? 'selected' : '' ?>>Airport → Address</option></select></div>
    <div class="grid md:grid-cols-2 gap-4">
      <div><label class="label" for="pickup_location">Pickup</label><input id="pickup_location" name="pickup_location" class="input" required value="<?= e($prefPickup) ?>"></div>
      <div><label class="label" for="destination_location">Destination</label><input id="destination_location" name="destination_location" class="input" required value="<?= e($prefDest) ?>"></div>
    </div>
    <div><label class="label">Additional stops (max 6)</label>
      <div id="stops" class="space-y-2">
        <?php if ($prefStops): foreach ($prefStops as $i => $ps): ?><input name="stops[]" class="input" value="<?= e($ps) ?>" placeholder="Stop <?= $i + 1 ?> (optional)"><?php endforeach; ?>
        <?php else: ?><input name="stops[]" class="input" placeholder="Stop 1 (optional)"><?php endif; ?>
      </div>
      <button type="button" class="text-sm underline mt-1" onclick="const d=document.getElementById('stops');if(d.children.length<6){const i=document.createElement('input');i.name='stops[]';i.className='input';i.placeholder='Stop '+(d.children.length+1);d.appendChild(i);}">+ Add stop</button></div>
    <div class="grid md:grid-cols-2 gap-4">
      <div><label class="label" for="pickup_date">Date</label><input id="pickup_date" type="date" name="pickup_date" class="input" required value="<?= e($prefDate) ?>"></div>
      <div><label class="label" for="pickup_time">Time</label><input id="pickup_time" type="time" name="pickup_time" class="input" required value="<?= e($prefTime) ?>"></div>
    </div>
    <div class="grid md:grid-cols-2 gap-4">
      <div><label class="label" for="passengers">Passengers</label><input id="passengers" type="number" min="1" max="20" value="<?= $prefPax ?>" name="passengers" class="input" required></div>
      <div><label class="label" for="luggage">Luggage</label><input id="luggage" type="number" min="0" max="20" value="0" name="luggage" class="input"></div>
    </div>
    <div><label class="label" for="vehicle_id">Vehicle</label>
      <select id="vehicle_id" name="vehicle_id" class="input" required>
        <?php foreach ($vehicles as $v): ?>
        <option value="<?= (int)$v['id'] ?>"><?= e($v['make'] . ' ' . $v['model'] . ' (' . ($v['category'] ?? '') . ')') ?> — <?= $mode === 'hourly' ? '$' . money($v['hourly_rate'] ?? 0) . '/hr' : '$' . money($v['per_mile_rate'] ?? 0) . '/mi' ?> · <?= (int)$v['passenger_capacity'] ?> pax</option>
        <?php endforeach; ?>
      </select></div>
    <?php if ($mode === 'per_mile' && $mapsEnabled): ?>
    <div><label class="label" for="mileage">Trip mileage</label><input id="mileage" type="number" step="0.1" min="0" name="mileage" class="input" placeholder="Calculated via Maps"></div>
    <?php elseif ($mode === 'hourly'): ?>
    <div><label class="label" for="hours">Hours (min 2)</label><input id="hours" type="number" step="0.5" min="2" value="<?= e((string)$prefHours) ?>" name="hours" class="input"></div>
    <?php else: ?>
    <p class="text-xs text-[#AB8868]">Mileage is verified by our team after booking — you will receive a secure payment link once pricing is finalized.</p>
    <?php endif; ?>
    <fieldset><legend class="label">Add-ons</legend>
      <label class="text-sm block"><input type="checkbox" name="addon_meet_greet" value="1"> Meet &amp; Greet</label>
      <label class="text-sm block"><input type="checkbox" name="addon_child_seat" value="1"> Child Seat</label>
      <label class="text-sm block"><input type="checkbox" name="addon_booster_seat" value="1"> Booster Seat</label>
    </fieldset>
    <div><label class="label" for="coupon_code">Coupon (optional)</label><input id="coupon_code" name="coupon_code" class="input" placeholder="Code"></div>
    <?php if (!current_user('customer')): ?>
    <div class="grid md:grid-cols-3 gap-4">
      <div><label class="label" for="guest_name">Full name</label><input id="guest_name" name="guest_name" class="input" required></div>
      <div><label class="label" for="guest_email">Email</label><input id="guest_email" type="email" name="guest_email" class="input" required></div>
      <div><label class="label" for="guest_phone">Phone</label><input id="guest_phone" name="guest_phone" class="input"></div>
    </div>
    <p class="text-xs text-[#AB8868]">Booking as guest. <a class="underline" href="<?= url('auth/register.php') ?>">Create an account</a> to manage bookings. See <a class="underline" href="<?= url('legal/terms.php') ?>">Terms</a>.</p>
    <?php endif; ?>
    <button type="submit" class="btn-cta w-full text-lg">Continue → Price &amp; Payment</button>
  </form>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Book | Exotic Lane Limo';
$metaDesc = 'Book your luxury chauffeur ride online: instant server-side pricing, secure payment, email confirmation.';
require APP_ROOT . '/views/layouts/public.php';
