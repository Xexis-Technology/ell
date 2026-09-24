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
<div class="max-w-4xl mx-auto px-4 py-10 md:py-14">
  <div class="card rounded-2xl overflow-hidden grid md:grid-cols-2">
    <div class="relative min-h-[220px] md:min-h-full">
      <div class="absolute inset-0 bg-gradient-to-br from-[#181819] to-[#AB8868]"></div>
      <img src="https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?auto=format&fit=crop&w=800&q=60" alt="City streets at night" class="absolute inset-0 w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
      <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C]/90 via-[#0A0A0C]/30 to-transparent"></div>
      <div class="absolute bottom-0 p-6">
        <p class="eyebrow">Members' entrance</p>
        <p class="font-display text-2xl text-[#F9F9F9] mt-2">Ride in minutes.</p>
        <p class="text-xs mt-2 text-[#E5E5E3]"><a class="underline" href="<?= url('services/booking.php') ?>">Book as guest</a> · <a class="underline" href="<?= url('legal/terms.php') ?>">Terms</a></p>
      </div>
    </div>
    <form method="post" class="p-6 md:p-8 space-y-4"><?= csrf_field() ?>
      <h1 class="font-display text-3xl text-[#F9F9F9]">Create account</h1>
      <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
      <div><label class="label" for="name">Full name</label><input id="name" name="name" class="input" required autocomplete="name" placeholder="Jane Smith" value="<?= e($_POST['name'] ?? '') ?>"></div>
      <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input" required autocomplete="email" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>"></div>
      <div><label class="label" for="phone">Phone</label><input id="phone" name="phone" class="input" autocomplete="tel" placeholder="+1 555 010 2030" value="<?= e($_POST['phone'] ?? '') ?>"></div>
      <div><label class="label" for="password">Password (min 8)</label><input id="password" type="password" name="password" class="input" required autocomplete="new-password" placeholder="Choose a password"></div>
      <button class="btn-cta w-full rounded-full">Create account</button>
      <p class="text-sm">By registering you agree to our <a class="underline" href="<?= url('legal/terms.php') ?>">Terms</a>. Already registered? <a class="underline" href="<?= url('auth/login.php') ?>">Sign in</a>.</p>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Register | Exotic Lane Limo';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
