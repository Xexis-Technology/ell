<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
require_role('admin');
$q = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM audit_logs WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (action LIKE ? OR entity_type LIKE ? OR actor_type LIKE ?)';
    $params = ["%$q%", "%$q%", "%$q%"];
}
$sql .= ' ORDER BY id DESC LIMIT 300';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
ob_start();
?>
<h1 class="font-display text-3xl">Audit Log</h1>
<form method="get" class="flex gap-2 mt-3"><input name="q" class="input" style="max-width:240px" placeholder="Search action/entity/actor" value="<?= e($q) ?>"><button class="btn-gold">Search</button></form>
<div class="table-wrap card mt-3"><table class="data"><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['actor_type']) ?> <?= e((string)($r['actor_id'] ?? '')) ?></td><td><?= e($r['action']) ?></td><td><?= e((string)($r['entity_type'] ?? '')) ?> <?= e((string)($r['entity_id'] ?? '')) ?></td><td><?= e((string)($r['ip_address'] ?? '')) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Audit | Admin';
$navActive = 'audit.php';
require APP_ROOT . '/views/layouts/admin.php';
