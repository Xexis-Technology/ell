<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
if (current_user('admin')) redirect('admin/dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $pw = $_POST['password'] ?? '';
    $key = 'admin:' . ($_SERVER['REMOTE_ADDR'] ?? 'x');
    if (!rate_limit_check($key)) {
        $error = 'Too many attempts. Try again later.';
    } else {
        $st = $pdo->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u && $u['status'] === 'active' && password_verify($pw, $u['password_hash'])) {
            rate_limit_clear($key);
            login_user('admin', $u);
            $pdo->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
            audit($pdo, 'admin', (int)$u['id'], 'auth.login', 'admin', (int)$u['id'], null);
            redirect('admin/dashboard.php');
        }
        rate_limit_hit($key);
        $error = 'Invalid credentials.';
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Admin Sign in | Exotic Lane Limo</title><script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script><link rel="stylesheet" href="<?= asset('css/app.css?v=20260924g') ?>"></head>
<body class="font-ui">
<div class="min-h-screen flex items-center justify-center px-4 py-10">
  <div class="w-full max-w-4xl bg-[#0F0F0E] border border-[#262628] rounded-3xl overflow-hidden grid md:grid-cols-2">
    <div class="relative min-h-[240px] md:min-h-full">
      <div class="absolute inset-0 bg-gradient-to-br from-[#181819] to-[#AB8868]"></div>
      <img src="https://images.unsplash.com/photo-1502877338535-766e1452684a?auto=format&fit=crop&w=800&q=60" alt="Luxury car at night" class="absolute inset-0 w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
      <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C]/90 via-[#0A0A0C]/30 to-transparent"></div>
      <div class="absolute bottom-0 p-6">
        <p class="font-display text-2xl text-[#F3D4A6]">Exotic Lane Limo</p>
        <p class="font-display text-xl text-[#F9F9F9] mt-1">Operations console</p>
        <p class="text-xs text-[#E5E5E3] mt-2">Bookings · dispatch · payouts — one secure sign-in.</p>
      </div>
    </div>
    <div class="p-6 md:p-10">
      <p class="font-display text-xl text-[#F3D4A6] text-center">Exotic Lane Limo</p>
      <h1 class="font-display text-3xl text-[#F9F9F9] text-center mt-2">Login to your account</h1>
      <p class="mt-3 text-center"><span class="inline-flex items-center gap-2 text-[11px] tracking-widest border border-[#A3322F] text-[#f3c1bd] rounded-full px-3 py-1">ADMIN ONLY — NOT FOR CUSTOMERS OR DRIVERS</span></p>
      <p class="text-xs text-[#AB8868] text-center mt-2">Welcome back. Enter your details to sign in.</p>
      <p class="text-xs text-center mt-1"><a class="underline" href="<?= url('auth/login.php') ?>">Customer? Sign in here</a> · <a class="underline" href="<?= url('driver/index.php') ?>">Driver? Go to driver portal</a></p>
      <?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="space-y-4 mt-6"><?= csrf_field() ?>
        <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input rounded-full" required autocomplete="email" placeholder="Enter your email"></div>
        <div><label class="label" for="password">Password</label><input id="password" type="password" name="password" class="input rounded-full" required autocomplete="current-password" placeholder="Enter your password"></div>
        <p class="text-right text-xs"><a class="underline" href="<?= url('admin/forgot-password.php') ?>">Forgot password?</a></p>
        <button class="btn-gold rounded-full w-full py-3 text-sm font-semibold">Login</button>
      </form>
      <p class="text-[11px] text-[#AB8868] text-center mt-4">Rate-limited sign-in · every login is audit-logged.</p>
      <p class="text-xs text-[#AB8868] text-center mt-2"><a class="underline" href="<?= url('index.php') ?>">← Back to site</a></p>
    </div>
  </div>
</div>
</body></html>
