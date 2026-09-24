<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
$st = $pdo->prepare('SELECT b.booking_number, b.pickup_date, b.pickup_time, b.pickup_location, b.destination_location, b.status, b.id FROM bookings b JOIN dispatches d ON d.booking_id = b.id WHERE d.driver_id = ? AND d.status IN ("assigned","reassigned") ORDER BY b.pickup_date DESC, b.pickup_time DESC LIMIT 50');
$st->execute([$driver['id']]);
$rides = $st->fetchAll();
$active = null;
$next = null;
foreach ($rides as $r) {
    if (in_array($r['status'], ['assigned','on_the_way','arrived','at_pickup_location','on_board'], true) && !$active) $active = $r;
}
foreach (array_reverse($rides) as $r) {
    if (!in_array($r['status'], ['finish','cancelled','refunded'], true) && strtotime($r['pickup_date'] . ' ' . $r['pickup_time']) >= time() && !$next) $next = $r;
}
$st = $pdo->prepare('SELECT COUNT(*) c, COALESCE(SUM(driver_amount),0) t FROM driver_earnings WHERE driver_id = ?');
$st->execute([$driver['id']]);
$earn = $st->fetch();
$elig = EarningsService::payoutEligible($pdo, (int)$driver['id']);
$st = $pdo->prepare('SELECT status, requested_at FROM driver_payouts WHERE driver_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$driver['id']]);
$lastPayout = $st->fetch();
ob_start();
?>
<h1 class="font-display text-3xl text-[#F3D4A6]">Hello, <?= e($driver['name']) ?></h1>
<?php if ($active): ?><div class="ride-card"><span class="status-pill"><?= e($active['status']) ?></span><h2 class="font-display text-2xl mt-2">Active: <?= e($active['booking_number']) ?></h2><p class="text-sm"><?= e($active['pickup_location']) ?> → <?= e($active['destination_location']) ?></p><a class="btn-gold touch-btn" href="<?= url('driver/rides.php') ?>">Open ride</a></div><?php endif; ?>
<?php if ($next && (!$active || $next['id'] !== $active['id'])): ?><div class="ride-card"><h2 class="font-display text-xl">Next: <?= e($next['booking_number']) ?></h2><p class="text-sm"><?= e($next['pickup_date']) ?> <?= e(substr($next['pickup_time'], 0, 5)) ?> · <?= e($next['pickup_location']) ?></p></div><?php endif; ?>
<div class="grid grid-cols-2 gap-3 mt-4">
<div class="ride-card"><div class="label">Completed</div><div class="font-display text-2xl"><?= (int)$earn['c'] ?></div></div>
<div class="ride-card"><div class="label">Earnings</div><div class="font-display text-2xl">$<?= money($earn['t']) ?></div></div>
</div>
<div class="ride-card"><div class="label">Payout</div>
<?php if ($elig['eligible']): ?><p class="text-sm">Eligible: $<?= money($elig['balance']) ?></p><a class="btn-gold touch-btn" href="<?= url('driver/payout.php') ?>">Request payout</a>
<?php else: ?><p class="text-sm"><?= e($elig['reason']) ?></p><?php endif; ?>
<?php if ($lastPayout): ?><p class="text-xs mt-1">Last request: <?= e($lastPayout['status']) ?> · <?= e($lastPayout['requested_at']) ?></p><?php endif; ?></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Driver Dashboard';
require APP_ROOT . '/views/layouts/driver.php';
