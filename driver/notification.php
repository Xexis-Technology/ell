<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$driver = require_role('driver');
$did = (int)$driver['id'];
$fresh = DriverPortal::fresh($pdo, $did);
$clearance = DriverPortal::clearance($pdo, $fresh);

// Opening this page is what "seen" means, so the badge empties the moment the
// driver arrives. Done before the feed is read so the header - which asks the
// same class how many are unseen - renders the number this visit just cleared.
DriverNotices::markSeen($pdo, $did);

$events = DriverNotices::forDriver($pdo, $did);

/* Anything on the driver's own file that is about to become a problem. Shown
   first because an empty log that opens with bad news is not empty. */
$attention = [];
$licDays = days_until($fresh['license_expiry'] ?? null);
if ($licDays !== null && $licDays <= 45) {
    $attention[] = ['title' => 'Driving licence',
        'detail' => $licDays < 0
            ? 'Expired ' . abs($licDays) . ' days ago. Send a new photo.'
            : ($licDays === 0 ? 'Expires today.' : 'Expires in ' . $licDays . ' days. Send a new photo when you have it.'),
        'href' => url('driver/settings.php')];
}
$medDays = days_until($fresh['medical_cert_expiry'] ?? null);
if ($medDays !== null && $medDays <= 60) {
    $attention[] = ['title' => 'Medical certificate',
        'detail' => $medDays < 0 ? 'Expired ' . abs($medDays) . ' days ago. Tell the office.'
            : ($medDays === 0 ? 'Expires today.' : 'Expires in ' . $medDays . ' days.'),
        'href' => url('driver/settings.php')];
}
$st = $pdo->prepare('SELECT doc_type FROM driver_documents WHERE driver_id = ? AND file_path IS NOT NULL');
$st->execute([$did]);
$hasLicence = false;
$hasPicture = false;
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if (in_array($t, ['photo', 'selfie'], true)) $hasPicture = true; else $hasLicence = true;
}
if (!$hasLicence) {
    $attention[] = ['title' => 'No licence photo on file',
        'detail' => 'The office needs one before you can be cleared.', 'href' => url('driver/settings.php')];
}
if (!$hasPicture) {
    $attention[] = ['title' => 'No picture on file',
        'detail' => 'Guests get a manifest with your face on it.', 'href' => url('driver/settings.php')];
}
$st = $pdo->prepare("SELECT COUNT(*) FROM driver_documents WHERE driver_id = ? AND status = 'rejected'");
$st->execute([$did]);
if ((int)$st->fetchColumn() > 0) {
    $attention[] = ['title' => 'A photo was turned down',
        'detail' => 'Take it again and send a clearer one.', 'href' => url('driver/settings.php')];
}

$FILTERS = [
    'all' => 'Everything',
    'assigned' => 'Assigned',
    'done' => 'Done',
    'action' => 'Action needed',
    'info' => 'Updates',
];
$active = (string)($_GET['kind'] ?? 'all');
if (!isset($FILTERS[$active])) $active = 'all';
$shown = $active === 'all' ? $events : array_values(array_filter($events, static fn($x) => $x['kind'] === $active));

$counts = ['all' => count($events)];
foreach (DriverNotices::KINDS as $k) {
    $counts[$k] = count(array_filter($events, static fn($x) => $x['kind'] === $k));
}

$ago = static function (int $ts): string {
    $d = time() - $ts;
    if ($d < 60) return 'just now';
    if ($d < 3600) return intdiv($d, 60) . ' min ago';
    if ($d < 86400) return intdiv($d, 3600) . ' hr ago';
    if ($d < 604800) return intdiv($d, 86400) . 'd ago';
    return date('j M Y', $ts);
};

ob_start();
?>
<div class="note-page">

  <header class="note-head">
    <p class="sheet-when"><span class="drv-k">Your log</span> <?= e(strtoupper(date('D j M'))) ?></p>
    <h1 class="sheet-name">Notices</h1>
    <p class="note-sub">Everything that has happened on your file. Opening this page marks it read.</p>
  </header>

  <?php if ($attention): ?>
    <section class="note-alert">
      <span class="note-alert-h"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Needs you</span>
      <ul class="note-alert-list">
        <?php foreach ($attention as $a): ?>
          <li>
            <b><?= e($a['title']) ?></b>
            <span><?= e($a['detail']) ?></span>
            <?php if ($a['href']): ?><a href="<?= $a['href'] ?>">Fix this</a><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <nav class="note-tabs" aria-label="Filter notices">
    <?php foreach ($FILTERS as $k => $label): ?>
      <?php if (($counts[$k] ?? 0) === 0 && $k !== 'all') continue; ?>
      <a class="note-tab<?= $active === $k ? ' is-on' : '' ?>" href="<?= url('driver/notification.php?kind=' . $k) ?>">
        <?= e($label) ?><b><?= (int)($counts[$k] ?? 0) ?></b>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if ($shown): ?>
    <ul class="note-list">
      <?php foreach ($shown as $ev): ?>
        <li class="note-row is-<?= e($ev['kind']) ?>">
          <span class="note-kind" aria-hidden="true"><i class="fa-solid <?= e(DriverNotices::ICONS[$ev['kind']]) ?>"></i></span>
          <span class="note-body">
            <b class="note-title"><?= e($ev['title']) ?></b>
            <?php if ($ev['detail'] !== ''): ?><span class="note-detail"><?= e($ev['detail']) ?></span><?php endif; ?>
          </span>
          <span class="note-when"><?= e($ago($ev['when'])) ?></span>
          <span class="note-tag"><?= e(DriverNotices::LABELS[$ev['kind']]) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="note-empty">
      <span class="note-empty-mark" aria-hidden="true"></span>
      <p>Nothing here yet. Jobs you are given, money that moves and changes to your
         file all land in this list.</p>
    </div>
  <?php endif; ?>

</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Notices | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/driver.php';