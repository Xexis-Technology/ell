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
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Admin Sign in | Exotic Lane Limo</title><script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script><link rel="stylesheet" href="<?= asset('css/app.css') ?>"></head>
<body class="font-ui"><div class="max-w-md mx-auto px-4 py-16">
<h1 class="font-display text-4xl text-[#F9F9F9]">Admin</h1>
<?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card p-6 mt-6 space-y-4"><?= csrf_field() ?>
<div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input" required placeholder="admin@example.com"></div>
<div><label class="label" for="password">Password</label><input id="password" type="password" name="password" class="input" required placeholder="Your password"></div>
<button class="btn-gold w-full">Sign in</button></form></div></body></html>
