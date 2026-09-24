<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = $_POST['op'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM driver_payouts WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $p = $st->fetch();
    if ($p) {
        if ($op === 'approve') {
            $pdo->prepare('UPDATE driver_payouts SET status = "approved", reviewed_at = NOW(), reviewed_by = ? WHERE id = ?')->execute([(int)$admin['id'], $id]);
        } elseif ($op === 'reject') {
            $pdo->prepare('UPDATE driver_payouts SET status = "rejected", reviewed_at = NOW(), reviewed_by = ?, notes = ? WHERE id = ?')->execute([(int)$admin['id'], trim($_POST['notes'] ?? ''), $id]);
        } elseif ($op === 'paid') {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE driver_payouts SET status = "paid", paid_at = NOW(), reviewed_by = ? WHERE id = ?')->execute([(int)$admin['id'], $id]);
            $pdo->prepare('UPDATE driver_earnings SET status = "paid", paid_at = NOW() WHERE driver_id = ? AND status = "unpaid"')->execute([$p['driver_id']]);
            $pdo->commit();
        }
        audit($pdo, 'admin', (int)$admin['id'], 'payout.' . $op, 'payout', $id, null);
        $msg = 'Payout ' . $op . '.';
    }
    header('Location: ' . url('admin/payouts.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$rows = $pdo->query('SELECT p.*, d.name AS dname FROM driver_payouts p JOIN drivers d ON d.id = p.driver_id ORDER BY p.id DESC LIMIT 200')->fetchAll();
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">Driver Payouts</h1>
<div class="table-wrap card mt-3"><table class="data"><thead><tr><th>Driver</th><th>Amount</th><th>Status</th><th>Requested</th><th>Notes</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['dname']) ?></td><td>$<?= money($r['amount']) ?></td><td><?= e($r['status']) ?></td><td><?= e($r['requested_at']) ?></td><td><?= e((string)($r['notes'] ?? '')) ?></td>
<td class="whitespace-nowrap"><?php if ($r['status'] === 'requested'): ?>
<form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="op" value="approve"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-gold">Approve</button></form>
<form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="op" value="reject"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="notes" class="input" style="width:120px;display:inline" placeholder="Reason"><button class="btn-danger-outline">Reject</button></form>
<?php elseif ($r['status'] === 'approved'): ?>
<form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="op" value="paid"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-gold" data-confirm="Record this payout as paid?">Record paid</button></form>
<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Payouts | Admin';
$navActive = 'payouts.php';
require APP_ROOT . '/views/layouts/admin.php';
