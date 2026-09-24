<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$user = require_role('customer');
$msg = '';
$isErr = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $st = $pdo->prepare('SELECT password_hash FROM customers WHERE id = ? LIMIT 1');
    $st->execute([$user['id']]);
    $row = $st->fetch();
    if (!$row['password_hash'] || !password_verify($_POST['current'] ?? '', $row['password_hash'])) {
        $msg = 'Current password is incorrect.';
        $isErr = true;
    } elseif (($pwErr = validate_password($_POST['new'] ?? ''))) {
        $msg = $pwErr;
        $isErr = true;
    } else {
        $pdo->prepare('UPDATE customers SET password_hash = ? WHERE id = ?')->execute([hash_password($_POST['new']), $user['id']]);
        audit($pdo, 'customer', (int)$user['id'], 'auth.password_changed', 'customer', (int)$user['id'], null);
        $msg = 'Password updated.';
    }
}
ob_start();
?>
<h1 class="font-display text-4xl text-[#F9F9F9]">Password</h1>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?> mt-4"><?= e($msg) ?></div><?php endif; ?>
<form method="post" class="card p-6 mt-4 space-y-4"><?= csrf_field() ?>
  <div><label class="label" for="current">Current password</label><input id="current" type="password" name="current" class="input" required placeholder="Your current password"></div>
  <div><label class="label" for="new">New password (min 8)</label><input id="new" type="password" name="new" class="input" required placeholder="Choose a new password"></div>
  <button class="btn-gold">Update password</button>
</form>
<?php
$content = ob_get_clean();
$pageTitle = 'Password | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/customer.php';
