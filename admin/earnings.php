<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
require_role('admin');
$rows = $pdo->query('SELECT e.*, d.name AS dname, b.booking_number FROM driver_earnings e JOIN drivers d ON d.id = e.driver_id JOIN bookings b ON b.id = e.booking_id ORDER BY e.id DESC LIMIT 200')->fetchAll();
$tot = $pdo->query('SELECT COALESCE(SUM(gross_amount),0) g, COALESCE(SUM(company_amount),0) c, COALESCE(SUM(driver_amount),0) d FROM driver_earnings')->fetch();
ob_start();
?>
<h1 class="font-display text-3xl">Earnings Ledger (20/80)</h1>
<div class="grid md:grid-cols-3 gap-4 mt-4">
<div class="card p-5"><div class="label">Gross</div><div class="font-display text-3xl">$<?= money($tot['g']) ?></div></div>
<div class="card p-5"><div class="label">Company (20%)</div><div class="font-display text-3xl">$<?= money($tot['c']) ?></div></div>
<div class="card p-5"><div class="label">Drivers (80%)</div><div class="font-display text-3xl">$<?= money($tot['d']) ?></div></div>
</div>
<div class="table-wrap card mt-4"><table class="data"><thead><tr><th>Booking</th><th>Driver</th><th>Gross</th><th>Company</th><th>Driver</th><th>Status</th><th>Earned</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['booking_number']) ?></td><td><?= e($r['dname']) ?></td><td>$<?= money($r['gross_amount']) ?></td><td>$<?= money($r['company_amount']) ?></td><td>$<?= money($r['driver_amount']) ?></td><td><?= e($r['status']) ?></td><td><?= e($r['earned_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Earnings | Admin';
$navActive = 'earnings.php';
require APP_ROOT . '/views/layouts/admin.php';
