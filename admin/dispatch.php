<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
$isErr = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $r = DispatchService::assign($pdo, (int)$_POST['booking_id'], $_POST['driver_id'] !== '' ? (int)$_POST['driver_id'] : null, $_POST['vehicle_id'] !== '' ? (int)$_POST['vehicle_id'] : null, (int)$admin['id'], trim($_POST['tmp_driver_name'] ?? '') ?: null, trim($_POST['tmp_driver_phone'] ?? '') ?: null, trim($_POST['tmp_vehicle'] ?? '') ? ['label' => trim($_POST['tmp_vehicle'])] : null);
    $msg = $r['error'] ?? 'Dispatch saved. Notifications sent.';
    $isErr = isset($r['error']);
    if (!$isErr) {
        $st = $pdo->prepare('SELECT b.*, d.driver_id FROM bookings b LEFT JOIN dispatches d ON d.booking_id = b.id WHERE b.id = ? LIMIT 1');
        $st->execute([(int)$_POST['booking_id']]);
        $b = $st->fetch();
        if ($b && $b['driver_id']) {
            $dr = $pdo->query('SELECT email FROM drivers WHERE id = ' . (int)$b['driver_id'])->fetch();
            if ($dr) NotificationService::bookingEmail($pdo, 'driver-assigned', $b, $dr['email'], (int)$b['driver_id'], 'driver');
        }
    }
    header('Location: ' . url('admin/dispatch.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$upcoming = $pdo->query('SELECT b.id, b.booking_number, b.pickup_date, b.pickup_time, b.status, d.driver_id, d.vehicle_id, dr.name AS dname, CONCAT(v.make, " ", v.model) AS vname FROM bookings b LEFT JOIN dispatches d ON d.booking_id = b.id LEFT JOIN drivers dr ON dr.id = d.driver_id LEFT JOIN vehicles v ON v.id = d.vehicle_id WHERE b.status IN ("confirmed","assigned","booking_received") ORDER BY b.pickup_date, b.pickup_time LIMIT 100')->fetchAll();
$drivers = $pdo->query('SELECT id, name FROM drivers WHERE status = "active" ORDER BY name')->fetchAll();
$vehicles = $pdo->query('SELECT id, make, model FROM vehicles WHERE status = "active" ORDER BY id')->fetchAll();
$history = $pdo->query('SELECT h.*, b.booking_number FROM dispatch_history h JOIN bookings b ON b.id = h.booking_id ORDER BY h.id DESC LIMIT 50')->fetchAll();
ob_start();
?>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">Dispatch Board</h1>
<div class="table-wrap card mt-3"><table class="data"><thead><tr><th>Booking</th><th>Pickup</th><th>Status</th><th>Driver</th><th>Vehicle</th><th>Assign</th></tr></thead><tbody>
<?php foreach ($upcoming as $u): ?><tr><td><a class="underline" href="<?= url('admin/bookings.php?action=view&n=' . $u['booking_number']) ?>"><?= e($u['booking_number']) ?></a></td><td><?= e($u['pickup_date']) ?> <?= e(substr($u['pickup_time'], 0, 5)) ?></td><td><?= e($u['status']) ?></td><td><?= e((string)($u['dname'] ?? '—')) ?></td><td><?= e((string)($u['vname'] ?? '—')) ?></td>
<td><form method="post" class="space-y-1"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int)$u['id'] ?>">
<select name="driver_id" class="input" aria-label="Driver"><option value="">— driver —</option><?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)($u['driver_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
<select name="vehicle_id" class="input" aria-label="Vehicle"><option value="">— vehicle —</option><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>" <?= (int)($u['vehicle_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['make'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select>
<input name="tmp_driver_name" class="input" placeholder="Temp driver (optional)"><input name="tmp_driver_phone" class="input" placeholder="Temp driver phone"><input name="tmp_vehicle" class="input" placeholder="Temp vehicle (optional)">
<button class="btn-gold">Assign</button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<h2 class="font-display text-2xl mt-6">Dispatch history</h2>
<div class="table-wrap card mt-2"><table class="data"><thead><tr><th>Booking</th><th>Driver</th><th>Vehicle</th><th>Note</th><th>When</th></tr></thead><tbody>
<?php foreach ($history as $h): ?><tr><td><?= e($h['booking_number']) ?></td><td><?= e((string)($h['old_driver_id'] ?? '')) ?> → <?= e((string)($h['new_driver_id'] ?? '')) ?></td><td><?= e((string)($h['old_vehicle_id'] ?? '')) ?> → <?= e((string)($h['new_vehicle_id'] ?? '')) ?></td><td><?= e((string)($h['note'] ?? '')) ?></td><td><?= e($h['created_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Dispatch | Admin';
$navActive = 'dispatch.php';
require APP_ROOT . '/views/layouts/admin.php';
