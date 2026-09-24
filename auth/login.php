<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $pw = $_POST['password'] ?? '';
    $key = 'customer:' . ($_SERVER['REMOTE_ADDR'] ?? 'x');
    if (!rate_limit_check($key)) {
        $error = 'Too many attempts. Try again later.';
    } else {
        $st = $pdo->prepare('SELECT * FROM customers WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u && $u['status'] === 'active' && $u['password_hash'] && password_verify($pw, $u['password_hash'])) {
            rate_limit_clear($key);
            login_user('customer', $u);
            audit($pdo, 'customer', (int)$u['id'], 'auth.login', 'customer', (int)$u['id'], null);
            redirect('account/index.php');
        }
        rate_limit_hit($key);
        $error = 'Invalid email or password.';
    }
}
ob_start();
?>
<div class="max-w-4xl mx-auto px-4 py-10 md:py-14">
  <div class="card rounded-2xl overflow-hidden grid md:grid-cols-2">
    <div class="relative min-h-[220px] md:min-h-full">
      <div class="absolute inset-0 bg-gradient-to-br from-[#181819] to-[#AB8868]"></div>
      <img src="https://images.unsplash.com/photo-1477959858617-67f85cf4f1df?auto=format&fit=crop&w=800&q=60" alt="City at night" class="absolute inset-0 w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
      <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C]/90 via-[#0A0A0C]/30 to-transparent"></div>
      <div class="absolute bottom-0 p-6">
        <p class="eyebrow">Members' entrance</p>
        <p class="font-display text-2xl text-[#F9F9F9] mt-2">Welcome back.</p>
        <p class="text-xs mt-2 text-[#E5E5E3]"><a class="underline" href="<?= url('services/booking.php') ?>">Book a ride</a> · <a class="underline" href="<?= url('index.php#fleet') ?>">Fleet</a> · <a class="underline" href="<?= url('legal/contact.php') ?>">Contact</a></p>
      </div>
    </div>
    <form method="post" class="p-6 md:p-8 space-y-4"><?= csrf_field() ?>
      <h1 class="font-display text-3xl text-[#F9F9F9]">Sign in</h1>
      <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
    <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input" required autocomplete="email" placeholder="you@example.com"></div>
    <div><label class="label" for="password">Password</label><input id="password" type="password" name="password" class="input" required autocomplete="current-password" placeholder="Your password"></div>
      <button class="btn-cta w-full rounded-full">Sign in</button>
      <p class="text-sm"><a class="underline" href="<?= url('auth/forgot-password.php') ?>">Forgot password?</a> · <a class="underline" href="<?= url('auth/register.php') ?>">Create account</a></p>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Sign in | Exotic Lane Limo';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
