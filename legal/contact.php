<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
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
<div class="max-w-7xl mx-auto px-4 pt-6 pb-12">
  <div class="bg-[#0F0F0E] border border-[#262628] rounded-3xl p-6 md:p-12 grid lg:grid-cols-2 gap-10">
    <!-- Left -->
    <div class="flex flex-col justify-center">
      <p class="eyebrow">Contact</p>
      <h1 class="font-display text-4xl md:text-5xl text-[#F9F9F9] mt-2">Talk to<br>our team.</h1>
      <p class="text-sm text-[#AB8868] mt-4 max-w-sm">Questions about a booking, a quote or an upcoming ride — write to us and we will reply shortly.</p>
      <div class="text-sm text-[#E5E5E3] mt-4 space-y-1">
        <?php if ($org['phone']): ?><p><?= e($org['phone']) ?></p><?php endif; ?>
        <?php if ($org['email']): ?><p><?= e($org['email']) ?></p><?php endif; ?>
        <?php if ($org['address']): ?><p><?= e($org['address']) ?></p><?php endif; ?>
      </div>
      <p class="mt-6"><a href="<?= url('services/booking.php') ?>" class="rounded-full inline-block text-sm font-semibold px-6 py-2.5 bg-[#F9F9F9] text-[#0A0A0C]">Book a ride</a></p>
    </div>
    <!-- Form -->
    <form method="post" class="space-y-3"><?= csrf_field() ?>
      <?php if ($message): ?><div class="alert alert-ok"><?= e($message) ?></div><?php endif; ?>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label" for="name">Full name</label><input id="name" name="name" class="input" required placeholder="Jane Smith"></div>
        <div><label class="label" for="phone">Phone</label><input id="phone" name="phone" class="input" placeholder="+1 555 010 2030"></div>
      </div>
      <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input" required placeholder="you@example.com"></div>
      <div><label class="label" for="message">Message</label><textarea id="message" name="message" class="input" rows="5" required placeholder="How can we help?"></textarea></div>
      <div class="text-right"><button class="btn-gold rounded-full text-sm font-semibold px-8 py-3">Contact us</button></div>
    </form>
  </div>

  <!-- Reassurance strip -->
  <div class="grid md:grid-cols-2 gap-6 mt-10 hairline-t hairline-b py-6">
    <p class="text-sm text-[#E5E5E3]"><strong class="text-[#F9F9F9]">Booking instead?</strong> Start online — your price is locked before you pay. <a class="underline text-[#F3D4A6]" href="<?= url('services/booking.php') ?>">Book a ride</a>.</p>
    <p class="text-sm text-[#E5E5E3]"><strong class="text-[#F9F9F9]">Ride-day change?</strong> Move your pickup until 2 hours before, from your account. <a class="underline text-[#F3D4A6]" href="<?= url('account/index.php') ?>">My account</a>.</p>
  </div>

  <!-- Facts -->
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-8 mt-10">
    <div><p class="w-9 h-9 rounded-full bg-[#D9B978]/15 text-[#D9B978] inline-flex items-center justify-center font-bold" aria-hidden="true">$</p>
      <h2 class="font-display text-xl text-[#F9F9F9] mt-3">Fixed pricing</h2>
      <p class="text-sm text-[#AB8868] mt-1">Totals calculated on our servers, frozen before payment.</p></div>
    <div><p class="w-9 h-9 rounded-full bg-[#D9B978]/15 text-[#D9B978] inline-flex items-center justify-center font-bold" aria-hidden="true">◷</p>
      <h2 class="font-display text-xl text-[#F9F9F9] mt-3">Waiting included</h2>
      <p class="text-sm text-[#AB8868] mt-1">60 min at airports, 30 at terminals, 15 point-to-point.</p></div>
    <div><p class="w-9 h-9 rounded-full bg-[#D9B978]/15 text-[#D9B978] inline-flex items-center justify-center font-bold" aria-hidden="true">◈</p>
      <h2 class="font-display text-xl text-[#F9F9F9] mt-3">Secure payment</h2>
      <p class="text-sm text-[#AB8868] mt-1">Stripe checkout and an invoice on every booking.</p></div>
    <div><p class="w-9 h-9 rounded-full bg-[#D9B978]/15 text-[#D9B978] inline-flex items-center justify-center font-bold" aria-hidden="true">✓</p>
      <h2 class="font-display text-xl text-[#F9F9F9] mt-3">Real chauffeurs</h2>
      <p class="text-sm text-[#AB8868] mt-1">Vetted drivers, dispatched and confirmed by email.</p></div>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Contact | Exotic Lane Limo';
$metaDesc = 'Contact Exotic Lane Limo for reservations, quotes and support.';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
