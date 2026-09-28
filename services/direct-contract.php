<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['contact_name'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');
    $errors = validate_required(['contact_name' => $name, 'contact_email' => $email, 'event_type' => trim($_POST['event_type'] ?? '')], ['contact_name','contact_email','event_type']);
    if (!validate_email($email)) $errors['contact_email'] = 'Invalid email.';
    if (!$errors) {
        $st = $pdo->prepare('INSERT INTO group_event_inquiries (event_type, event_dates, vehicle_count, estimated_passengers, locations, schedule, special_requirements, contact_name, contact_email, contact_phone, kind, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([trim($_POST['event_type'] ?? ''), trim($_POST['event_dates'] ?? ''), (int)($_POST['vehicle_count'] ?? 0) ?: null, (int)($_POST['estimated_passengers'] ?? 0) ?: null, trim($_POST['locations'] ?? ''), trim($_POST['schedule'] ?? ''), trim($_POST['special_requirements'] ?? ''), $name, $email, trim($_POST['contact_phone'] ?? ''), 'direct_contract', 'new']);
        NotificationService::send($pdo, 'inquiry-received', $email, 'We received your direct contract inquiry', '<p>Thank you — our team will respond.</p>', 'guest', null, null);
        $message = 'Thank you — your direct contract inquiry was received.';
    } else {
        $message = implode(' ', $errors);
    }
}
ob_start();
?>
<div class="max-w-7xl mx-auto px-4 py-10 md:py-14 grid lg:grid-cols-2 gap-8 items-stretch">
  <!-- Photo panel -->
  <div class="relative rounded-3xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868] min-h-[320px]">
    <img src="https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=1000&q=60" alt="Luxury sedan" class="absolute inset-0 w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'">
    <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C]/90 via-[#0A0A0C]/25 to-transparent"></div>
    <div class="absolute inset-x-0 bottom-0 p-6 md:p-8">
      <p class="tabular text-[10px] tracking-widest text-[#D9B978]">HOW CONTRACTS WORK</p>
      <ol class="mt-2 space-y-1.5 text-sm text-[#F5F5F3]">
        <li><strong>1.</strong> Describe the recurring need and routes.</li>
        <li><strong>2.</strong> We propose vehicles, terms and direct billing.</li>
        <li><strong>3.</strong> Ride on account, invoiced together.</li>
      </ol>
    </div>
  </div>
  <!-- Form -->
  <div>
    <h1 class="font-display text-4xl md:text-5xl text-[#F9F9F9]">Direct contract.</h1>
    <p class="text-sm text-[#AB8868] mt-2">Corporate accounts and recurring service — one agreement, invoiced together.</p>
    <?php if ($message): ?><div class="alert alert-ok mt-4"><?= e($message) ?></div><?php endif; ?>
    <form method="post" class="card rounded-2xl p-5 md:p-6 mt-6 space-y-3"><?= csrf_field() ?>
      <div><label class="label" for="event_type">Contract / service type</label><input id="event_type" name="event_type" class="input" required placeholder="Weekly executive shuttle…"></div>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label" for="event_dates">Dates</label><input id="event_dates" name="event_dates" class="input" placeholder="Ongoing / specific dates"></div>
        <div><label class="label" for="vehicle_count">Vehicles</label><input id="vehicle_count" type="number" min="1" name="vehicle_count" class="input" placeholder="1"></div>
      </div>
      <div><label class="label" for="estimated_passengers">Estimated passengers</label><input id="estimated_passengers" type="number" min="1" name="estimated_passengers" class="input" placeholder="4"></div>
      <div><label class="label" for="locations">Locations</label><textarea id="locations" name="locations" class="input" rows="2" placeholder="Office, airport, venues"></textarea></div>
      <div><label class="label" for="schedule">Schedule</label><textarea id="schedule" name="schedule" class="input" rows="2" placeholder="Weekdays 8 AM pickup…"></textarea></div>
      <div><label class="label" for="special_requirements">Special requirements</label><textarea id="special_requirements" name="special_requirements" class="input" rows="2" placeholder="Billing terms, vehicle preferences…"></textarea></div>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label" for="contact_name">Contact name</label><input id="contact_name" name="contact_name" class="input" required placeholder="Jane Smith"></div>
        <div><label class="label" for="contact_phone">Contact phone</label><input id="contact_phone" name="contact_phone" class="input" placeholder="+1 555 010 2030"></div>
      </div>
      <div><label class="label" for="contact_email">Contact email</label><input id="contact_email" type="email" name="contact_email" class="input" required placeholder="you@example.com"></div>
      <button class="btn-gold rounded-full w-full py-3.5 text-sm font-semibold">Request contract quote</button>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Direct Contract | Exotic Lane Limo';
$metaDesc = 'Corporate accounts and recurring chauffeured service inquiries with direct billing.';
$headerVariant = 'index';
require APP_ROOT . '/views/layouts/public.php';
