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
        $ns = in_array($_POST['status'] ?? '', ['pending','active','inactive','suspended'], true) ? $_POST['status'] : 'pending';
        $pdo->prepare('UPDATE drivers SET status = ? WHERE id = ?')->execute([$ns, $id]);
        audit($pdo, 'admin', (int)$admin['id'], 'driver.status', 'driver', $id, ['to' => $ns]);
        $msg = 'Driver status updated.';
    } elseif ($op === 'doc' && isset($_FILES['doc'])) {
        [$path, $err] = secure_upload($_FILES['doc'], 'drivers');
        if ($err) $msg = $err;
        else {
            $pdo->prepare('INSERT INTO driver_documents (driver_id, doc_type, file_path, expiry_date, status) VALUES (?,?,?,?,?)')->execute([$id, trim($_POST['doc_type'] ?? 'document'), $path, $_POST['expiry_date'] ?: null, 'pending']);
            $msg = 'Document uploaded.';
        }
    } elseif ($op === 'verify_doc') {
        $pdo->prepare('UPDATE driver_documents SET status = ? WHERE id = ?')->execute([in_array($_POST['doc_status'] ?? '', ['verified','rejected','expired'], true) ? $_POST['doc_status'] : 'pending', (int)$_POST['doc_id']]);
        $msg = 'Document reviewed.';
    }
    header('Location: ' . url('admin/drivers.php?msg=' . urlencode($msg) . '&status=' . urlencode($_GET['status'] ?? '')));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$filter = $_GET['status'] ?? '';
$sql = 'SELECT d.*, (SELECT COUNT(*) FROM dispatches x WHERE x.driver_id = d.id) AS rides FROM drivers d';
$params = [];
if ($filter !== '') {
    $sql .= ' WHERE d.status = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY d.id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
$docs = [];
if ($rows) {
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    $docs = $pdo->query('SELECT * FROM driver_documents WHERE driver_id IN (' . $ids . ') ORDER BY id DESC')->fetchAll();
}
$byDriver = [];
foreach ($docs as $d) $byDriver[$d['driver_id']][] = $d;
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">Drivers</h1>
<p class="text-sm">Registration requires Admin activation before dispatch. <a class="underline" href="<?= url('admin/drivers.php') ?>">All</a> · <a class="underline" href="<?= url('admin/drivers.php?status=pending') ?>">Pending</a></p>
<div class="table-wrap card mt-3"><table class="data"><thead><tr><th>Name</th><th>Email / Phone</th><th>Ref</th><th>Rides</th><th>Status</th><th>Docs</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['email']) ?><br><?= e((string)($r['phone'] ?? '')) ?></td><td><?= e((string)($r['reference'] ?? '')) ?></td><td><?= (int)$r['rides'] ?></td><td><?= e($r['status']) ?></td>
<td class="text-xs"><?php foreach ($byDriver[$r['id']] ?? [] as $d): ?><p><?= e($d['doc_type']) ?> — <?= e($d['status']) ?> <a class="underline" href="<?= url($d['file_path']) ?>">file</a>
<form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="op" value="verify_doc"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>"><select name="doc_status" class="input" style="width:110px;display:inline"><option>verified</option><option>rejected</option><option>expired</option></select><button class="btn-gold">OK</button></form></p><?php endforeach; ?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="op" value="doc"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="doc_type" class="input" placeholder="doc type"><input type="file" name="doc" class="input" accept=".jpg,.jpeg,.png,.pdf"><button class="btn-gold">Upload</button></form></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="op" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<select name="status" class="input"><?php foreach (['pending','active','inactive','suspended'] as $s): ?><option <?= $r['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select><button class="btn-gold mt-1">Save</button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Drivers | Admin';
$navActive = 'drivers.php';
require APP_ROOT . '/views/layouts/admin.php';
