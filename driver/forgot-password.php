<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $st = $pdo->prepare('SELECT id, name FROM drivers WHERE email = ? AND status = "active" LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();
    $message = 'If an account exists for that email, a reset link was sent (valid 30 minutes).';
    if ($u) {
        $raw = issue_reset_token($pdo, 'driver', (int)$u['id']);
        NotificationService::send($pdo, 'password-reset', $email, 'Reset your driver password', '<p>Hello ' . e($u['name']) . ',</p><p><a href="' . e(url('driver/reset-password.php?token=' . $raw)) . '">Reset your password</a> (expires in 30 minutes, single-use).</p>', 'driver', (int)$u['id'], null);
    }
}
ob_start();
?>
<h1 class="font-display text-3xl text-[#F3D4A6]">Forgot password</h1>
<?php if ($message): ?><div class="alert alert-ok mt-4"><?= e($message) ?></div><?php endif; ?>
<form method="post" class="card p-5 mt-4 space-y-3"><?= csrf_field() ?>
<div><label class="label" for="email">Driver account email</label><input id="email" type="email" name="email" class="input" required></div>
<button class="btn-gold touch-btn">Send reset link</button></form>
<?php
$content = ob_get_clean();
$pageTitle = 'Driver forgot password';
require APP_ROOT . '/views/layouts/driver.php';
