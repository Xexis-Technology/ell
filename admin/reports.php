<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
require_role('admin');
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$st = $pdo->prepare('SELECT status, COUNT(*) c, COALESCE(SUM(total),0) t FROM bookings WHERE pickup_date BETWEEN ? AND ? GROUP BY status');
$st->execute([$from, $to]);
$byStatus = $st->fetchAll();
$st = $pdo->prepare('SELECT COALESCE(SUM(total),0) t, COUNT(*) c FROM bookings WHERE pickup_date BETWEEN ? AND ? AND payment_status = "paid"');
$st->execute([$from, $to]);
$rev = $st->fetch();
$st = $pdo->prepare('SELECT service_type, COUNT(*) c, COALESCE(SUM(total),0) t FROM bookings WHERE pickup_date BETWEEN ? AND ? GROUP BY service_type');
$st->execute([$from, $to]);
$byService = $st->fetchAll();
$st = $pdo->prepare('SELECT d.name, COUNT(*) c, COALESCE(SUM(e.driver_amount),0) t FROM driver_earnings e JOIN drivers d ON d.id = e.driver_id WHERE DATE(e.earned_at) BETWEEN ? AND ? GROUP BY d.id');
$st->execute([$from, $to]);
$byDriver = $st->fetchAll();
ob_start();
?>
<h1 class="font-display text-3xl">Reports (basic operational/financial)</h1>
<form method="get" class="flex gap-2 mt-3"><input type="date" name="from" class="input" style="max-width:180px" value="<?= e($from) ?>"><input type="date" name="to" class="input" style="max-width:180px" value="<?= e($to) ?>"><button class="btn-gold">Run</button></form>
<div class="grid md:grid-cols-2 gap-4 mt-4">
<div class="card p-5"><div class="label">Revenue (paid, <?= e($from) ?> → <?= e($to) ?>)</div><div class="font-display text-3xl">$<?= money($rev['t']) ?> (<?= (int)$rev['c'] ?> rides)</div></div>
<div class="card p-5"><div class="label">By service</div><?php foreach ($byService as $s): ?><p class="text-sm"><?= e($s['service_type']) ?>: <?= (int)$s['c'] ?> rides · $<?= money($s['t']) ?></p><?php endforeach; ?></div>
</div>
<div class="table-wrap card mt-4"><table class="data"><thead><tr><th>Status</th><th>Count</th><th>Total</th></tr></thead><tbody>
<?php foreach ($byStatus as $s): ?><tr><td><?= e($s['status']) ?></td><td><?= (int)$s['c'] ?></td><td>$<?= money($s['t']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<h2 class="font-display text-2xl mt-4">Driver earnings in range</h2>
<div class="table-wrap card mt-2"><table class="data"><thead><tr><th>Driver</th><th>Rides</th><th>Driver share</th></tr></thead><tbody>
<?php foreach ($byDriver as $d): ?><tr><td><?= e($d['name']) ?></td><td><?= (int)$d['c'] ?></td><td>$<?= money($d['t']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Reports | Admin';
$navActive = 'reports.php';
require APP_ROOT . '/views/layouts/admin.php';
