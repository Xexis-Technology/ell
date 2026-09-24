<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$user = require_role('customer');
$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$sql = 'SELECT b.*, v.make, v.model FROM bookings b LEFT JOIN vehicles v ON v.id = b.vehicle_id WHERE b.customer_id = ?';
$params = [$user['id']];
if ($q !== '') {
    $sql .= ' AND (b.booking_number LIKE ? OR b.pickup_location LIKE ? OR b.destination_location LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if ($status !== '') {
    $sql .= ' AND b.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY b.id DESC LIMIT 100';
$st = $pdo->prepare($sql);
$st->execute($params);
$bookings = $st->fetchAll();
ob_start();
?>
<h1 class="font-display text-4xl text-[#F9F9F9]">My Bookings</h1>
<form method="get" class="flex flex-wrap gap-2 mt-4">
  <input name="q" class="input" style="max-width:220px" placeholder="Search number/route" value="<?= e($q) ?>" aria-label="Search bookings">
  <select name="status" class="input" style="max-width:200px" aria-label="Filter by status"><option value="">All statuses</option>
  <?php foreach (BookingService::VALID_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
  <button class="btn-gold">Filter</button>
</form>
<div class="table-wrap card mt-4"><table class="data"><thead><tr><th>Number</th><th>Date/Time</th><th>Vehicle</th><th>Status</th><th>Total</th><th></th></tr></thead><tbody>
<?php foreach ($bookings as $b): ?><tr><td><?= e($b['booking_number']) ?></td><td><?= e($b['pickup_date']) ?> <?= e(substr($b['pickup_time'], 0, 5)) ?></td><td><?= e(trim(($b['make'] ?? '') . ' ' . ($b['model'] ?? ''))) ?></td><td><?= e($b['status']) ?> / <?= e($b['payment_status']) ?></td><td>$<?= money($b['total']) ?></td><td><a class="underline" href="<?= url('account/booking-view.php?n=' . $b['booking_number']) ?>">View</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Bookings | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/customer.php';
