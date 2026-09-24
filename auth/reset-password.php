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
<div class="max-w-4xl mx-auto px-4 py-10 md:py-14">
  <div class="card rounded-2xl overflow-hidden grid md:grid-cols-2">
    <div class="relative min-h-[220px] md:min-h-full">
      <div class="absolute inset-0 bg-gradient-to-br from-[#181819] to-[#AB8868]"></div>
      <img src="https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=800&q=60" alt="Airplane wing at sunset" class="absolute inset-0 w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
      <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C]/90 via-[#0A0A0C]/30 to-transparent"></div>
      <div class="absolute bottom-0 p-6">
        <p class="eyebrow">Members' entrance</p>
        <p class="font-display text-2xl text-[#F9F9F9] mt-2">Choose a new password.</p>
        <p class="text-xs mt-2 text-[#E5E5E3]">Links expire in 30 minutes and work once.</p>
      </div>
    </div>
    <div class="p-6 md:p-8">
      <h1 class="font-display text-3xl text-[#F9F9F9]">Reset password</h1>
      <?php if ($done): ?><div class="alert alert-ok mt-4">Password updated. <a class="underline" href="<?= url('auth/login.php') ?>">Sign in</a>.</div>
      <?php else: ?>
      <?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="space-y-4 mt-4"><?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div><label class="label" for="password">New password (min 8)</label><input id="password" type="password" name="password" class="input" required autocomplete="new-password" placeholder="Choose a new password"></div>
        <button class="btn-cta w-full rounded-full">Update password</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Reset password | Exotic Lane Limo';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
