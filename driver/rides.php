<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $r = DispatchService::driverUpdateStatus($pdo, (int)$_POST['booking_id'], (int)$driver['id'], $_POST['new_status'] ?? '');
    $msg = $r['error'] ?? 'Status updated.';
    if (!isset($r['error'])) {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
        $st->execute([(int)$_POST['booking_id']]);
        $b = $st->fetch();
        $email = $b['guest_email'];
        if ($b['customer_id']) $email = $pdo->query('SELECT email FROM customers WHERE id = ' . (int)$b['customer_id'])->fetch()['email'] ?? $email;
        if ($email) NotificationService::bookingEmail($pdo, 'driver-status-update', $b, $email, $b['customer_id'], $b['customer_id'] ? 'customer' : 'guest', ['html' => '<p>Trip update for booking <strong>' . e($b['booking_number']) . '</strong>: ' . e($_POST['new_status']) . '.</p>']);
    }
    header('Location: ' . url('driver/rides.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$st = $pdo->prepare('SELECT b.* FROM bookings b JOIN dispatches d ON d.booking_id = b.id WHERE d.driver_id = ? AND d.status IN ("assigned","reassigned") ORDER BY b.pickup_date DESC LIMIT 100');
$st->execute([$driver['id']]);
$rides = $st->fetchAll();
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl text-[#F3D4A6]">My Rides</h1>
<?php foreach ($rides as $r): ?>
<?php
$stops = $pdo->prepare('SELECT location FROM booking_stops WHERE booking_id = ? ORDER BY stop_order');
$stops->execute([$r['id']]);
$stops = $stops->fetchAll();
$veh = $r['vehicle_id'] ? $pdo->query('SELECT make, model FROM vehicles WHERE id = ' . (int)$r['vehicle_id'])->fetch() : null;
?>
<div class="ride-card">
  <span class="status-pill"><?= e($r['status']) ?></span>
  <h2 class="font-display text-2xl mt-2"><?= e($r['booking_number']) ?></h2>
  <p class="text-sm"><strong>Pickup:</strong> <?= e($r['pickup_location']) ?> · <?= e($r['pickup_date']) ?> <?= e(substr($r['pickup_time'], 0, 5)) ?></p>
  <p class="text-sm"><strong>Drop:</strong> <?= e($r['destination_location']) ?></p>
  <?php foreach ($stops as $s): ?><p class="text-sm"><strong>Stop:</strong> <?= e($s['location']) ?></p><?php endforeach; ?>
  <p class="text-sm"><strong>Pax:</strong> <?= (int)$r['passengers'] ?> · <strong>Bags:</strong> <?= (int)$r['luggage'] ?> · <strong>Vehicle:</strong> <?= e(trim(($veh['make'] ?? '') . ' ' . ($veh['model'] ?? '')) ?: '—') ?></p>
  <form method="post" data-once class="mt-2"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int)$r['id'] ?>">
    <label class="label" for="ns-<?= (int)$r['id'] ?>">Update status</label>
    <select id="ns-<?= (int)$r['id'] ?>" name="new_status" class="input">
      <?php foreach (DispatchService::DRIVER_FLOW as $s): ?><option <?= $r['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
    </select>
    <button class="btn-gold touch-btn primary">Update status</button></form>
</div>
<?php endforeach; ?>
<?php if (!$rides): ?><p class="text-sm mt-4">No assigned rides yet.</p><?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'My Rides';
require APP_ROOT . '/views/layouts/driver.php';
