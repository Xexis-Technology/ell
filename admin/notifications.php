<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
require_role('admin');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM notifications WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $n = $st->fetch();
    if ($n && $n['status'] === 'failed') {
        // Safe retry: re-send via same template path is content-dependent; log retry attempt
        $ok = NotificationService::send($pdo, in_array($n['template'], NotificationService::TEMPLATES, true) ? $n['template'] : 'booking-created', $n['email'], 'Retry: ' . $n['template'], '<p>Retried notification for booking ' . e((string)($n['booking_id'] ?? '')) . '.</p>', $n['recipient_type'], $n['recipient_id'], $n['booking_id']);
        $msg = $ok ? 'Retry sent.' : 'Retry failed (logged).';
    }
    header('Location: ' . url('admin/notifications.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$rows = $pdo->query('SELECT * FROM notifications ORDER BY id DESC LIMIT 200')->fetchAll();
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl">Notifications Log</h1>
<div class="table-wrap card mt-3"><table class="data"><thead><tr><th>When</th><th>Template</th><th>To</th><th>Booking</th><th>Status</th><th>Error</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['template']) ?></td><td><?= e($r['email']) ?></td><td><?= e((string)($r['booking_id'] ?? '')) ?></td><td><?= e($r['status']) ?></td><td class="text-xs"><?= e((string)($r['error_message'] ?? '')) ?></td>
<td><?php if ($r['status'] === 'failed'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-gold">Retry</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php
$content = ob_get_clean();
$pageTitle = 'Notifications | Admin';
$navActive = 'notifications.php';
require APP_ROOT . '/views/layouts/admin.php';
