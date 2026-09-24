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
    <h1 class="font-display text-3xl">New Booking (admin)</h1>
    <p class="text-sm">Admin-created bookings support offline/manual payment afterwards. Totals are calculated server-side.</p>
    <form method="post" class="card p-4 mt-3 space-y-3"><?= csrf_field() ?><input type="hidden" name="op" value="admin_create">
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
        <h1 class="font-display text-3xl">Edit Booking <?= e($eb['booking_number']) ?></h1>
        <form method="post" class="card p-4 mt-3 space-y-3"><?= csrf_field() ?><input type="hidden" name="op" value="admin_edit"><input type="hidden" name="booking_id" value="<?= (int)$eb['id'] ?>">
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
    $st = $pdo->prepare('SELECT b.*, v.make, v.model, c.name AS cname, c.email AS cemail FROM bookings b LEFT JOIN vehicles v ON v.id = b.vehicle_id LEFT JOIN customers c ON c.id = b.customer_id WHERE b.booking_number = ? LIMIT 1');
    $st->execute([$_GET['n']]);
    $b = $st->fetch();
    if (!$b) {
        echo '<p>Not found.</p>';
    } else {
        $st = $pdo->prepare('SELECT * FROM booking_stops WHERE booking_id = ? ORDER BY stop_order');
        $st->execute([$b['id']]);
        $stops = $st->fetchAll();
        $st = $pdo->prepare('SELECT * FROM booking_charges WHERE booking_id = ?');
        $st->execute([$b['id']]);
        $charges = $st->fetchAll();
        $st = $pdo->prepare('SELECT * FROM payments WHERE booking_id = ? ORDER BY id DESC');
        $st->execute([$b['id']]);
        $pays = $st->fetchAll();
        $st = $pdo->prepare('SELECT * FROM booking_status_logs WHERE booking_id = ? ORDER BY id DESC LIMIT 20');
        $st->execute([$b['id']]);
        $logs = $st->fetchAll();
        $vehicles = $pdo->query('SELECT id, make, model FROM vehicles WHERE status = "active" ORDER BY id')->fetchAll();
        ?>
        <?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>
        <h1 class="font-display text-3xl">Booking <?= e($b['booking_number']) ?> <a href="<?= url('admin/bookings.php?action=edit&id=' . (int)$b['id']) ?>" class="btn-gold text-base align-middle">Edit</a></h1>
        <div class="card p-5 mt-3 text-sm space-y-1">
          <p><strong>Customer:</strong> <?= e($b['cname'] ?? $b['guest_name'] ?? '—') ?> (<?= e($b['cemail'] ?? $b['guest_email'] ?? '') ?>)</p>
          <p><strong>Service:</strong> <?= e($b['service_type']) ?> · <?= e($b['trip_type']) ?> · <?= e((string)($b['airport_direction'] ?? '')) ?></p>
          <p><strong>Route:</strong> <?= e($b['pickup_location']) ?> → <?= e($b['destination_location']) ?></p>
          <?php foreach ($stops as $s): ?><p><strong>Stop <?= (int)$s['stop_order'] ?>:</strong> <?= e($s['location']) ?></p><?php endforeach; ?>
          <p><strong>Pickup:</strong> <?= e($b['pickup_date']) ?> <?= e($b['pickup_time']) ?> · <?= (int)$b['passengers'] ?> pax · <?= (int)$b['luggage'] ?> bags</p>
          <p><strong>Vehicle:</strong> <?= e(trim(($b['make'] ?? '') . ' ' . ($b['model'] ?? ''))) ?> · mileage <?= e((string)($b['mileage'] ?? '—')) ?> · hours <?= e((string)($b['hours'] ?? '—')) ?></p>
          <p><strong>Status:</strong> <?= e($b['status']) ?> · <strong>Payment:</strong> <?= e($b['payment_status']) ?></p>
          <h3 class="label mt-2">Charges</h3>
          <?php foreach ($charges as $c): ?><p><?= e($c['description']) ?> — $<?= money($c['total']) ?></p><?php endforeach; ?>
          <p><strong>Total $<?= money($b['total']) ?></strong> (sub $<?= money($b['subtotal']) ?> − disc $<?= money($b['discount']) ?> + tax $<?= money($b['tax']) ?>)</p>
          <h3 class="label mt-2">Payments</h3>
          <?php foreach ($pays as $p): ?><p>#<?= (int)$p['id'] ?> <?= e($p['provider']) ?> $<?= money($p['amount']) ?> — <?= e($p['status']) ?> <?= e((string)($p['provider_payment_id'] ?? '')) ?></p><?php endforeach; ?>
        </div>
        <div class="grid md:grid-cols-2 gap-4 mt-4">
          <form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="mileage"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
            <h3 class="label">Set mileage + finalize</h3>
            <select name="vehicle_id" class="input"><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>" <?= (int)$b['vehicle_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['make'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select>
            <input name="mileage" type="number" step="0.1" class="input" placeholder="Mileage" value="<?= e((string)($b['mileage'] ?? '')) ?>">
            <button class="btn-gold">Set mileage</button></form>
          <form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="finalize"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
            <h3 class="label">Finalize price (charges + coupon)</h3>
            <input name="mileage" type="number" step="0.1" class="input" placeholder="Mileage" value="<?= e((string)($b['mileage'] ?? '')) ?>">
            <input name="hours" type="number" step="0.5" class="input" placeholder="Hours" value="<?= e((string)($b['hours'] ?? '')) ?>">
            <input name="coupon_code" class="input" placeholder="Coupon code">
            <div class="grid grid-cols-3 gap-1"><input name="mc_code[]" class="input" placeholder="code (toll)"><input name="mc_label[]" class="input" placeholder="label"><input name="mc_amount[]" type="number" step="0.01" class="input" placeholder="amount"></div>
            <button class="btn-gold">Finalize price</button></form>
          <form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="payment_link"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
            <h3 class="label">Payment link</h3><button class="btn-gold">Generate + email link</button></form>
          <form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="offline_pay"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
            <h3 class="label">Offline payment</h3><input name="amount" type="number" step="0.01" class="input" placeholder="Amount" required><input name="note" class="input" placeholder="Note"><button class="btn-gold">Record offline</button></form>
          <form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="status"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
            <h3 class="label">Change status</h3>
            <select name="new_status" class="input"><?php foreach (BookingService::VALID_STATUSES as $s): ?><option><?= e($s) ?></option><?php endforeach; ?></select>
            <button class="btn-gold">Update status</button></form>
          <form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="confirm"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
            <button class="btn-gold">Confirm booking</button></form>
          <form method="post" class="card p-4 space-y-2" onsubmit="return confirm('Cancel this booking?')"><?= csrf_field() ?><input type="hidden" name="op" value="cancel"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
            <button class="btn-danger-outline">Cancel booking</button></form>
          <form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="mark_paid"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
            <button class="btn-gold">Mark paid</button></form>
        </div>
        <h3 class="font-display text-xl mt-4">Status history</h3>
        <div class="table-wrap card mt-2"><table class="data"><thead><tr><th>When</th><th>From</th><th>To</th><th>Actor</th><th>Note</th></tr></thead><tbody>
        <?php foreach ($logs as $l): ?><tr><td><?= e($l['created_at']) ?></td><td><?= e((string)$l['old_status']) ?></td><td><?= e($l['new_status']) ?></td><td><?= e($l['actor_type']) ?></td><td><?= e((string)($l['note'] ?? '')) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
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
    <h1 class="font-display text-3xl">Bookings <a href="<?= url('admin/bookings.php?action=create') ?>" class="btn-gold text-base align-middle">+ New booking</a></h1>
    <form method="get" class="flex gap-2 mt-3">
      <input type="hidden" name="action" value="list">
      <input name="q" class="input" style="max-width:240px" placeholder="Search" value="<?= e($q) ?>" aria-label="Search">
      <select name="status" class="input" style="max-width:200px" aria-label="Status"><option value="">All</option><?php foreach (BookingService::VALID_STATUSES as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
      <button class="btn-gold">Filter</button>
    </form>
    <div class="table-wrap card mt-3"><table class="data"><thead><tr><th>Number</th><th>Date</th><th>Service</th><th>Status</th><th>Payment</th><th>Total</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><a class="underline" href="<?= url('admin/bookings.php?action=view&n=' . $r['booking_number']) ?>"><?= e($r['booking_number']) ?></a></td><td><?= e($r['pickup_date']) ?></td><td><?= e($r['service_type']) ?></td><td><?= e($r['status']) ?></td><td><?= e($r['payment_status']) ?></td><td>$<?= money($r['total']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php
}
$content = ob_get_clean();
$pageTitle = 'Bookings | Admin';
$navActive = 'bookings.php';
require APP_ROOT . '/views/layouts/admin.php';
