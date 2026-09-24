<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$error = '';
$done = false;
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
        $st = $pdo->prepare('SELECT 1 FROM drivers WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $ref = trim($_POST['reference'] ?? '');
            $st = $pdo->prepare('INSERT INTO drivers (name, email, phone, password_hash, status, reference) VALUES (?,?,?,?,?,?)');
            $st->execute([$name, $email, $phone ?: null, hash_password($pw), 'pending', $ref ?: null]);
            $id = (int)$pdo->lastInsertId();
            if (isset($_FILES['document']) && ($_FILES['document']['error'] ?? 4) === UPLOAD_ERR_OK) {
                [$path, $err] = secure_upload($_FILES['document'], 'drivers');
                if (!$err) $pdo->prepare('INSERT INTO driver_documents (driver_id, doc_type, file_path, status) VALUES (?,?,?,?)')->execute([$id, 'license', $path, 'pending']);
            }
            audit($pdo, 'driver', $id, 'auth.register', 'driver', $id, null);
            $done = true;
        }
    }
}
ob_start();
?>
<h1 class="font-display text-3xl text-[#F3D4A6]">Drive with Exotic Lane</h1>
<?php if ($done): ?><div class="alert alert-ok mt-4">Registration received — status <strong>pending</strong>. An administrator will activate your account before dispatch.</div>
<?php else: ?>
<?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card p-5 mt-4 space-y-3"><?= csrf_field() ?>
<div><label class="label" for="name">Full name</label><input id="name" name="name" class="input" required placeholder="Jane Smith"></div>
<div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input" required placeholder="you@example.com"></div>
<div><label class="label" for="phone">Mobile</label><input id="phone" name="phone" class="input" required placeholder="+1 555 010 2030"></div>
<div><label class="label" for="password">Password (min 8)</label><input id="password" type="password" name="password" class="input" required placeholder="Choose a password"></div>
<div><label class="label" for="reference">Reference</label><input id="reference" name="reference" class="input" placeholder="Referral name or code"></div>
<div><label class="label" for="document">License document (jpg/png/pdf)</label><input id="document" type="file" name="document" class="input" accept=".jpg,.jpeg,.png,.pdf"></div>
<button class="btn-gold touch-btn">Register</button></form>
<?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'Driver Registration | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/driver.php';
