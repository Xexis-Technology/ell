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
<div class="max-w-4xl mx-auto px-4 py-10 md:py-14">
  <div class="card rounded-2xl overflow-hidden grid md:grid-cols-2">
    <div class="relative min-h-[220px] md:min-h-full">
      <div class="absolute inset-0 bg-gradient-to-br from-[#181819] to-[#AB8868]"></div>
      <img src="https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=800&q=60" alt="Luxury car on the road" class="absolute inset-0 w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
      <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C]/90 via-[#0A0A0C]/30 to-transparent"></div>
      <div class="absolute bottom-0 p-6">
        <p class="eyebrow">Members' entrance</p>
        <p class="font-display text-2xl text-[#F9F9F9] mt-2">Locked out? Let's fix it.</p>
        <p class="text-xs mt-2 text-[#E5E5E3]"><a class="underline" href="<?= url('auth/login.php') ?>">Back to sign in</a></p>
      </div>
    </div>
    <form method="post" class="p-6 md:p-8 space-y-4"><?= csrf_field() ?>
      <h1 class="font-display text-3xl text-[#F9F9F9]">Forgot password</h1>
      <?php if ($message): ?><div class="alert alert-ok"><?= e($message) ?></div><?php endif; ?>
      <div><label class="label" for="email">Account email</label><input id="email" type="email" name="email" class="input" required autocomplete="email" placeholder="you@example.com"></div>
      <button class="btn-cta w-full rounded-full">Send reset link</button>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Forgot password | Exotic Lane Limo';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
