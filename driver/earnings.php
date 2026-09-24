<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
$st = $pdo->prepare('SELECT e.*, b.booking_number FROM driver_earnings e JOIN bookings b ON b.id = e.booking_id WHERE e.driver_id = ? ORDER BY e.id DESC LIMIT 200');
$st->execute([$driver['id']]);
$rows = $st->fetchAll();
$tot = $pdo->prepare('SELECT COALESCE(SUM(driver_amount),0) t, COALESCE(SUM(CASE WHEN status="unpaid" THEN driver_amount ELSE 0 END),0) u FROM driver_earnings WHERE driver_id = ?');
$tot->execute([$driver['id']]);
$t = $tot->fetch();
ob_start();
?>
<h1 class="font-display text-3xl text-[#F3D4A6]">Earnings</h1>
<div class="grid grid-cols-2 gap-3 mt-4">
<div class="ride-card"><div class="label">Total (80%)</div><div class="font-display text-2xl">$<?= money($t['t']) ?></div></div>
<div class="ride-card"><div class="label">Unpaid</div><div class="font-display text-2xl">$<?= money($t['u']) ?></div></div>
</div>
<?php foreach ($rows as $r): ?><div class="ride-card"><h2 class="font-display text-xl"><?= e($r['booking_number']) ?> — $<?= money($r['driver_amount']) ?></h2><p class="text-sm">Gross $<?= money($r['gross_amount']) ?> · <?= e($r['status']) ?> · <?= e($r['earned_at']) ?></p></div><?php endforeach; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'Earnings';
require APP_ROOT . '/views/layouts/driver.php';
