<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pdo->prepare('UPDATE group_event_inquiries SET status = ?, quote_amount = ?, admin_notes = ? WHERE id = ?')->execute([in_array($_POST['status'] ?? '', ['new','quoted','confirmed','declined','closed'], true) ? $_POST['status'] : 'new', $_POST['quote_amount'] !== '' ? (float)$_POST['quote_amount'] : null, trim($_POST['admin_notes'] ?? ''), (int)$_POST['id']]);
    audit($pdo, 'admin', (int)$admin['id'], 'inquiry.updated', 'inquiry', (int)$_POST['id'], null);
    $msg = 'Inquiry updated.';
    header('Location: ' . url('admin/group-events.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$rows = $pdo->query('SELECT * FROM group_event_inquiries ORDER BY id DESC LIMIT 200')->fetchAll();
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">Group &amp; Event Inquiries</h1>
<div class="table-wrap card mt-3"><table class="data"><thead><tr><th>ID</th><th>Kind</th><th>Event</th><th>Contact</th><th>Status</th><th>Quote</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= e($r['kind']) ?></td><td><?= e($r['event_type']) ?> · <?= e((string)($r['event_dates'] ?? '')) ?> · <?= (int)($r['vehicle_count'] ?? 0) ?> veh · <?= (int)($r['estimated_passengers'] ?? 0) ?> pax<br><span class="text-xs"><?= e(mb_strimwidth((string)($r['locations'] ?? ''), 0, 80, '…')) ?></span></td><td><?= e($r['contact_name']) ?><br><?= e($r['contact_email']) ?></td><td><?= e($r['status']) ?></td><td><?= $r['quote_amount'] !== null ? '$' . money($r['quote_amount']) : '—' ?></td>
<td><form method="post" class="space-y-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<select name="status" class="input"><?php foreach (['new','quoted','confirmed','declined','closed'] as $s): ?><option <?= $r['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
<input name="quote_amount" type="number" step="0.01" class="input" placeholder="Quote $" value="<?= e((string)($r['quote_amount'] ?? '')) ?>">
<input name="admin_notes" class="input" placeholder="Notes" value="<?= e((string)($r['admin_notes'] ?? '')) ?>">
<button class="btn-gold">Save</button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Groups | Admin';
$navActive = 'group-events.php';
require APP_ROOT . '/views/layouts/admin.php';
