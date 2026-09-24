<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$stats = [];
foreach ([
    'today' => 'SELECT COUNT(*) c FROM bookings WHERE pickup_date = CURDATE()',
    'awaiting' => 'SELECT COUNT(*) c FROM bookings WHERE status = "awaiting_pricing"',
    'unpaid' => 'SELECT COUNT(*) c FROM bookings WHERE payment_status = "pending" AND status NOT IN ("cancelled","refunded")',
    'drivers_pending' => 'SELECT COUNT(*) c FROM drivers WHERE status = "pending"',
    'payouts_pending' => 'SELECT COUNT(*) c FROM driver_payouts WHERE status = "requested"',
    'revenue' => 'SELECT COALESCE(SUM(total),0) c FROM bookings WHERE payment_status = "paid" AND status != "refunded"',
] as $k => $sql) {
    $stats[$k] = $pdo->query($sql)->fetch()['c'];
}
$recent = $pdo->query('SELECT booking_number, pickup_date, status, payment_status, total FROM bookings ORDER BY id DESC LIMIT 10')->fetchAll();
ob_start();
?>
<h1 class="font-display text-3xl">Dashboard</h1>
<div class="grid md:grid-cols-3 gap-4 mt-4">
  <div class="card p-5"><div class="label">Today's pickups</div><div class="font-display text-3xl"><?= (int)$stats['today'] ?></div></div>
  <div class="card p-5"><div class="label">Awaiting pricing</div><div class="font-display text-3xl"><?= (int)$stats['awaiting'] ?></div><a class="underline text-sm" href="<?= url('admin/bookings.php?status=awaiting_pricing') ?>">Review</a></div>
  <div class="card p-5"><div class="label">Unpaid bookings</div><div class="font-display text-3xl"><?= (int)$stats['unpaid'] ?></div></div>
  <div class="card p-5"><div class="label">Drivers pending activation</div><div class="font-display text-3xl"><?= (int)$stats['drivers_pending'] ?></div><a class="underline text-sm" href="<?= url('admin/drivers.php?status=pending') ?>">Review</a></div>
  <div class="card p-5"><div class="label">Payout requests</div><div class="font-display text-3xl"><?= (int)$stats['payouts_pending'] ?></div><a class="underline text-sm" href="<?= url('admin/payouts.php') ?>">Review</a></div>
  <div class="card p-5"><div class="label">Revenue (paid)</div><div class="font-display text-3xl">$<?= money($stats['revenue']) ?></div></div>
</div>
<h2 class="font-display text-2xl mt-6">Recent bookings</h2>
<div class="table-wrap card mt-2"><table class="data"><thead><tr><th>Number</th><th>Date</th><th>Status</th><th>Payment</th><th>Total</th></tr></thead><tbody>
<?php foreach ($recent as $r): ?><tr><td><a class="underline" href="<?= url('admin/bookings.php?action=view&n=' . $r['booking_number']) ?>"><?= e($r['booking_number']) ?></a></td><td><?= e($r['pickup_date']) ?></td><td><?= e($r['status']) ?></td><td><?= e($r['payment_status']) ?></td><td>$<?= money($r['total']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Dashboard | Admin';
$navActive = 'dashboard.php';
require APP_ROOT . '/views/layouts/admin.php';
