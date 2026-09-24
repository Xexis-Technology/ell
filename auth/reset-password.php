<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pw = $_POST['password'] ?? '';
    if (($pwErr = validate_password($pw))) {
        $error = $pwErr;
    } else {
        $row = consume_reset_token($pdo, 'customer', $token);
        if (!$row) {
            $error = 'This reset link is invalid or expired.';
        } else {
            $pdo->prepare('UPDATE customers SET password_hash = ? WHERE id = ?')->execute([hash_password($pw), $row['user_id']]);
            audit($pdo, 'customer', (int)$row['user_id'], 'auth.password_reset', 'customer', (int)$row['user_id'], null);
            $done = true;
        }
    }
}
ob_start();
?>
<div class="max-w-md mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Reset password</h1>
  <?php if ($done): ?><div class="alert alert-ok mt-4">Password updated. <a class="underline" href="<?= url('auth/login.php') ?>">Sign in</a>.</div>
  <?php else: ?>
  <?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="card p-6 mt-6 space-y-4"><?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div><label class="label" for="password">New password (min 8)</label><input id="password" type="password" name="password" class="input" required></div>
    <button class="btn-cta w-full">Update password</button>
  </form>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Reset password | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/public.php';
