<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $pw = $_POST['password'] ?? '';
    $errs = validate_required(['name' => $name, 'email' => $email, 'password' => $pw], ['name','email','password']);
    if (!validate_email($email)) $errs['email'] = 'Invalid email.';
    if (($pwErr = validate_password($pw))) $errs['password'] = $pwErr;
    if ($errs) {
        $error = implode(' ', $errs);
    } else {
        $st = $pdo->prepare('SELECT 1 FROM customers WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $st = $pdo->prepare('INSERT INTO customers (name, email, phone, password_hash, status) VALUES (?,?,?,?,?)');
            $st->execute([$name, $email, $phone ?: null, hash_password($pw), 'active']);
            $id = (int)$pdo->lastInsertId();
            audit($pdo, 'customer', $id, 'auth.register', 'customer', $id, null);
            login_user('customer', ['id' => $id, 'name' => $name, 'email' => $email, 'status' => 'active']);
            redirect('account/index.php');
        }
    }
}
ob_start();
?>
<div class="max-w-md mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Create account</h1>
  <?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="card p-6 mt-6 space-y-4"><?= csrf_field() ?>
    <div><label class="label" for="name">Full name</label><input id="name" name="name" class="input" required autocomplete="name"></div>
    <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input" required autocomplete="email"></div>
    <div><label class="label" for="phone">Phone</label><input id="phone" name="phone" class="input" autocomplete="tel"></div>
    <div><label class="label" for="password">Password (min 8)</label><input id="password" type="password" name="password" class="input" required autocomplete="new-password"></div>
    <button class="btn-cta w-full">Create account</button>
    <p class="text-sm">By registering you agree to our <a class="underline" href="<?= url('legal/terms.php') ?>">Terms</a>.</p>
  </form>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Register | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/public.php';
