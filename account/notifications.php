<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$user = require_role('customer');
$st = $pdo->prepare('SELECT email FROM customers WHERE id = ? LIMIT 1');
$st->execute([$user['id']]);
$email = $st->fetch()['email'];
$st = $pdo->prepare('SELECT * FROM notifications WHERE (recipient_type = "customer" AND recipient_id = ?) OR email = ? ORDER BY id DESC LIMIT 100');
$st->execute([$user['id'], $email]);
$rows = $st->fetchAll();
ob_start();
?>
<h1 class="font-display text-4xl text-[#F9F9F9]">Notifications</h1>
<div class="table-wrap card mt-4"><table class="data"><thead><tr><th>Date</th><th>Template</th><th>Booking</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['template']) ?></td><td><?= e((string)($r['booking_id'] ?? '')) ?></td><td><?= e($r['status']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Notifications | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/customer.php';
