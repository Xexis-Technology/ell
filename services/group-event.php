<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $data = [
        'event_type' => trim($_POST['event_type'] ?? ''),
        'event_dates' => trim($_POST['event_dates'] ?? ''),
        'vehicle_count' => (int)($_POST['vehicle_count'] ?? 0) ?: null,
        'estimated_passengers' => (int)($_POST['estimated_passengers'] ?? 0) ?: null,
        'locations' => trim($_POST['locations'] ?? ''),
        'schedule' => trim($_POST['schedule'] ?? ''),
        'special_requirements' => trim($_POST['special_requirements'] ?? ''),
        'contact_name' => trim($_POST['contact_name'] ?? ''),
        'contact_email' => trim($_POST['contact_email'] ?? ''),
        'contact_phone' => trim($_POST['contact_phone'] ?? ''),
    ];
    $errors = validate_required($data, ['event_type','contact_name','contact_email']);
    if (!validate_email($data['contact_email'])) $errors['contact_email'] = 'Invalid email.';
    if (!$errors) {
        $st = $pdo->prepare('INSERT INTO group_event_inquiries (event_type, event_dates, vehicle_count, estimated_passengers, locations, schedule, special_requirements, contact_name, contact_email, contact_phone, kind, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$data['event_type'],$data['event_dates'],$data['vehicle_count'],$data['estimated_passengers'],$data['locations'],$data['schedule'],$data['special_requirements'],$data['contact_name'],$data['contact_email'],$data['contact_phone'],'group_event','new']);
        $id = (int)$pdo->lastInsertId();
        audit($pdo, 'system', null, 'inquiry.created', 'inquiry', $id, ['kind' => 'group_event']);
        NotificationService::send($pdo, 'inquiry-received', $data['contact_email'], 'We received your group/event inquiry', '<p>Thank you ' . e($data['contact_name']) . ' — our team will respond with a quote.</p>', 'guest', null, null);
        $message = 'Thank you — your inquiry was received. Our team will respond shortly.';
    } else {
        $message = implode(' ', $errors);
    }
}
ob_start();
?>
<div class="max-w-3xl mx-auto px-4 py-12">
  <h1 class="font-display text-4xl text-[#F9F9F9]">Group &amp; Event Transportation</h1>
  <p class="text-sm text-[#AB8868] mt-1">Tell us about your event — we respond with a basic quote.</p>
  <?php if ($message): ?><div class="alert alert-ok mt-4"><?= e($message) ?></div><?php endif; ?>
  <form method="post" class="card p-6 mt-6 space-y-4"><?= csrf_field() ?>
    <div><label class="label" for="event_type">Event type</label><input id="event_type" name="event_type" class="input" required></div>
    <div class="grid md:grid-cols-2 gap-4">
      <div><label class="label" for="event_dates">Dates</label><input id="event_dates" name="event_dates" class="input"></div>
      <div><label class="label" for="vehicle_count">Vehicle count</label><input id="vehicle_count" type="number" min="1" name="vehicle_count" class="input"></div>
    </div>
    <div><label class="label" for="estimated_passengers">Estimated passengers</label><input id="estimated_passengers" type="number" min="1" name="estimated_passengers" class="input"></div>
    <div><label class="label" for="locations">Locations</label><textarea id="locations" name="locations" class="input" rows="2"></textarea></div>
    <div><label class="label" for="schedule">Schedule</label><textarea id="schedule" name="schedule" class="input" rows="2"></textarea></div>
    <div><label class="label" for="special_requirements">Special requirements</label><textarea id="special_requirements" name="special_requirements" class="input" rows="2"></textarea></div>
    <div class="grid md:grid-cols-3 gap-4">
      <div><label class="label" for="contact_name">Contact name</label><input id="contact_name" name="contact_name" class="input" required></div>
      <div><label class="label" for="contact_email">Contact email</label><input id="contact_email" type="email" name="contact_email" class="input" required></div>
      <div><label class="label" for="contact_phone">Contact phone</label><input id="contact_phone" name="contact_phone" class="input"></div>
    </div>
    <button class="btn-cta">Submit Inquiry</button>
  </form>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Group & Event | Exotic Lane Limo';
$metaDesc = 'Group and event transportation inquiries with prompt admin quotes for multi-vehicle needs.';
require APP_ROOT . '/views/layouts/public.php';
