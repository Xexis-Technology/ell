<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$user = require_role('customer');
$st = $pdo->prepare('SELECT b.*, v.make, v.model FROM bookings b LEFT JOIN vehicles v ON v.id = b.vehicle_id WHERE b.customer_id = ? ORDER BY b.pickup_date DESC, b.pickup_time DESC LIMIT 20');
$st->execute([$user['id']]);
$bookings = $st->fetchAll();
$upcoming = null;
foreach (array_reverse($bookings) as $b) {
    if (!in_array($b['status'], ['finish','cancelled','refunded'], true) && strtotime($b['pickup_date'] . ' ' . $b['pickup_time']) >= time()) {
        $upcoming = $b;
        break;
    }
}
ob_start();
?>
<h1 class="font-display text-4xl text-[#F9F9F9]">Welcome, <?= e($user['name']) ?></h1>
<div class="grid md:grid-cols-3 gap-4 mt-6">
  <div class="card p-5"><h2 class="label">Upcoming ride</h2><?php if ($upcoming): ?><p class="font-display text-xl text-[#F3D4A6]"><?= e($upcoming['booking_number']) ?></p><p class="text-sm"><?= e($upcoming['pickup_date']) ?> · <?= e($upcoming['payment_status']) ?></p><a class="underline text-sm" href="<?= url('account/booking-view.php?n=' . $upcoming['booking_number']) ?>">View</a><?php else: ?><p class="text-sm">No upcoming rides. <a class="underline" href="<?= url('services/booking.php') ?>">Book one</a>.</p><?php endif; ?></div>
  <div class="card p-5"><h2 class="label">Recent bookings</h2><p class="font-display text-xl text-[#F3D4A6]"><?= count($bookings) ?></p><a class="underline text-sm" href="<?= url('account/bookings.php') ?>">History</a></div>
  <div class="card p-5"><h2 class="label">Shortcuts</h2><p class="text-sm space-x-2"><a class="underline" href="<?= url('account/profile.php') ?>">Profile</a><a class="underline" href="<?= url('account/password.php') ?>">Password</a><a class="underline" href="<?= url('account/notifications.php') ?>">Notifications</a></p></div>
</div>
<h2 class="font-display text-2xl mt-8">Recent bookings</h2>
<div class="table-wrap card mt-3"><table class="data"><thead><tr><th>Number</th><th>Date</th><th>Route</th><th>Status</th><th>Total</th><th></th></tr></thead><tbody>
<?php foreach (array_slice($bookings, 0, 5) as $b): ?><tr><td><?= e($b['booking_number']) ?></td><td><?= e($b['pickup_date']) ?></td><td><?= e(mb_strimwidth($b['pickup_location'], 0, 30, '…')) ?> → <?= e(mb_strimwidth($b['destination_location'], 0, 30, '…')) ?></td><td><?= e($b['status']) ?></td><td>$<?= money($b['total']) ?></td><td><a class="underline" href="<?= url('account/booking-view.php?n=' . $b['booking_number']) ?>">View</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Dashboard | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/customer.php';
