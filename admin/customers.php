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
    if ($op === 'status' && $id) {
        $pdo->prepare('UPDATE customers SET status = ? WHERE id = ?')->execute([$_POST['status'] === 'inactive' ? 'inactive' : 'active', $id]);
        audit($pdo, 'admin', (int)$admin['id'], 'customer.status', 'customer', $id, null);
        $msg = 'Customer updated.';
    }
    header('Location: ' . url('admin/customers.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$q = trim($_GET['q'] ?? '');
$sql = 'SELECT c.*, (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id) AS rides FROM customers c';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?';
    $params = ["%$q%", "%$q%", "%$q%"];
}
$sql .= ' ORDER BY c.id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">Customers</h1>
<form method="get" class="flex gap-2 mt-3"><input name="q" class="input" style="max-width:240px" placeholder="Search name/email/phone" value="<?= e($q) ?>"><button class="btn-gold">Search</button></form>
<div class="table-wrap card mt-3"><table class="data"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Rides</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['email']) ?></td><td><?= e((string)($r['phone'] ?? '')) ?></td><td><?= (int)$r['rides'] ?></td><td><?= e($r['status']) ?></td>
<td><form method="post" class="flex gap-1"><?= csrf_field() ?><input type="hidden" name="op" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<select name="status" class="input"><option value="active" <?= $r['status'] === 'active' ? 'selected' : '' ?>>active</option><option value="inactive" <?= $r['status'] === 'inactive' ? 'selected' : '' ?>>inactive</option></select><button class="btn-gold">Save</button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Customers | Admin';
$navActive = 'customers.php';
require APP_ROOT . '/views/layouts/admin.php';
