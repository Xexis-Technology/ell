<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
if (current_user('admin')) redirect('admin/dashboard.php');
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $st = $pdo->prepare('SELECT id, name FROM admins WHERE email = ? AND status = "active" LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();
    $message = 'If an account exists for that email, a reset link was sent (valid 30 minutes).';
    if ($u) {
        $raw = issue_reset_token($pdo, 'admin', (int)$u['id']);
        NotificationService::send($pdo, 'password-reset', $email, 'Reset your admin password', '<p>Hello ' . e($u['name']) . ',</p><p><a href="' . e(url('admin/reset-password.php?token=' . $raw)) . '">Reset your password</a> (expires in 30 minutes, single-use).</p>', 'admin', (int)$u['id'], null);
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Admin Forgot Password | Exotic Lane Limo</title><script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script><link rel="stylesheet" href="<?= asset('css/app.css?v=20260924g') ?>"></head>
<body class="font-ui">
<div class="min-h-screen flex items-center justify-center px-4 py-10">
  <div class="w-full max-w-md bg-[#0F0F0E] border border-[#262628] rounded-3xl p-6 md:p-8">
    <p class="font-display text-xl text-[#F3D4A6] text-center">Exotic Lane Limo</p>
    <h1 class="font-display text-2xl text-[#F9F9F9] text-center mt-2">Reset admin password</h1>
    <p class="mt-2 text-center"><span class="inline-flex items-center gap-2 text-[11px] tracking-widest border border-[#A3322F] text-[#f3c1bd] rounded-full px-3 py-1">ADMIN ONLY</span></p>
    <p class="text-xs text-center mt-2"><a class="underline" href="<?= url('auth/forgot-password.php') ?>">Customer? Reset here</a> · <a class="underline" href="<?= url('driver/forgot-password.php') ?>">Driver? Reset here</a></p>
    <?php if ($message): ?><div class="alert alert-ok mt-4"><?= e($message) ?></div><?php endif; ?>
    <form method="post" class="space-y-4 mt-6"><?= csrf_field() ?>
      <div><label class="label" for="email">Admin email</label><input id="email" type="email" name="email" class="input rounded-full" required placeholder="Enter your email"></div>
      <button class="btn-gold rounded-full w-full py-3 text-sm font-semibold">Send reset link</button>
    </form>
    <p class="text-xs text-[#AB8868] text-center mt-4"><a class="underline" href="<?= url('admin/index.php') ?>">Back to login</a></p>
  </div>
</div>
</body></html>
