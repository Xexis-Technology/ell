<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$pc = page_content($pdo, 'contact');
$pu = static fn(string $k): string => url(PageContent::safe_link($pc[$k] ?? '', 'services/booking.php'));
$message = '';
$isErr = false;
$form = ['name' => '', 'email' => '', 'phone' => '', 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $body = trim($_POST['message'] ?? '');
    $errs = validate_required(['name' => $name, 'email' => $email, 'message' => $body], ['name','email','message']);
    if (!validate_email($email)) $errs['email'] = 'Invalid email.';
    if ($errs) {
        /* Errors render inline on the POST so the sender can correct one field
           without retyping the rest. */
        $message = implode(' ', $errs);
        $form = ['name' => $name, 'email' => $email, 'phone' => $phone, 'message' => $body];
        $isErr = true;
    } else {
        /* Store the message first. It used to send an email, log the address
           and discard everything else - so with mail switched off, a message
           sent through this form simply ceased to exist. This row is the real
           record; the email is a courtesy copy of it, not the other way round. */
        $st = $pdo->prepare('INSERT INTO contact_submissions (name, email, phone, message) VALUES (?,?,?,?)');
        $st->execute([$name, $email, ($phone !== '' ? $phone : null), $body]);
        $submissionId = (int)$pdo->lastInsertId();

        NotificationService::send($pdo, 'inquiry-received', $email, 'We received your message', '<p>Thank you ' . e($name) . ' — we will reply shortly.</p>', 'guest', null, null);
        audit($pdo, 'system', null, 'contact.submitted', 'contact_submission', $submissionId, ['email' => $email]);

        /* Redirect after success, never render it.
           The page used to answer the POST in place, which meant F5 - or a
           double-click on a slow connection, or a back-then-forward - posted
           the identical body again and saved another copy. Every duplicate that
           ever reached the admin inbox came from this. PRG makes the refresh a
           GET, so the message is stored exactly once no matter how hard the
           sender tries to send it twice. */
        header('Location: ' . url('legal/contact.php?sent=1'));
        exit;
    }
} elseif (isset($_GET['sent'])) {
    $message = 'Thank you — your message was received.';
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
      <p class="eyebrow"><?= e($pc['contact_eyebrow']) ?></p>
      <h1 class="font-display text-4xl md:text-5xl text-[#F9F9F9] mt-2"><?= e($pc['contact_title_lead']) ?><br><?= e($pc['contact_title_tail']) ?></h1>
      <p class="text-sm text-[#AB8868] mt-4 max-w-sm"><?= e($pc['contact_body']) ?> — write to us and we will reply shortly.</p>
      <div class="text-sm text-[#E5E5E3] mt-4 space-y-1">
        <?php if ($org['phone']): ?><p><?= e($org['phone']) ?></p><?php endif; ?>
        <?php if ($org['email']): ?><p><?= e($org['email']) ?></p><?php endif; ?>
        <?php if ($org['address']): ?><p><?= e($org['address']) ?></p><?php endif; ?>
      </div>
      <p class="mt-6"><a href="<?= e($pu('contact_cta_url')) ?>" class="rounded-full inline-block text-sm font-semibold px-6 py-2.5 bg-[#F9F9F9] text-[#0A0A0C]"><?= e($pc['contact_cta']) ?></a></p>
    </div>
    <!-- Form -->
    <form method="post" class="space-y-3"><?= csrf_field() ?>
      <?php if ($message): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($message) ?></div><?php endif; ?>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label" for="name">Full name</label><input id="name" name="name" class="input" required value="<?= e($form['name']) ?>" placeholder="Jane Smith"></div>
        <div><label class="label" for="phone">Phone</label><input id="phone" name="phone" class="input" value="<?= e($form['phone']) ?>" placeholder="+1 555 010 2030"></div>
      </div>
      <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input" required value="<?= e($form['email']) ?>" placeholder="you@example.com"></div>
      <div><label class="label" for="message">Message</label><textarea id="message" name="message" class="input" rows="5" required placeholder="How can we help?"><?= e($form['message'] ?? '') ?></textarea></div>
      <div class="text-right"><button class="btn-gold rounded-full text-sm font-semibold px-8 py-3"><?= e($pc['contact_form_cta']) ?></button></div>
    </form>
  </div>

  <!-- Reassurance strip -->
  <div class="grid md:grid-cols-2 gap-6 mt-10 hairline-t hairline-b py-6">
    <p class="text-sm text-[#E5E5E3]"><strong class="text-[#F9F9F9]"><?= e($pc['strip_1_title']) ?></strong> <?= e($pc['strip_1_body']) ?> <a class="underline text-[#F3D4A6]" href="<?= e($pu('strip_1_link_url')) ?>"><?= e($pc['strip_1_link']) ?></a>.</p>
    <p class="text-sm text-[#E5E5E3]"><strong class="text-[#F9F9F9]"><?= e($pc['strip_2_title']) ?></strong> <?= e($pc['strip_2_body']) ?> <a class="underline text-[#F3D4A6]" href="<?= e($pu('strip_2_link_url')) ?>"><?= e($pc['strip_2_link']) ?></a>.</p>
  </div>

  <!-- Facts -->
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-8 mt-10">
    <div><p class="w-9 h-9 rounded-full bg-[#D9B978]/15 text-[#D9B978] inline-flex items-center justify-center font-bold" aria-hidden="true">$</p>
      <h2 class="font-display text-xl text-[#F9F9F9] mt-3"><?= e($pc['contact_fact_1_title']) ?></h2>
      <p class="text-sm text-[#AB8868] mt-1"><?= e($pc['contact_fact_1_body']) ?></p></div>
    <div><p class="w-9 h-9 rounded-full bg-[#D9B978]/15 text-[#D9B978] inline-flex items-center justify-center font-bold" aria-hidden="true">◷</p>
      <h2 class="font-display text-xl text-[#F9F9F9] mt-3"><?= e($pc['contact_fact_2_title']) ?></h2>
      <p class="text-sm text-[#AB8868] mt-1"><?= e($pc['contact_fact_2_body']) ?></p></div>
    <div><p class="w-9 h-9 rounded-full bg-[#D9B978]/15 text-[#D9B978] inline-flex items-center justify-center font-bold" aria-hidden="true">◈</p>
      <h2 class="font-display text-xl text-[#F9F9F9] mt-3"><?= e($pc['contact_fact_3_title']) ?></h2>
      <p class="text-sm text-[#AB8868] mt-1"><?= e($pc['contact_fact_3_body']) ?></p></div>
    <div><p class="w-9 h-9 rounded-full bg-[#D9B978]/15 text-[#D9B978] inline-flex items-center justify-center font-bold" aria-hidden="true">✓</p>
      <h2 class="font-display text-xl text-[#F9F9F9] mt-3"><?= e($pc['contact_fact_4_title']) ?></h2>
      <p class="text-sm text-[#AB8868] mt-1"><?= e($pc['contact_fact_4_body']) ?></p></div>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Contact | Exotic Lane Limo';
$metaDesc = 'Contact Exotic Lane Limo for reservations, quotes and support.';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
