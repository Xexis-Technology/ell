<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$user = require_role('customer');
$n = $_GET['booking'] ?? '';
$st = $pdo->prepare('SELECT b.*, v.make, v.model, i.invoice_number, i.issued_at FROM bookings b LEFT JOIN vehicles v ON v.id = b.vehicle_id LEFT JOIN invoices i ON i.booking_id = b.id WHERE b.booking_number = ? AND b.customer_id = ? LIMIT 1');
$st->execute([$n, $user['id']]);
$b = $st->fetch();
if (!$b) {
    http_response_code(404);
    require APP_ROOT . '/views/errors/404.php';
    exit;
}
$st = $pdo->prepare('SELECT * FROM booking_charges WHERE booking_id = ?');
$st->execute([$b['id']]);
$charges = $st->fetchAll();

$customerName = $user['name'];
$customerEmail = $user['email'];
$orgName = (string)(setting($pdo, 'org_name', 'Exotic Lane Limo'));
require APP_ROOT . '/views/invoices/invoice.php'; // builds $html

// PDF download via dompdf
if (($_GET['format'] ?? '') === 'pdf') {
    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="invoice-' . $b['booking_number'] . '.pdf"');
    echo $dompdf->output();
    exit;
}
ob_start();
?>
<h1 class="font-display text-4xl text-[#F9F9F9]">Invoice <?= e($b['invoice_number'] ?? $b['booking_number']) ?></h1>
<div class="card p-6 mt-4 text-sm space-y-2">
  <p><strong><?= e((string)(setting($pdo, 'org_name', 'Exotic Lane Limo'))) ?></strong></p>
  <p>Booking <?= e($b['booking_number']) ?> · Customer <?= e($user['name']) ?> (<?= e($user['email']) ?>)</p>
  <p>Trip: <?= e($b['pickup_location']) ?> → <?= e($b['destination_location']) ?> on <?= e($b['pickup_date']) ?> <?= e(substr($b['pickup_time'], 0, 5)) ?></p>
  <p>Vehicle: <?= e(trim(($b['make'] ?? '') . ' ' . ($b['model'] ?? ''))) ?></p>
  <hr>
  <?php foreach ($charges as $c): ?><p><?= e($c['description']) ?> — $<?= money($c['total']) ?></p><?php endforeach; ?>
  <hr>
  <p>Subtotal $<?= money($b['subtotal']) ?> · Discount $<?= money($b['discount']) ?> · Tax $<?= money($b['tax']) ?></p>
  <p class="font-display text-2xl text-[#F3D4A6]">Total $<?= money($b['total']) ?> · <?= e($b['payment_status']) ?></p>
</div>
<a href="<?= url('account/invoice.php?booking=' . $b['booking_number'] . '&format=pdf') ?>" class="btn-gold inline-block mt-4">Download PDF</a>
<?php
$content = ob_get_clean();
$pageTitle = 'Invoice | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/customer.php';
