<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $elig = EarningsService::payoutEligible($pdo, (int)$driver['id']);
    if (!$elig['eligible']) {
        $msg = $elig['reason'];
    } else {
        $dest = trim($_POST['destination'] ?? '');
        if ($dest === '') $msg = 'Payout destination is required.';
        else {
            $pdo->prepare('INSERT INTO driver_payouts (driver_id, amount, eligible_at, status, destination_reference, requested_at) VALUES (?,?,?,?,?,?)')->execute([(int)$driver['id'], $elig['balance'], date('Y-m-d H:i:s'), 'requested', substr($dest, 0, 255), date('Y-m-d H:i:s')]);
            audit($pdo, 'driver', (int)$driver['id'], 'payout.requested', 'payout', (int)$pdo->lastInsertId(), ['amount' => $elig['balance']]);
            $msg = 'Payout request submitted for $' . money($elig['balance']) . '.';
        }
    }
    header('Location: ' . url('driver/payout.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$elig = EarningsService::payoutEligible($pdo, (int)$driver['id']);
$st = $pdo->prepare('SELECT * FROM driver_payouts WHERE driver_id = ? ORDER BY id DESC LIMIT 50');
$st->execute([$driver['id']]);
$rows = $st->fetchAll();
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl text-[#F3D4A6]">Payout</h1>
<div class="ride-card mt-4"><div class="label">Eligibility (once every 7 days)</div>
<?php if ($elig['eligible']): ?><p>Available: <strong>$<?= money($elig['balance']) ?></strong></p>
<form method="post" data-once class="mt-2 space-y-2"><?= csrf_field() ?>
<label class="label" for="destination">Payout destination (bank/Zelle handle — never full secrets in logs)</label>
<input id="destination" name="destination" class="input" required placeholder="e.g. Chase ••••1234">
<button class="btn-gold touch-btn">Request payout</button></form>
<?php else: ?><p class="text-sm"><?= e($elig['reason']) ?></p><?php endif; ?></div>
<h2 class="font-display text-xl mt-4">History</h2>
<?php foreach ($rows as $r): ?><div class="ride-card"><p><strong>$<?= money($r['amount']) ?></strong> — <?= e($r['status']) ?> · <?= e($r['requested_at']) ?></p></div><?php endforeach; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'Payout';
require APP_ROOT . '/views/layouts/driver.php';
