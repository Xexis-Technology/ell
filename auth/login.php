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
<div class="max-w-md mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Sign in</h1>
  <?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="card p-6 mt-6 space-y-4"><?= csrf_field() ?>
    <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input" required autocomplete="email"></div>
    <div><label class="label" for="password">Password</label><input id="password" type="password" name="password" class="input" required autocomplete="current-password"></div>
    <button class="btn-cta w-full">Sign in</button>
    <p class="text-sm"><a class="underline" href="<?= url('auth/forgot-password.php') ?>">Forgot password?</a> · <a class="underline" href="<?= url('auth/register.php') ?>">Create account</a></p>
  </form>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Sign in | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/public.php';
