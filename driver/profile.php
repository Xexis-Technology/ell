<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $phone = trim($_POST['phone'] ?? '');
    $payout = trim($_POST['payout_reference'] ?? '');
    $pdo->prepare('UPDATE drivers SET phone = ?, payout_reference = ? WHERE id = ?')->execute([$phone ?: null, $payout ?: null, (int)$driver['id']]);
    if (isset($_FILES['document']) && ($_FILES['document']['error'] ?? 4) === UPLOAD_ERR_OK) {
        [$path, $err] = secure_upload($_FILES['document'], 'drivers');
        if ($err) $msg = $err;
        else $pdo->prepare('INSERT INTO driver_documents (driver_id, doc_type, file_path, status) VALUES (?,?,?,?)')->execute([(int)$driver['id'], trim($_POST['doc_type'] ?? 'document'), $path, 'pending']);
    }
    if ($msg === '') $msg = 'Profile updated.';
    audit($pdo, 'driver', (int)$driver['id'], 'profile.updated', 'driver', (int)$driver['id'], null);
    header('Location: ' . url('driver/profile.php?msg=' . urlencode($msg)));
    exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
$st = $pdo->prepare('SELECT * FROM drivers WHERE id = ? LIMIT 1');
$st->execute([$driver['id']]);
$d = $st->fetch();
$st = $pdo->prepare('SELECT * FROM driver_documents WHERE driver_id = ? ORDER BY id DESC');
$st->execute([$driver['id']]);
$docs = $st->fetchAll();
ob_start();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<h1 class="font-display text-3xl text-[#F3D4A6]">Profile</h1>
<div class="ride-card mt-4 text-sm"><p><strong>Name:</strong> <?= e($d['name']) ?></p><p><strong>Email:</strong> <?= e($d['email']) ?></p><p><strong>Status:</strong> <?= e($d['status']) ?></p></div>
<form method="post" enctype="multipart/form-data" class="ride-card space-y-2"><?= csrf_field() ?>
<div><label class="label" for="phone">Mobile</label><input id="phone" name="phone" class="input" value="<?= e((string)($d['phone'] ?? '')) ?>"></div>
<div><label class="label" for="payout_reference">Payout information</label><input id="payout_reference" name="payout_reference" class="input" value="<?= e((string)($d['payout_reference'] ?? '')) ?>"></div>
<div><label class="label" for="doc_type">Document type</label><input id="doc_type" name="doc_type" class="input" placeholder="license / insurance"></div>
<div><label class="label" for="document">Upload document</label><input id="document" type="file" name="document" class="input" accept=".jpg,.jpeg,.png,.pdf"></div>
<button class="btn-gold touch-btn">Save</button></form>
<h2 class="font-display text-xl mt-4">Documents</h2>
<?php foreach ($docs as $doc): ?><div class="ride-card text-sm"><p><?= e($doc['doc_type']) ?> — <?= e($doc['status']) ?> · <?= e((string)($doc['expiry_date'] ?? '')) ?></p></div><?php endforeach; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'Profile';
require APP_ROOT . '/views/layouts/driver.php';
