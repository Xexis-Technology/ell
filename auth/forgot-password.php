<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $st = $pdo->prepare('SELECT id, name FROM customers WHERE email = ? AND status = "active" LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();
    // Always show generic message (no account enumeration)
    $message = 'If an account exists for that email, a reset link was sent (valid 30 minutes).';
    if ($u) {
        $raw = issue_reset_token($pdo, 'customer', (int)$u['id']);
        NotificationService::send($pdo, 'password-reset', $email, 'Reset your password', '<p>Hello ' . e($u['name']) . ',</p><p><a href="' . e(url('auth/reset-password.php?token=' . $raw)) . '">Reset your password</a> (expires in 30 minutes, single-use).</p>', 'customer', (int)$u['id'], null);
    }
}
ob_start();
?>
<div class="max-w-md mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Forgot password</h1>
  <?php if ($message): ?><div class="alert alert-ok mt-4"><?= e($message) ?></div><?php endif; ?>
  <form method="post" class="card p-6 mt-6 space-y-4"><?= csrf_field() ?>
    <div><label class="label" for="email">Account email</label><input id="email" type="email" name="email" class="input" required></div>
    <button class="btn-cta w-full">Send reset link</button>
  </form>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Forgot password | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/public.php';
