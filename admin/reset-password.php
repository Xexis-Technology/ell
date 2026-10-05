<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
if (current_user('admin')) redirect('admin/dashboard.php');
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pw = $_POST['password'] ?? '';
    if (($pwErr = validate_password($pw))) {
        $error = $pwErr;
    } else {
        $row = consume_reset_token($pdo, 'admin', $token);
        if (!$row) {
            $error = 'This reset link is invalid or expired.';
        } else {
            $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([hash_password($pw), $row['user_id']]);
            audit($pdo, 'admin', (int)$row['user_id'], 'auth.password_reset', 'admin', (int)$row['user_id'], null);
            $done = true;
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Admin Reset Password | Exotic Lane Limo</title><script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script><link rel="stylesheet" href="<?= asset('css/app.css?v=20260924g') ?>"></head>
<body class="font-ui">
<div class="min-h-screen flex items-center justify-center px-4 py-10">
  <div class="w-full max-w-md bg-[#0F0F0E] border border-[#262628] rounded-3xl p-6 md:p-8">
    <p class="font-display text-xl text-[#F3D4A6] text-center">Exotic Lane Limo</p>
    <h1 class="font-display text-2xl text-[#F9F9F9] text-center mt-2">Choose a new password</h1>
    <p class="mt-2 text-center"><span class="inline-flex items-center gap-2 text-[11px] tracking-widest border border-[#A3322F] text-[#f3c1bd] rounded-full px-3 py-1">ADMIN ONLY</span></p>
    <?php if ($done): ?><div class="alert alert-ok mt-4">Password updated. <a class="underline" href="<?= url('admin/index.php') ?>">Sign in</a>.</div>
    <?php else: ?>
    <?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="space-y-4 mt-6"><?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div><label class="label" for="password">New password (min 8)</label><input id="password" type="password" name="password" class="input rounded-full" required placeholder="Enter your new password"></div>
      <button class="btn-gold rounded-full w-full py-3 text-sm font-semibold">Update password</button>
    </form>
    <?php endif; ?>
  </div>
</div>
</body></html>
