<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $body = trim($_POST['message'] ?? '');
    $errs = validate_required(['name' => $name, 'email' => $email, 'message' => $body], ['name','email','message']);
    if (!validate_email($email)) $errs['email'] = 'Invalid email.';
    if ($errs) $message = implode(' ', $errs);
    else {
        NotificationService::send($pdo, 'inquiry-received', $email, 'We received your message', '<p>Thank you ' . e($name) . ' — we will reply shortly.</p>', 'guest', null, null);
        audit($pdo, 'system', null, 'contact.submitted', null, null, ['email' => $email]);
        $message = 'Thank you — your message was received.';
    }
}
$org = ['phone' => '', 'email' => '', 'address' => ''];
try {
    foreach (['org_phone','org_email','org_address'] as $k) $org[str_replace('org_','',$k)] = (string)(setting($pdo, $k, ''));
} catch (Throwable) {}
ob_start();
?>
<div class="max-w-3xl mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Contact Us</h1>
  <?php if ($org['phone'] || $org['email'] || $org['address']): ?>
  <p class="text-sm text-[#AB8868] mt-2"><?= e($org['phone']) ?> <?= e($org['email']) ?> <?= e($org['address']) ?></p>
  <?php endif; ?>
  <?php if ($message): ?><div class="alert alert-ok mt-4"><?= e($message) ?></div><?php endif; ?>
  <form method="post" class="card p-6 mt-6 space-y-4"><?= csrf_field() ?>
    <div><label class="label" for="name">Name</label><input id="name" name="name" class="input" required></div>
    <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input" required></div>
    <div><label class="label" for="message">Message</label><textarea id="message" name="message" class="input" rows="5" required></textarea></div>
    <button class="btn-cta">Send Message</button>
  </form>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Contact | Exotic Lane Limo';
$metaDesc = 'Contact Exotic Lane Limo for reservations, quotes and support.';
require APP_ROOT . '/views/layouts/public.php';
