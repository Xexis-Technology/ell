<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$user = require_role('customer');
$n = $_GET['n'] ?? '';
$st = $pdo->prepare('SELECT b.*, v.make, v.model FROM bookings b LEFT JOIN vehicles v ON v.id = b.vehicle_id WHERE b.booking_number = ? AND b.customer_id = ? LIMIT 1');
$st->execute([$n, $user['id']]);
$b = $st->fetch();
if (!$b) {
    http_response_code(404);
    require APP_ROOT . '/views/errors/404.php';
    exit;
}
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pickup_time') {
    require_csrf();
    $r = BookingService::updatePickupTime($pdo, (int)$b['id'], $_POST['new_date'] ?? '', $_POST['new_time'] ?? '', 'customer', (int)$user['id']);
    if (isset($r['ok'])) {
        $b['pickup_date'] = $_POST['new_date'];
        $b['pickup_time'] = $_POST['new_time'];
        $ownerEmail = $pdo->query('SELECT email FROM customers WHERE id = ' . (int)$user['id'])->fetch()['email'];
        NotificationService::bookingEmail($pdo, 'pickup-time-updated', $b, $ownerEmail, (int)$user['id'], 'customer');
        $msg = 'Pickup time updated.';
    } else {
        $msg = $r['error'];
    }
}
$st = $pdo->prepare('SELECT * FROM booking_stops WHERE booking_id = ? ORDER BY stop_order');
$st->execute([$b['id']]);
$stops = $st->fetchAll();
$st = $pdo->prepare('SELECT * FROM booking_charges WHERE booking_id = ?');
$st->execute([$b['id']]);
$charges = $st->fetchAll();
ob_start();
?>
<h1 class="font-display text-4xl text-[#F9F9F9]">Booking <?= e($b['booking_number']) ?></h1>
<?php if ($msg): ?><div class="alert alert-ok mt-4"><?= e($msg) ?></div><?php endif; ?>
<div class="card p-6 mt-4 text-sm space-y-2">
  <p><strong>Service:</strong> <?= e($b['service_type']) ?> · <?= e($b['trip_type']) ?></p>
  <p><strong>Pickup:</strong> <?= e($b['pickup_location']) ?> on <?= e($b['pickup_date']) ?> at <?= e(substr($b['pickup_time'], 0, 5)) ?></p>
  <p><strong>Destination:</strong> <?= e($b['destination_location']) ?></p>
  <?php foreach ($stops as $s): ?><p><strong>Stop <?= (int)$s['stop_order'] ?>:</strong> <?= e($s['location']) ?></p><?php endforeach; ?>
  <p><strong>Vehicle:</strong> <?= e(trim(($b['make'] ?? '') . ' ' . ($b['model'] ?? ''))) ?> · <?= (int)$b['passengers'] ?> pax · <?= (int)$b['luggage'] ?> bags</p>
  <p><strong>Status:</strong> <?= e($b['status']) ?> · <strong>Payment:</strong> <?= e($b['payment_status']) ?></p>
  <h2 class="label mt-3">Charges</h2>
  <?php foreach ($charges as $c): ?><p><?= e($c['description']) ?> — $<?= money($c['total']) ?></p><?php endforeach; ?>
  <p><strong>Subtotal</strong> $<?= money($b['subtotal']) ?> · <strong>Discount</strong> $<?= money($b['discount']) ?> · <strong>Tax</strong> $<?= money($b['tax']) ?> · <strong>Total $<?= money($b['total']) ?></strong></p>
  <p><a class="underline" href="<?= url('account/invoice.php?booking=' . $b['booking_number']) ?>">View invoice / PDF</a></p>
</div>
<form method="post" class="card p-6 mt-4 space-y-3" x-data="pickupEditor()"><?= csrf_field() ?>
  <input type="hidden" name="action" value="pickup_time">
  <h2 class="font-display text-xl text-[#F3D4A6]">Change pickup time (until 2h before)</h2>
  <div class="grid md:grid-cols-2 gap-3">
    <div><label class="label" for="new_date">New date</label><input id="new_date" type="date" name="new_date" class="input" required></div>
    <div><label class="label" for="new_time">New time</label><input id="new_time" type="time" name="new_time" class="input" required></div>
  </div>
  <button class="btn-gold">Update pickup time</button>
</form>
<?php
$content = ob_get_clean();
$pageTitle = 'Booking | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/customer.php';
