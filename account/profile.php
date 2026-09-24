<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$user = require_role('customer');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if ($name === '') $msg = 'Name is required.';
    else {
        $pdo->prepare('UPDATE customers SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone ?: null, $user['id']]);
        $_SESSION['user']['customer']['name'] = $name;
        audit($pdo, 'customer', (int)$user['id'], 'profile.updated', 'customer', (int)$user['id'], null);
        $msg = 'Profile updated.';
        $user['name'] = $name;
    }
}
$st = $pdo->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
$st->execute([$user['id']]);
$c = $st->fetch();
ob_start();
?>
<h1 class="font-display text-4xl text-[#F9F9F9]">Profile</h1>
<?php if ($msg): ?><div class="alert alert-ok mt-4"><?= e($msg) ?></div><?php endif; ?>
<form method="post" class="card p-6 mt-4 space-y-4"><?= csrf_field() ?>
  <div><label class="label" for="name">Full name</label><input id="name" name="name" class="input" required value="<?= e($c['name']) ?>"></div>
  <div><label class="label" for="email">Email</label><input id="email" class="input" disabled value="<?= e($c['email']) ?>"></div>
  <div><label class="label" for="phone">Phone</label><input id="phone" name="phone" class="input" value="<?= e($c['phone'] ?? '') ?>"></div>
  <button class="btn-gold">Save</button>
</form>
<?php
$content = ob_get_clean();
$pageTitle = 'Profile | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/customer.php';
