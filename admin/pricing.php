<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = $_POST['op'] ?? '';
    if ($op === 'mode') {
        $mode = ($_POST['pricing_mode'] ?? '') === 'hourly' ? 'hourly' : 'per_mile';
        $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')->execute(['pricing_mode', $mode]);
        audit($pdo, 'admin', (int)$admin['id'], 'pricing.mode', null, null, ['mode' => $mode]);
        $msg = 'Pricing mode set to ' . $mode . '.';
    } elseif ($op === 'tax') {
        $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')->execute(['tax_percent', (string)max(0, (float)($_POST['tax_percent'] ?? 0))]);
        $msg = 'Tax updated.';
    } elseif ($op === 'charge') {
        $pdo->prepare('INSERT INTO additional_charge_types (code, name, calculation_type, amount, active) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name), calculation_type=VALUES(calculation_type), amount=VALUES(amount), active=1')->execute([preg_replace('/[^a-z_]/', '', strtolower($_POST['code'] ?? '')), trim($_POST['name'] ?? ''), in_array($_POST['calculation_type'] ?? '', ['flat','per_unit','per_mile','per_hour'], true) ? $_POST['calculation_type'] : 'flat', (float)($_POST['amount'] ?? 0)]);
        $msg = 'Charge saved.';
    } elseif ($op === 'coupon') {
        $dt = fn($v) => $v !== '' ? str_replace('T', ' ', $v) . ':00' : null;
        $pdo->prepare('INSERT INTO coupons (code, type, value, minimum_subtotal, usage_limit, customer_limit, starts_at, expires_at, active) VALUES (?,?,?,?,?,?,?,?,1)')->execute([strtoupper(trim($_POST['code'] ?? '')), $_POST['type'] === 'fixed' ? 'fixed' : 'percent', (float)($_POST['value'] ?? 0), (float)($_POST['minimum_subtotal'] ?? 0), $_POST['usage_limit'] !== '' ? (int)$_POST['usage_limit'] : null, $_POST['customer_limit'] !== '' ? (int)$_POST['customer_limit'] : null, $dt(trim($_POST['starts_at'] ?? '')), $dt(trim($_POST['expires_at'] ?? ''))]);
        $msg = 'Coupon created.';
    } elseif ($op === 'coupon_toggle') {
        $pdo->prepare('UPDATE coupons SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['id']]);
        $msg = 'Coupon toggled.';
    } elseif ($op === 'waiting') {
        $pdo->prepare('INSERT INTO waiting_rules (category, free_minutes, charge_interval_minutes, charge_per_interval, active) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE free_minutes=VALUES(free_minutes), charge_interval_minutes=VALUES(charge_interval_minutes), charge_per_interval=VALUES(charge_per_interval)')->execute([$_POST['category'], (int)$_POST['free_minutes'], (int)$_POST['charge_interval_minutes'], (float)$_POST['charge_per_interval']]);
        $msg = 'Waiting rule saved.';
    } elseif ($op === 'waiting_close') {
        $r = WaitingService::closeSession($pdo, (int)$_POST['booking_id'], $_POST['category'] ?? 'point_to_point', (int)$_POST['waited_minutes'], (int)$admin['id']);
        $msg = $r['error'] ?? ('Waiting session closed. Charge $' . money($r['charge']) . ' added as pending invoice line.');
        if (!isset($r['error']) && $r['charge'] > 0) {
            $wb = $pdo->query('SELECT * FROM bookings WHERE id = ' . (int)$_POST['booking_id'])->fetch();
            if ($wb) {
                $wemail = $wb['guest_email'];
                if ($wb['customer_id']) $wemail = $pdo->query('SELECT email FROM customers WHERE id = ' . (int)$wb['customer_id'])->fetch()['email'] ?? $wemail;
                if ($wemail) NotificationService::bookingEmail($pdo, 'waiting-charge', $wb, $wemail, $wb['customer_id'], $wb['customer_id'] ? 'customer' : 'guest', ['html' => '<p>A waiting charge of <strong>$' . money($r['charge']) . '</strong> was added to booking <strong>' . e($wb['booking_number']) . '</strong>. It will be collected via pending invoice — no automatic card charge was made.</p>']);
            }
        }
    }
    header('Location: ' . url('admin/pricing.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$charges = $pdo->query('SELECT * FROM additional_charge_types ORDER BY code')->fetchAll();
$coupons = $pdo->query('SELECT * FROM coupons ORDER BY id DESC LIMIT 100')->fetchAll();
$rules = $pdo->query('SELECT * FROM waiting_rules ORDER BY category')->fetchAll();
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">Pricing</h1>
<div class="grid md:grid-cols-3 gap-4 mt-4">
<form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="mode">
  <h3 class="label">Primary mode (exactly one)</h3>
  <select name="pricing_mode" class="input"><option value="per_mile" <?= PricingService::activeMode($pdo) === 'per_mile' ? 'selected' : '' ?>>Per-Mile</option><option value="hourly" <?= PricingService::activeMode($pdo) === 'hourly' ? 'selected' : '' ?>>Hourly</option></select>
  <button class="btn-gold">Save mode</button></form>
<form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="tax">
  <h3 class="label">Tax % (default 0)</h3><input name="tax_percent" type="number" step="0.01" min="0" class="input" value="<?= e((string)(setting($pdo, 'tax_percent', '0'))) ?>"><button class="btn-gold">Save tax</button></form>
<form method="post" class="card p-4 space-y-2"><?= csrf_field() ?><input type="hidden" name="op" value="waiting_close">
  <h3 class="label">Close waiting session</h3><input name="booking_id" type="number" class="input" placeholder="Booking ID" required>
  <select name="category" class="input"><?php foreach ($rules as $r): ?><option><?= e($r['category']) ?></option><?php endforeach; ?></select>
  <input name="waited_minutes" type="number" min="0" class="input" placeholder="Waited minutes" required><button class="btn-gold">Close &amp; invoice</button></form>
</div>
<h2 class="font-display text-2xl mt-6">Additional charges</h2>
<div class="table-wrap card mt-2"><table class="data"><thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Amount</th></tr></thead><tbody>
<?php foreach ($charges as $c): ?><tr><td><?= e($c['code']) ?></td><td><?= e($c['name']) ?></td><td><?= e($c['calculation_type']) ?></td><td>$<?= money($c['amount']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<form method="post" class="card p-4 mt-2 grid md:grid-cols-5 gap-2"><?= csrf_field() ?><input type="hidden" name="op" value="charge">
<input name="code" class="input" placeholder="code" required><input name="name" class="input" placeholder="Name" required>
<select name="calculation_type" class="input"><option>flat</option><option>per_unit</option><option>per_mile</option><option>per_hour</option></select>
<input name="amount" type="number" step="0.01" class="input" placeholder="Amount" required><button class="btn-gold">Save</button></form>
<h2 class="font-display text-2xl mt-6">Coupons</h2>
<div class="table-wrap card mt-2"><table class="data"><thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Used</th><th>Per-cust</th><th>Active</th><th></th></tr></thead><tbody>
<?php foreach ($coupons as $c): ?><tr><td><?= e($c['code']) ?></td><td><?= e($c['type']) ?></td><td><?= e((string)$c['value']) ?></td><td><?= (int)$c['used_count'] ?>/<?= e((string)($c['usage_limit'] ?? '∞')) ?></td><td><?= e((string)($c['customer_limit'] ?? '—')) ?></td><td><?= (int)$c['active'] ?></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="op" value="coupon_toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn-gold">Toggle</button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<form method="post" class="card p-4 mt-2 grid md:grid-cols-6 gap-2"><?= csrf_field() ?><input type="hidden" name="op" value="coupon">
<input name="code" class="input" placeholder="CODE" required><select name="type" class="input"><option>percent</option><option>fixed</option></select>
<input name="value" type="number" step="0.01" class="input" placeholder="Value" required><input name="minimum_subtotal" type="number" step="0.01" class="input" placeholder="Min subtotal"><input name="usage_limit" type="number" class="input" placeholder="Total limit"><input name="customer_limit" type="number" class="input" placeholder="Per-customer limit">
<input name="starts_at" type="datetime-local" class="input" aria-label="Starts at"><input name="expires_at" type="datetime-local" class="input" aria-label="Expires at"><button class="btn-gold">Create</button></form>
<h2 class="font-display text-2xl mt-6">Waiting rules</h2>
<div class="table-wrap card mt-2"><table class="data"><thead><tr><th>Category</th><th>Free min</th><th>Interval</th><th>$/interval</th></tr></thead><tbody>
<?php foreach ($rules as $r): ?><tr><td><?= e($r['category']) ?></td><td><?= (int)$r['free_minutes'] ?></td><td><?= (int)$r['charge_interval_minutes'] ?></td><td>$<?= money($r['charge_per_interval']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<form method="post" class="card p-4 mt-2 grid md:grid-cols-5 gap-2"><?= csrf_field() ?><input type="hidden" name="op" value="waiting">
<input name="category" class="input" placeholder="category" required><input name="free_minutes" type="number" class="input" placeholder="Free min" required><input name="charge_interval_minutes" type="number" class="input" placeholder="Interval" required><input name="charge_per_interval" type="number" step="0.01" class="input" placeholder="$/interval" required><button class="btn-gold">Save rule</button></form>
<?php
$content = ob_get_clean();
$pageTitle = 'Pricing | Admin';
$navActive = 'pricing.php';
require APP_ROOT . '/views/layouts/admin.php';
