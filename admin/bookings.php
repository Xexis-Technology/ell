<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$action = $_GET['action'] ?? 'list';
$msg = '';
$isErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['op'] ?? '';
    // Admin-created booking (offline/manual path supported) — no existing booking needed
    if ($act === 'admin_create') {
        $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;
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
            'stops' => array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['stops'] ?? ''))))),
            'addons' => ['meet_greet' => !empty($_POST['addon_meet_greet']), 'child_seat' => !empty($_POST['addon_child_seat']), 'booster_seat' => !empty($_POST['addon_booster_seat'])],
            'coupon_code' => trim($_POST['coupon_code'] ?? ''),
            'guest_name' => trim($_POST['guest_name'] ?? ''),
            'guest_email' => trim($_POST['guest_email'] ?? ''),
            'guest_phone' => trim($_POST['guest_phone'] ?? ''),
        ];
        $res = BookingService::create($pdo, $in, $customerId);
        if (isset($res['errors'])) {
            $msg = implode(' ', $res['errors']);
            header('Location: ' . url('admin/bookings.php?action=create&msg=' . urlencode($msg)));
            exit;
        }
        audit($pdo, 'admin', (int)$admin['id'], 'booking.admin_created', 'booking', (int)$res['booking_id'], ['booking_number' => $res['booking_number']]);
        header('Location: ' . url('admin/bookings.php?action=view&n=' . $res['booking_number'] . '&msg=' . urlencode('Booking created: ' . $res['booking_number'])));
        exit;
    }
    $bid = (int)($_POST['booking_id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
    $st->execute([$bid]);
    $b = $st->fetch();
    if (!$b) {
        $msg = 'Booking not found.';
        $isErr = true;
    } else {
        switch ($act) {
            case 'status':
                $ns = $_POST['new_status'] ?? '';
                $msg = BookingService::setStatus($pdo, $bid, $ns, 'admin', (int)$admin['id'], 'Admin status change') ? 'Status updated.' : 'Invalid status.';
                $isErr = !$msg || str_starts_with($msg, 'Invalid');
                break;
            case 'cancel':
                BookingService::setStatus($pdo, $bid, 'cancelled', 'admin', (int)$admin['id'], 'Admin cancel');
                $pdo->prepare('UPDATE bookings SET cancelled_at = NOW() WHERE id = ?')->execute([$bid]);
                $bc = $pdo->query('SELECT * FROM bookings WHERE id = ' . $bid)->fetch();
                $bemail = $bc['guest_email'];
                if ($bc['customer_id']) $bemail = $pdo->query('SELECT email FROM customers WHERE id = ' . (int)$bc['customer_id'])->fetch()['email'] ?? $bemail;
                if ($bemail) NotificationService::bookingEmail($pdo, 'booking-cancelled', $bc, $bemail, $bc['customer_id'], $bc['customer_id'] ? 'customer' : 'guest');
                $msg = 'Booking cancelled.';
                break;
            case 'admin_edit':
                if (in_array($b['status'], ['finish','cancelled','refunded'], true)) {
                    $msg = 'Completed/cancelled bookings cannot be edited.';
                    $isErr = true;
                } else {
                    $pdo->prepare('UPDATE bookings SET pickup_location = ?, destination_location = ?, pickup_date = ?, pickup_time = ?, passengers = ?, luggage = ?, vehicle_id = ?, trip_type = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([trim($_POST['pickup_location'] ?? $b['pickup_location']), trim($_POST['destination_location'] ?? $b['destination_location']), $_POST['pickup_date'] ?? $b['pickup_date'], $_POST['pickup_time'] ?? $b['pickup_time'], (int)($_POST['passengers'] ?? $b['passengers']), (int)($_POST['luggage'] ?? $b['luggage']), (int)($_POST['vehicle_id'] ?? $b['vehicle_id']), in_array($_POST['trip_type'] ?? '', ['one_way','round_trip'], true) ? $_POST['trip_type'] : $b['trip_type'], $bid]);
                    audit($pdo, 'admin', (int)$admin['id'], 'booking.edited', 'booking', $bid, null);
                    $msg = 'Booking updated (route/schedule/vehicle). Totals unchanged — re-finalize price if needed.';
                }
                break;
            case 'mileage':
                $r = BookingService::finalizePricing($pdo, $bid, ['vehicle_id' => (int)($_POST['vehicle_id'] ?? $b['vehicle_id']), 'mileage' => (float)($_POST['mileage'] ?? 0), 'hours' => $b['hours'], 'reason' => 'mileage set'], (int)$admin['id']);
                $msg = $r['error'] ?? 'Mileage set, price finalized.';
                $isErr = isset($r['error']);
                break;
            case 'finalize':
                $manual = [];
                foreach ((array)($_POST['mc_code'] ?? []) as $i => $code) {
                    $amt = (float)($_POST['mc_amount'][$i] ?? 0);
                    if ($amt > 0) $manual[] = ['code' => $code, 'label' => $_POST['mc_label'][$i] ?? $code, 'amount' => $amt];
                }
                $r = BookingService::finalizePricing($pdo, $bid, ['vehicle_id' => (int)($_POST['vehicle_id'] ?? $b['vehicle_id']), 'mileage' => ($_POST['mileage'] ?? '') !== '' ? (float)$_POST['mileage'] : $b['mileage'], 'hours' => ($_POST['hours'] ?? '') !== '' ? (float)$_POST['hours'] : $b['hours'], 'manual_charges' => $manual, 'coupon_code' => trim($_POST['coupon_code'] ?? ''), 'reason' => 'admin finalize'], (int)$admin['id']);
                $msg = $r['error'] ?? 'Price finalized.';
                $isErr = isset($r['error']);
                break;
            case 'payment_link':
                $link = PaymentService::createPaymentLink($pdo, $bid);
                $email = $b['guest_email'];
                if ($b['customer_id']) $email = $pdo->query('SELECT email FROM customers WHERE id = ' . (int)$b['customer_id'])->fetch()['email'] ?? $email;
                if ($email) NotificationService::bookingEmail($pdo, 'payment-link', $b, $email, $b['customer_id'], $b['customer_id'] ? 'customer' : 'guest', ['html' => '<p>Your price is finalized: <strong>$' . money($b['total']) . '</strong>.</p><p><a href="' . e($link['url']) . '">Pay securely now</a></p>']);
                audit($pdo, 'admin', (int)$admin['id'], 'payment.link_sent', 'booking', $bid, null);
                $msg = 'Payment link created & emailed: ' . $link['url'];
                break;
            case 'offline_pay':
                $r = PaymentService::recordOffline($pdo, $bid, (float)$_POST['amount'], (int)$admin['id'], trim($_POST['note'] ?? ''));
                $msg = $r['error'] ?? 'Offline payment recorded.';
                $isErr = isset($r['error']);
                break;
            case 'mark_paid':
                $r = PaymentService::recordOffline($pdo, $bid, (float)$b['total'], (int)$admin['id'], 'marked paid');
                $msg = $r['error'] ?? 'Marked paid.';
                $isErr = isset($r['error']);
                break;
            case 'confirm':
                $msg = BookingService::setStatus($pdo, $bid, 'confirmed', 'admin', (int)$admin['id'], 'Admin confirmed') ? 'Booking confirmed.' : 'Could not confirm.';
                $b2 = $pdo->query('SELECT * FROM bookings WHERE id = ' . $bid)->fetch();
                $email = $b2['guest_email'];
                if ($b2['customer_id']) $email = $pdo->query('SELECT email FROM customers WHERE id = ' . (int)$b2['customer_id'])->fetch()['email'] ?? $email;
                if ($email && !$isErr) NotificationService::bookingEmail($pdo, 'booking-confirmed', $b2, $email, $b2['customer_id'], $b2['customer_id'] ? 'customer' : 'guest');
                break;
        }
    }
    // PRG
    header('Location: ' . url('admin/bookings.php?action=' . $action . ($action === 'view' ? '&n=' . urlencode($_GET['n'] ?? '') : '') . '&msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];

ob_start();
if ($action === 'create') {
    $customers = $pdo->query('SELECT id, name, email FROM customers WHERE status = "active" ORDER BY name LIMIT 500')->fetchAll();
    $vehicles = $pdo->query('SELECT v.id, v.make, v.model FROM vehicles v WHERE v.status = "active" ORDER BY v.id')->fetchAll();
    ?>
    <?php if ($msg): ?><div class="alert alert-err"><?= e($msg) ?></div><?php endif; ?>
    <div class="page-head"><div><p class="eyebrow">Operations</p><h1 class="font-display text-3xl mt-1">New booking</h1></div></div>
    <p class="text-sm text-[#AB8868] mt-1">Admin-created bookings support offline/manual payment afterwards. Totals are calculated server-side.</p>
    <form method="post" class="card rounded-2xl p-5 mt-3 space-y-4"><?= csrf_field() ?><input type="hidden" name="op" value="admin_create">
      <div><label class="label" for="customer_id">Customer (or guest below)</label>
        <select id="customer_id" name="customer_id" class="input"><option value="0">— Guest booking —</option><?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name'] . ' (' . $c['email'] . ')') ?></option><?php endforeach; ?></select></div>
      <div class="grid md:grid-cols-3 gap-2">
        <input name="guest_name" class="input" placeholder="Guest name"><input name="guest_email" type="email" class="input" placeholder="Guest email"><input name="guest_phone" class="input" placeholder="Guest phone">
      </div>
      <div class="grid md:grid-cols-3 gap-2">
        <select name="service_type" class="input" aria-label="Service"><option value="point_to_point">point_to_point</option><option value="airport">airport</option><option value="hourly">hourly</option></select>
        <select name="trip_type" class="input" aria-label="Trip"><option value="one_way">one_way</option><option value="round_trip">round_trip</option></select>
        <select name="airport_direction" class="input" aria-label="Direction"><option value="">— direction —</option><option value="to_airport">to_airport</option><option value="from_airport">from_airport</option></select>
      </div>
      <div class="grid md:grid-cols-2 gap-2">
        <input name="pickup_location" class="input" placeholder="Pickup" required><input name="destination_location" class="input" placeholder="Destination" required>
      </div>
      <div><label class="label" for="stops">Stops (one per line, max 6)</label><textarea id="stops" name="stops" class="input" rows="2"></textarea></div>
      <div class="grid md:grid-cols-4 gap-2">
        <input name="pickup_date" type="date" class="input" required><input name="pickup_time" type="time" class="input" required>
        <input name="passengers" type="number" min="1" value="1" class="input" aria-label="Passengers"><input name="luggage" type="number" min="0" value="0" class="input" aria-label="Luggage">
      </div>
      <div class="grid md:grid-cols-3 gap-2">
        <select name="vehicle_id" class="input" required><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['make'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select>
        <input name="mileage" type="number" step="0.1" class="input" placeholder="Mileage (optional)"><input name="hours" type="number" step="0.5" class="input" placeholder="Hours (hourly)">
      </div>
      <div class="flex gap-3 text-sm">
        <label><input type="checkbox" name="addon_meet_greet" value="1"> Meet &amp; Greet</label>
        <label><input type="checkbox" name="addon_child_seat" value="1"> Child Seat</label>
        <label><input type="checkbox" name="addon_booster_seat" value="1"> Booster Seat</label>
      </div>
      <input name="coupon_code" class="input" placeholder="Coupon code (optional)">
      <button class="btn-gold">Create booking</button></form>
    <?php
} elseif ($action === 'edit' && isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
    $st->execute([(int)$_GET['id']]);
    $eb = $st->fetch();
    $vehicles = $pdo->query('SELECT id, make, model FROM vehicles WHERE status = "active" ORDER BY id')->fetchAll();
    if (!$eb) {
        echo '<p>Not found.</p>';
    } else {
        ?>
        <?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
        <div class="page-head"><div><p class="eyebrow">Operations</p><h1 class="font-display text-3xl mt-1">Edit booking <span class="tabular"><?= e($eb['booking_number']) ?></span></h1></div><a href="<?= url('admin/bookings.php?action=view&n=' . $eb['booking_number']) ?>" class="text-sm underline">Back to view</a></div>
        <form method="post" class="card rounded-2xl p-5 mt-3 space-y-4"><?= csrf_field() ?><input type="hidden" name="op" value="admin_edit"><input type="hidden" name="booking_id" value="<?= (int)$eb['id'] ?>">
          <div class="grid md:grid-cols-2 gap-2">
            <div><label class="label" for="pickup_location">Pickup</label><input id="pickup_location" name="pickup_location" class="input" required value="<?= e($eb['pickup_location']) ?>"></div>
            <div><label class="label" for="destination_location">Destination</label><input id="destination_location" name="destination_location" class="input" required value="<?= e($eb['destination_location']) ?>"></div>
          </div>
          <div class="grid md:grid-cols-4 gap-2">
            <div><label class="label" for="pickup_date">Date</label><input id="pickup_date" type="date" name="pickup_date" class="input" required value="<?= e($eb['pickup_date']) ?>"></div>
            <div><label class="label" for="new_time">Time</label><input id="new_time" type="time" name="pickup_time" class="input" required value="<?= e(substr($eb['pickup_time'], 0, 5)) ?>"></div>
            <div><label class="label" for="passengers">Pax</label><input id="passengers" type="number" min="1" name="passengers" class="input" value="<?= (int)$eb['passengers'] ?>"></div>
            <div><label class="label" for="luggage">Bags</label><input id="luggage" type="number" min="0" name="luggage" class="input" value="<?= (int)$eb['luggage'] ?>"></div>
          </div>
          <div class="grid md:grid-cols-2 gap-2">
            <div><label class="label" for="vehicle_id">Vehicle</label><select id="vehicle_id" name="vehicle_id" class="input"><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>" <?= (int)$eb['vehicle_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['make'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select></div>
            <div><label class="label" for="trip_type">Trip</label><select id="trip_type" name="trip_type" class="input"><option value="one_way" <?= $eb['trip_type'] === 'one_way' ? 'selected' : '' ?>>one_way</option><option value="round_trip" <?= $eb['trip_type'] === 'round_trip' ? 'selected' : '' ?>>round_trip</option></select></div>
          </div>
          <button class="btn-gold">Save changes</button></form>
        <?php
    }
} elseif ($action === 'view' && !empty($_GET['n'])) {
    $st = $pdo->prepare('SELECT b.*, v.make, v.model, v.plate, c.name AS cname, c.email AS cemail, c.phone AS cphone FROM bookings b LEFT JOIN vehicles v ON v.id = b.vehicle_id LEFT JOIN customers c ON c.id = b.customer_id WHERE b.booking_number = ? LIMIT 1');
    $st->execute([$_GET['n']]);
    $b = $st->fetch();
    if (!$b) {
        echo '<p>Not found.</p>';
    } else {
        $bid = (int)$b['id'];
        $st = $pdo->prepare('SELECT * FROM booking_stops WHERE booking_id = ? ORDER BY stop_order');
        $st->execute([$bid]);
        $stops = $st->fetchAll();
        $st = $pdo->prepare('SELECT * FROM booking_charges WHERE booking_id = ? ORDER BY id');
        $st->execute([$bid]);
        $charges = $st->fetchAll();
        $st = $pdo->prepare('SELECT * FROM payments WHERE booking_id = ? ORDER BY id DESC');
        $st->execute([$bid]);
        $pays = $st->fetchAll();
        $st = $pdo->prepare('SELECT * FROM booking_status_logs WHERE booking_id = ? ORDER BY id DESC LIMIT 20');
        $st->execute([$bid]);
        $logs = $st->fetchAll();
        $st = $pdo->prepare('SELECT d.*, dr.name AS drname, dr.phone AS drphone FROM dispatches d LEFT JOIN drivers dr ON dr.id = d.driver_id WHERE d.booking_id = ? ORDER BY d.id DESC LIMIT 1');
        $st->execute([$bid]);
        $dispatch = $st->fetch() ?: null;
        $vehicles = $pdo->query('SELECT id, make, model FROM vehicles WHERE status = "active" ORDER BY id')->fetchAll();

        $pretty = static fn(?string $s): string => ucwords(str_replace('_', ' ', trim((string)$s)));
        $qty = static fn($n): string => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
        $vehicleName = trim(($b['make'] ?? '') . ' ' . ($b['model'] ?? ''));
        $custName = $b['cname'] ?: ($b['guest_name'] ?: 'Guest booking');
        $custEmail = $b['cemail'] ?: ($b['guest_email'] ?: '');
        $custPhone = $b['cphone'] ?: ($b['guest_phone'] ?: '');
        $paidTotal = 0.0;
        foreach ($pays as $p) if ($p['status'] === 'paid') $paidTotal += (float)$p['amount'];
        $addons = json_decode((string)($b['addons_json'] ?? ''), true);
        $addonLabels = ['meet_greet' => 'Meet & Greet', 'child_seat' => 'Child Seat', 'booster_seat' => 'Booster Seat'];
        $addonList = [];
        if (is_array($addons)) foreach ($addons as $k => $on) if (!empty($on)) $addonList[] = $addonLabels[$k] ?? ucwords(str_replace('_', ' ', (string)$k));
        ?>
        <?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

        <nav class="bk-crumbs" aria-label="Breadcrumb">
          <a href="<?= url('admin/bookings.php?action=list') ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> All bookings</a>
          <span aria-hidden="true">/</span><span class="tabular"><?= e($b['booking_number']) ?></span>
        </nav>

        <div class="page-head mt-2">
          <div>
            <p class="eyebrow">Booking detail</p>
            <h1 class="font-display text-3xl mt-1"><?= e($b['booking_number']) ?></h1>
            <p class="bk-head-pills"><?= status_pill($b['status']) ?><?= status_pill($b['payment_status']) ?><span class="pill pill-gold"><?= e($pretty($b['pricing_status'])) ?></span></p>
          </div>
          <div class="bk-actions">
            <a class="bk-btn bk-btn-line" href="<?= url('admin/bookings.php?action=edit&id=' . $bid) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit trip</a>
            <a class="bk-btn bk-btn-line" href="<?= url('admin/dispatch.php') ?>"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Dispatch</a>
          </div>
        </div>

        <div class="bk-strip mt-4">
          <div><span class="bk-k">Pickup</span><span class="bk-v tabular"><?= e(date('D, j M Y', strtotime((string)$b['pickup_date']))) ?></span><span class="bk-m tabular"><?= e(substr((string)$b['pickup_time'], 0, 5)) ?></span></div>
          <div><span class="bk-k">Service</span><span class="bk-v"><?= e($pretty($b['service_type'])) ?></span><span class="bk-m"><?= e($pretty($b['trip_type'])) ?><?= $b['airport_direction'] ? ' · ' . e($pretty($b['airport_direction'])) : '' ?></span></div>
          <div><span class="bk-k">Party</span><span class="bk-v"><?= (int)$b['passengers'] ?> pax</span><span class="bk-m"><?= (int)$b['luggage'] ?> bag<?= (int)$b['luggage'] === 1 ? '' : 's' ?></span></div>
          <div><span class="bk-k">Vehicle</span><span class="bk-v"><?= $vehicleName !== '' ? e($vehicleName) : 'Unassigned' ?></span><span class="bk-m"><?= e((string)($b['plate'] ?? '—')) ?></span></div>
          <div><span class="bk-k">Balance</span><span class="bk-v bk-total">$<?= money(max(0, (float)$b['total'] - $paidTotal)) ?></span><span class="bk-m tabular">$<?= money($paidTotal) ?> of $<?= money($b['total']) ?> paid</span></div>
        </div>

        <section class="bk-quick mt-3" aria-label="Quick actions">
          <span class="bk-k">Quick actions</span>
          <div class="bk-actions">
            <form method="post" class="bk-inline"><?= csrf_field() ?><input type="hidden" name="op" value="confirm"><input type="hidden" name="booking_id" value="<?= $bid ?>"><button class="bk-btn bk-btn-gold"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Confirm</button></form>
            <form method="post" class="bk-inline"><?= csrf_field() ?><input type="hidden" name="op" value="mark_paid"><input type="hidden" name="booking_id" value="<?= $bid ?>"><button class="bk-btn bk-btn-line"><i class="fa-solid fa-circle-dollar" aria-hidden="true"></i> Mark paid</button></form>
            <form method="post" class="bk-inline"><?= csrf_field() ?><input type="hidden" name="op" value="payment_link"><input type="hidden" name="booking_id" value="<?= $bid ?>"><button class="bk-btn bk-btn-line"><i class="fa-solid fa-link" aria-hidden="true"></i> Send payment link</button></form>
            <form method="post" class="bk-inline" onsubmit="return confirm('Cancel this booking?')"><?= csrf_field() ?><input type="hidden" name="op" value="cancel"><input type="hidden" name="booking_id" value="<?= $bid ?>"><button class="bk-btn bk-btn-danger"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancel</button></form>
          </div>
        </section>

        <div class="bk-grid mt-4">
          <div class="bk-stack">
            <section class="bk-sec">
              <h3>Route &amp; schedule <span class="bk-sub"><?= count($stops) ?> stop<?= count($stops) === 1 ? '' : 's' ?></span></h3>
              <ol class="bk-tl">
                <li class="bk-node"><span class="bk-tag">Pickup</span><?= e($b['pickup_location']) ?><span class="bk-m"><?= e($b['pickup_date']) ?> at <?= e(substr((string)$b['pickup_time'], 0, 5)) ?> · <?= (int)$b['passengers'] ?> pax · <?= (int)$b['luggage'] ?> bag<?= (int)$b['luggage'] === 1 ? '' : 's' ?></span></li>
                <?php foreach ($stops as $s): ?>
                <li class="bk-node is-stop"><span class="bk-tag">Stop <?= (int)$s['stop_order'] ?></span><?= e($s['location']) ?></li>
                <?php endforeach; ?>
                <li class="bk-node is-end"><span class="bk-tag">Drop</span><?= e($b['destination_location']) ?></li>
              </ol>
              <div class="bk-facts">
                <div><span class="bk-k">Mileage</span><span class="bk-v tabular"><?= $b['mileage'] !== null ? e($qty($b['mileage'])) . ' mi' : '—' ?></span></div>
                <div><span class="bk-k">Hours</span><span class="bk-v tabular"><?= $b['hours'] !== null ? e($qty($b['hours'])) . ' hr' : '—' ?></span></div>
                <div><span class="bk-k">Driver</span><span class="bk-v"><?= $dispatch ? e((string)$dispatch['drname'] ?: 'Temporary') : '—' ?></span><?php if ($dispatch && $dispatch['drphone']): ?><span class="bk-m"><?= e($dispatch['drphone']) ?></span><?php endif; ?></div>
                <div><span class="bk-k">Booked</span><span class="bk-v tabular"><?= e(substr((string)$b['created_at'], 0, 10)) ?></span><span class="bk-m"><?= e(substr((string)$b['created_at'], 11, 5)) ?></span></div>
                <div><span class="bk-k">Finalized</span><span class="bk-v tabular"><?= $b['pricing_finalized_at'] ? e(substr((string)$b['pricing_finalized_at'], 0, 10)) : '—' ?></span></div>
                <div><span class="bk-k">Add-ons</span><span class="bk-v"><?= $addonList ? e(implode(', ', $addonList)) : 'None' ?></span></div>
              </div>
            </section>

            <section class="bk-sec">
              <h3>Charges <span class="bk-sub"><?= e($pretty($b['pricing_status'])) ?> snapshot</span></h3>
              <?php if ($charges): ?>
              <div class="table-wrap"><table class="data"><thead><tr><th>Description</th><th class="hidden sm:table-cell">Source</th><th class="text-right">Qty</th><th class="text-right">Total</th></tr></thead><tbody>
                <?php foreach ($charges as $c): ?><tr><td><?= e($c['description']) ?></td><td class="hidden sm:table-cell text-xs text-[#8a8a8a]"><?= e($pretty($c['source'])) ?></td><td class="tabular text-right"><?= e($qty($c['quantity'])) ?></td><td class="tabular text-right">$<?= money($c['total']) ?></td></tr><?php endforeach; ?>
              </tbody></table></div>
              <?php else: ?><p class="bk-empty">No charge lines yet — finalize the price to generate them.</p><?php endif; ?>
              <div class="bk-totals tabular">
                <div><span>Subtotal</span><span>$<?= money($b['subtotal']) ?></span></div>
                <div><span>Discount</span><span>−$<?= money($b['discount']) ?></span></div>
                <div><span>Tax</span><span>$<?= money($b['tax']) ?></span></div>
                <div class="bk-grand"><span>Total</span><span>$<?= money($b['total']) ?></span></div>
              </div>
            </section>

            <section class="bk-sec">
              <h3>Payments <span class="bk-sub"><?= count($pays) ?> record<?= count($pays) === 1 ? '' : 's' ?></span></h3>
              <?php if ($pays): ?>
              <div class="table-wrap"><table class="data"><thead><tr><th>#</th><th>Provider</th><th>Status</th><th class="hidden md:table-cell">Paid at</th><th class="text-right">Amount</th></tr></thead><tbody>
                <?php foreach ($pays as $p): ?><tr><td class="tabular"><?= (int)$p['id'] ?></td><td><?= e($pretty($p['provider'])) ?><?php if ($p['method']): ?><span class="hidden sm:inline text-xs text-[#8a8a8a]"><?= ' · ' . e($p['method']) ?></span><?php endif; ?></td><td><?= status_pill($p['status']) ?></td><td class="tabular text-xs hidden md:table-cell"><?= e($p['paid_at'] ?: '—') ?></td><td class="tabular text-right">$<?= money($p['amount']) ?></td></tr><?php endforeach; ?>
              </tbody></table></div>
              <?php else: ?><p class="bk-empty">No payments recorded yet.</p><?php endif; ?>
            </section>

            <section class="bk-sec">
              <h3>Status history <span class="bk-sub">Latest <?= count($logs) ?></span></h3>
              <?php if ($logs): ?>
              <div class="table-wrap"><table class="data"><thead><tr><th>When</th><th class="hidden xl:table-cell">From</th><th>To</th><th class="hidden 2xl:table-cell">Actor</th><th>Note</th></tr></thead><tbody>
                <?php foreach ($logs as $l): ?><tr><td class="tabular text-xs"><span class="xl:hidden"><?= e(substr((string)$l['created_at'], 5, 5)) ?><?= $l['created_at'] ? '<br>' . e(substr((string)$l['created_at'], 11, 5)) : '' ?></span><span class="hidden xl:inline"><?= e($l['created_at']) ?></span></td><td class="hidden xl:table-cell"><?= $l['old_status'] ? status_pill((string)$l['old_status']) : '<span class="bk-empty">—</span>' ?></td><td><?= status_pill($l['new_status']) ?></td><td class="hidden 2xl:table-cell"><?= e($pretty($l['actor_type'])) ?></td><td class="text-xs"><?= e((string)($l['note'] ?? '')) ?></td></tr><?php endforeach; ?>
              </tbody></table></div>
              <?php else: ?><p class="bk-empty">No status changes recorded.</p><?php endif; ?>
            </section>
          </div>

          <aside class="bk-stack" aria-label="Booking tools">
            <section class="bk-sec">
              <h3>Customer</h3>
              <p class="bk-v"><?= e($custName) ?></p>
              <?php if ($custEmail): ?><p class="bk-m"><a class="bk-link" href="mailto:<?= e($custEmail) ?>"><?= e($custEmail) ?></a></p><?php endif; ?>
              <?php if ($custPhone): ?><p class="bk-m"><a class="bk-link" href="tel:<?= e(preg_replace('/[^\d+]/', '', $custPhone)) ?>"><?= e($custPhone) ?></a></p><?php endif; ?>
              <p class="bk-m"><?= $b['customer_id'] ? 'Registered customer' : 'Guest booking' ?></p>
            </section>

            <section class="bk-sec">
              <h3>Change status</h3>
              <form method="post" class="bk-form"><?= csrf_field() ?><input type="hidden" name="op" value="status"><input type="hidden" name="booking_id" value="<?= $bid ?>">
                <select name="new_status" class="input" aria-label="New status"><?php foreach (BookingService::VALID_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $b['status'] === $s ? 'selected' : '' ?>><?= e($pretty($s)) ?></option><?php endforeach; ?></select>
                <button class="bk-btn bk-btn-gold">Update status</button>
              </form>
            </section>

            <section class="bk-sec">
              <h3>Pricing</h3>
              <form method="post" class="bk-form"><?= csrf_field() ?><input type="hidden" name="op" value="mileage"><input type="hidden" name="booking_id" value="<?= $bid ?>">
                <p class="bk-form-head">Set mileage &amp; finalize</p>
                <select name="vehicle_id" class="input" aria-label="Vehicle"><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>" <?= (int)$b['vehicle_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['make'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select>
                <input name="mileage" type="number" step="0.1" min="0" class="input" placeholder="Mileage (mi)" value="<?= e((string)($b['mileage'] ?? '')) ?>">
                <button class="bk-btn bk-btn-gold">Finalize with mileage</button>
              </form>
              <form method="post" class="bk-form"><?= csrf_field() ?><input type="hidden" name="op" value="finalize"><input type="hidden" name="booking_id" value="<?= $bid ?>">
                <p class="bk-form-head">Full finalize (charges &amp; coupon)</p>
                <div class="bk-row2">
                  <input name="mileage" type="number" step="0.1" min="0" class="input" placeholder="Mileage" value="<?= e((string)($b['mileage'] ?? '')) ?>" aria-label="Mileage">
                  <input name="hours" type="number" step="0.5" min="0" class="input" placeholder="Hours" value="<?= e((string)($b['hours'] ?? '')) ?>" aria-label="Hours">
                </div>
                <input name="coupon_code" class="input" placeholder="Coupon code (optional)" aria-label="Coupon code">
                <div class="bk-row2">
                  <input name="mc_code[]" class="input" placeholder="Charge code" aria-label="Charge code">
                  <input name="mc_label[]" class="input" placeholder="Charge label" aria-label="Charge label">
                </div>
                <input name="mc_amount[]" type="number" step="0.01" min="0" class="input" placeholder="Charge amount" aria-label="Charge amount">
                <button class="bk-btn bk-btn-line">Finalize full price</button>
              </form>
            </section>

            <section class="bk-sec">
              <h3>Record offline payment</h3>
              <form method="post" class="bk-form"><?= csrf_field() ?><input type="hidden" name="op" value="offline_pay"><input type="hidden" name="booking_id" value="<?= $bid ?>">
                <input name="amount" type="number" step="0.01" min="0.01" class="input" placeholder="Amount ($)" required aria-label="Amount">
                <input name="note" class="input" placeholder="Note (cash, card, ref #)" aria-label="Note">
                <button class="bk-btn bk-btn-line">Record payment</button>
              </form>
            </section>
          </aside>
        </div>
        <?php
    }
} else {
    $q = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $sql = 'SELECT booking_number, pickup_date, service_type, status, payment_status, total FROM bookings WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (booking_number LIKE ? OR pickup_location LIKE ? OR destination_location LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($status !== '') {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY id DESC LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    ?>
    <?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
    <div class="page-head"><div><p class="eyebrow">Operations</p><h1 class="font-display text-3xl mt-1">Bookings</h1></div><a href="<?= url('admin/bookings.php?action=create') ?>" class="btn-gold rounded-full text-sm px-5 py-2.5 inline-flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-[#0A0A0C] text-[#D9B978] inline-flex items-center justify-center font-bold" aria-hidden="true">+</span> New booking</a></div>
    <?php
    $listCount = count($rows);
    $listPaid = 0.0;
    foreach ($rows as $lr) { if ($lr['payment_status'] === 'paid') $listPaid += (float)$lr['total']; }
    ?>
    <p class="tabular text-xs text-[#AB8868] mt-2"><?= $listCount ?> booking<?= $listCount === 1 ? '' : 's' ?><?= $status !== '' ? ' · ' . e($status) : '' ?><?= $q !== '' ? ' · “' . e($q) . '”' : '' ?> · $<?= money($listPaid) ?> paid in view</p>
    <div class="card rounded-2xl p-4 mt-3">
      <form method="get" class="flex flex-wrap items-center gap-2">
        <input type="hidden" name="action" value="list">
        <input name="q" class="input rounded-full" style="max-width:220px" placeholder="Number or route…" value="<?= e($q) ?>" aria-label="Search">
        <select name="status" class="input rounded-full" style="max-width:190px" aria-label="Status"><option value="">All statuses</option><?php foreach (BookingService::VALID_STATUSES as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
        <button class="btn-gold rounded-full text-sm px-5">Filter</button>
      </form>
      <div class="flex flex-wrap gap-1.5 mt-3">
        <?php foreach (['' => 'All', 'awaiting_pricing' => 'Awaiting pricing', 'pending_payment' => 'Unpaid', 'confirmed' => 'Confirmed', 'assigned' => 'Dispatched', 'finish' => 'Finished', 'cancelled' => 'Cancelled'] as $sv => $sl): ?>
        <a href="<?= url('admin/bookings.php?action=list' . ($sv !== '' ? '&status=' . $sv : '') . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>" class="tabular text-[11px] px-3 py-1.5 rounded-full <?= $status === $sv ? 'bg-[#D9B978] text-[#0A0A0C] font-semibold' : 'border border-[#3a3a3d] text-[#AB8868] hover:text-[#F3D4A6]' ?>"><?= e($sl) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="table-wrap card rounded-2xl mt-3"><table class="data"><thead><tr><th>Number</th><th>Date</th><th>Service</th><th>Status</th><th>Payment</th><th class="text-right">Total</th><th class="text-right">Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><a class="underline tabular font-semibold" href="<?= url('admin/bookings.php?action=view&n=' . $r['booking_number']) ?>"><?= e($r['booking_number']) ?></a></td><td class="tabular"><?= e($r['pickup_date']) ?></td><td><?= e($r['service_type']) ?></td><td><?= status_pill($r['status']) ?></td><td><?= status_pill($r['payment_status']) ?></td><td class="tabular text-right">$<?= money($r['total']) ?></td>
    <td class="text-right whitespace-nowrap"><a href="<?= url('admin/bookings.php?action=view&n=' . $r['booking_number']) ?>" class="btn-gold rounded-full text-xs font-semibold px-4 py-1.5 inline-block">View</a>
    <?php if (in_array($r['status'], ['confirmed', 'assigned', 'booking_received'], true)): ?> <a href="<?= url('admin/dispatch.php') ?>" class="rounded-full text-xs font-semibold px-4 py-1.5 inline-block border border-[#C8A96B] text-[#F3D4A6] hover:bg-[#D9B978]/10">Dispatch</a><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-xs">No bookings match. <a class="underline" href="<?= url('admin/bookings.php?action=create') ?>">Create one</a>.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php
}
$content = ob_get_clean();
$pageTitle = 'Bookings | Admin';
$navActive = 'bookings.php';
require APP_ROOT . '/views/layouts/admin.php';