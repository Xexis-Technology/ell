<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$vid = (int)($_GET['vehicle'] ?? 0);
$st = $pdo->prepare('SELECT v.*, c.name AS category, pr.per_mile_rate, pr.hourly_rate FROM vehicles v LEFT JOIN vehicle_categories c ON c.id = v.category_id LEFT JOIN pricing_rates pr ON pr.vehicle_id = v.id AND pr.active = 1 WHERE v.id = ? AND v.status = "active" AND v.is_temporary = 0 LIMIT 1');
$st->execute([$vid]);
$v = $st->fetch();
if (!$v) {
    http_response_code(404);
    $GLOBALS['__site_url'] = SITE_URL;
    require APP_ROOT . '/views/errors/404.php';
    exit;
}
[$photoId, $photoAlt] = vehicle_photo($v['category'] ?? null);
$others = $pdo->query('SELECT v.id, v.make, v.model, c.name AS category FROM vehicles v LEFT JOIN vehicle_categories c ON c.id = v.category_id WHERE v.status = "active" AND v.is_temporary = 0 AND v.id != ' . (int)$v['id'] . ' ORDER BY v.id LIMIT 3')->fetchAll();
ob_start();
?>
<div class="max-w-6xl mx-auto px-4 py-10">
  <p class="text-xs"><a class="underline text-[#AB8868]" href="<?= url('index.php#fleet') ?>">Fleet</a> <span class="text-[#AB8868]">/</span> <span class="text-[#F5F5F3]"><?= e($v['make'] . ' ' . $v['model']) ?></span></p>
  <div class="grid md:grid-cols-2 gap-8 mt-4 items-start">
    <div class="rounded-2xl overflow-hidden bg-gradient-to-br from-[#181819] to-[#AB8868]">
      <img src="https://images.unsplash.com/<?= $photoId ?>?auto=format&fit=crop&w=1000&q=60" alt="<?= e($photoAlt) ?>" class="w-full h-72 md:h-96 object-cover" onerror="this.style.display='none'">
    </div>
    <div>
      <p class="flight-code"><?= e(strtoupper($v['category'] ?? 'VEHICLE')) ?></p>
      <h1 class="font-display text-4xl md:text-5xl text-[#F9F9F9] mt-2"><?= e($v['make'] . ' ' . $v['model']) ?></h1>
      <dl class="grid grid-cols-2 gap-3 mt-6 text-sm">
        <div class="card rounded-xl p-4"><dt class="label">Year</dt><dd class="font-display text-2xl text-[#F3D4A6]"><?= e((string)$v['year']) ?></dd></div>
        <div class="card rounded-xl p-4"><dt class="label">Seats</dt><dd class="font-display text-2xl text-[#F3D4A6]">Up to <?= (int)$v['passenger_capacity'] ?></dd></div>
        <div class="card rounded-xl p-4"><dt class="label">Luggage</dt><dd class="font-display text-2xl text-[#F3D4A6]">Up to <?= (int)$v['luggage_capacity'] ?></dd></div>
        <div class="card rounded-xl p-4"><dt class="label">Rates</dt><dd class="tabular text-sm text-[#F3D4A6] font-semibold">
          <?php if ($v['per_mile_rate'] !== null): ?>$<?= money($v['per_mile_rate']) ?>/mi<br><?php endif; ?>
          <?php if ($v['hourly_rate'] !== null): ?>$<?= money($v['hourly_rate']) ?>/hr<?php endif; ?>
          <?php if ($v['per_mile_rate'] === null && $v['hourly_rate'] === null): ?>On request<?php endif; ?>
        </dd></div>
      </dl>
      <div class="grid grid-cols-2 gap-2 mt-6">
        <a href="<?= url('services/booking.php') ?>" class="btn-gold rounded-full text-center text-sm font-semibold px-4 py-3">Book this vehicle</a>
        <a href="<?= url('services/group-event.php') ?>" class="rounded-full text-center text-sm font-semibold px-4 py-3 border border-[#C8A96B] text-[#F3D4A6] hover:bg-[#D9B978]/10">Group inquiry</a>
      </div>
      <p class="text-xs text-[#AB8868] mt-4">Final vehicle assignment is confirmed by our dispatch team. Substitutions are like-for-like or better.</p>
    </div>
  </div>
  <?php if ($others): ?>
  <h2 class="font-display text-2xl text-[#F9F9F9] mt-12">Also in the fleet</h2>
  <div class="grid sm:grid-cols-3 gap-4 mt-4">
    <?php foreach ($others as $o):
      [$opId, $opAlt] = vehicle_photo($o['category'] ?? null);
    ?>
    <a href="<?= url('services/fleet.php?vehicle=' . (int)$o['id']) ?>" class="card rounded-2xl overflow-hidden group">
      <div class="h-36 bg-gradient-to-br from-[#0A0A0C] to-[#AB8868] overflow-hidden">
        <img src="https://images.unsplash.com/<?= $opId ?>?auto=format&fit=crop&w=600&q=60" alt="<?= e($opAlt) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" onerror="this.style.display='none'">
      </div>
      <p class="p-4 font-display text-lg text-[#F3D4A6]"><?= e($o['make'] . ' ' . $o['model']) ?></p>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = $v['make'] . ' ' . $v['model'] . ' | Fleet | Exotic Lane Limo';
$metaDesc = 'Details, seating, luggage and live rates for the ' . $v['make'] . ' ' . $v['model'] . ' in the Exotic Lane Limo fleet.';
require APP_ROOT . '/views/layouts/public.php';
