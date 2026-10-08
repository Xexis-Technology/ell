<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
require_role('admin');

/**
 * An audit log is read for two different questions, and they do not want the
 * same page.
 *
 * "What changed?" is the working question - the edits to fares, bookings and
 * vehicles. On this install 333 of the 422 rows are sign-ins from one address,
 * so a single undifferentiated list buries the 89 rows that carry information.
 * Sign-ins are kept as their own view because they have their own question:
 * the repetitive ones are background, the unusual ones are a security
 * concern, and neither can be seen while they are mixed in with everything
 * else.
 */

$view = (string)($_GET['view'] ?? 'activity');
if (!in_array($view, ['activity', 'signins'], true)) $view = 'activity';

$who = (string)($_GET['who'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$day = (string)($_GET['day'] ?? '');

/**
 * Who did it. The stored row carries only a role and an id, which reads as
 * "admin 2" on a page whose whole purpose is naming who acted.
 */
$people = [];
foreach ([
    'admin' => ['admins', 'name'],
    'customer' => ['customers', 'name'],
    'driver' => ['drivers', 'name'],
] as $role => [$table, $col]) {
    try {
        foreach ($pdo->query("SELECT id, $col FROM $table") as $r) {
            $people[$role][(int)$r['id']] = (string)$r[$col];
        }
    } catch (Throwable) {
        $people[$role] = [];
    }
}

/** Vehicles, so an assignment reads as a car rather than a row id. */
$vehicles = [];
try {
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM vehicles') as $c) $cols[] = $c['Field'];
    $plate = in_array('plate', $cols, true) ? ', plate' : '';
    $model = in_array('model', $cols, true) ? ', model' : '';
    foreach ($pdo->query("SELECT id$plate$model FROM vehicles") as $r) {
        $bits = array_filter([trim((string)($r['model'] ?? '')), trim((string)($r['plate'] ?? ''))]);
        $vehicles[(int)$r['id']] = $bits === [] ? ('Vehicle ' . (int)$r['id']) : implode(' · ', $bits);
    }
} catch (Throwable) {
    $vehicles = [];
}

// ---- query -----------------------------------------------------------------
$where = [];
$params = [];
if ($view === 'signins') {
    $where[] = "action IN ('auth.login','auth.logout','auth.password_reset','auth.register')";
} else {
    $where[] = "action NOT IN ('auth.login','auth.logout')";
}
if ($who !== '' && in_array($who, ['admin', 'customer', 'driver', 'system'], true)) {
    $where[] = 'actor_type = ?';
    $params[] = $who;
}
if ($day !== '') {
    $where[] = 'DATE(created_at) = ?';
    $params[] = $day;
}
if ($q !== '') {
    // Search the human sentence too, not just the stored key - the reader
    // knows "refund", not "payment.refund".
    $where[] = '(action LIKE ? OR entity_type LIKE ? OR metadata LIKE ? OR ip_address LIKE ? OR actor_type LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}
$sql = 'SELECT * FROM audit_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 300';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Count for the headline, so the number shown is the number in the table.
$countSql = 'SELECT COUNT(*) FROM audit_logs WHERE ' . implode(' AND ', $where);
$cnt = $pdo->prepare($countSql);
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

$totalAll = (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$loginCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('auth.login','auth.logout')")->fetchColumn();

// Who is actually acting, for the filter chips.
$whoCounts = [];
foreach ($pdo->query('SELECT actor_type, COUNT(*) c FROM audit_logs GROUP BY actor_type ORDER BY c DESC') as $r) {
    $whoCounts[(string)$r['actor_type']] = (int)$r['c'];
}

// Sign-in origins. This is the number that answers "is anyone else in here".
$origins = [];
foreach ($pdo->query("SELECT ip_address, COUNT(*) c FROM audit_logs WHERE action IN ('auth.login','auth.logout') GROUP BY ip_address ORDER BY c DESC LIMIT 8") as $r) {
    $origins[] = ['ip' => (string)($r['ip_address'] ?? ''), 'n' => (int)$r['c']];
}
$originMax = $origins ? max(array_column($origins, 'n')) : 1;

$days = [];
foreach ($pdo->query('SELECT DATE(created_at) d, COUNT(*) c FROM audit_logs GROUP BY d ORDER BY d DESC LIMIT 14') as $r) {
    $days[] = ['d' => (string)$r['d'], 'c' => (int)$r['c']];
}

// Group the visible rows into days for reading.
$byDay = [];
foreach ($rows as $r) {
    $d = substr((string)$r['created_at'], 0, 10);
    $byDay[$d][] = $r;
}

/**
 * Collapse runs of identical sign-ins.
 *
 * Three hundred rows that all say "QA Admin signed in from ::1" is the same
 * fact written three hundred times, and burying the two sign-ins that differ
 * inside them is exactly the failure this view exists to prevent. A run
 * becomes one row carrying the count and the span it covered, so an
 * individual sign-in is still reachable by narrowing with the search.
 */
if ($view === 'signins') {
    $collapsed = [];
    foreach ($byDay as $day => $dayRows) {
        $run = null;
        foreach ($dayRows as $r) {
            $sig = (string)$r['action'] . '|' . (string)$r['actor_id'] . '|' . (string)$r['ip_address'] . '|' . (string)$r['user_agent'];
            if ($run !== null && $run['sig'] === $sig) {
                $run['n']++;
                $run['to'] = (string)$r['created_at'];
                continue;
            }
            if ($run !== null) $collapsed[] = $run;
            $run = ['sig' => $sig, 'row' => $r, 'n' => 1, 'to' => (string)$r['created_at']];
        }
        if ($run !== null) $collapsed[] = $run;
        $byDay[$day] = $collapsed;
        $collapsed = [];
    }
}

$anyFilter = $q !== '' || $who !== '' || $day !== '';
ob_start();
?>
<div class="au-head">
  <?php
// "since" needs the oldest day on file. array_key_first would give the most
// recent, which reads as though the log began yesterday.
try {
    $oldest = (string)$pdo->query('SELECT DATE(MIN(created_at)) d FROM audit_logs')->fetchColumn();
    $oldestTs = $oldest !== '' ? strtotime($oldest) : false;
} catch (Throwable) {
    $oldest = ''; $oldestTs = false;
}
$oldestLabel = $oldestTs !== false ? date('j M Y', $oldestTs) : 'recently';
?>
  <div>
    <p class="eyebrow">System</p>
    <h1 class="font-display">Audit log</h1>
    <p class="au-sub">Every change recorded on this site. <?= $totalAll ?> entries<?= $oldest !== '' ? ' since ' . e($oldestLabel) : '' ?>.
      <?php if ($loginCount > 0): ?>
        <?= $loginCount ?> of them are sign-ins, which is why they have their own view.
      <?php endif; ?>
    </p>
  </div>
</div>

<nav class="au-views" aria-label="Views">
  <a class="au-view<?= $view === 'activity' ? ' is-on' : '' ?>" href="<?= url('admin/audit.php?view=activity') ?>"<?= $view === 'activity' ? ' aria-current="page"' : '' ?>>
    <span class="au-view-l">Activity</span>
    <span class="au-view-s">Everything that changed something</span>
  </a>
  <a class="au-view<?= $view === 'signins' ? ' is-on' : '' ?>" href="<?= url('admin/audit.php?view=signins') ?>"<?= $view === 'signins' ? ' aria-current="page"' : '' ?>>
    <span class="au-view-l">Sign-ins</span>
    <span class="au-view-s">Who got in, and from where</span>
  </a>
</nav>

<form method="get" class="au-filters">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <div class="au-who" role="group" aria-label="Who did it">
    <?php foreach ($whoCounts as $wt => $wc): ?>
      <a class="au-chip<?= $who === (string)$wt ? ' is-on' : '' ?>" href="<?= url('admin/audit.php?' . http_build_query(['view' => $view, 'who' => $wt, 'day' => $day, 'q' => $q])) ?>">
        <?= e(audit_state((string)$wt)) ?><b><?= $wc ?></b>
      </a>
    <?php endforeach; ?>
    <?php if ($who !== ''): ?>
      <a class="au-chip is-clear" href="<?= url('admin/audit.php?' . http_build_query(['view' => $view, 'day' => $day, 'q' => $q])) ?>">Clear</a>
    <?php endif; ?>
  </div>
  <div class="au-search">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Refund, booking 93207414, an address…" aria-label="Search the log">
    <button type="submit">Search</button>
  </div>
</form>

<?php if ($view === 'signins' && $origins !== []): ?>
<section class="au-origins">
  <p class="au-origins-h">Where sign-ins come from</p>
  <?php foreach ($origins as $o): ?>
  <div class="au-origin">
    <span class="au-origin-ip"><?= e($o['ip'] !== '' ? $o['ip'] : 'unknown') ?></span>
    <span class="au-origin-bar" aria-hidden="true"><i style="width:<?= (int)round($o['n'] / max(1, $originMax) * 100) ?>%"></i></span>
    <span class="au-origin-n"><?= $o['n'] ?></span>
  </div>
  <?php endforeach; ?>
  <p class="au-origins-note">One address holding every entry usually means one machine - a local install, or a test run.
    An unfamiliar address on this list is the thing worth acting on.</p>
</section>
<?php endif; ?>

<?php if ($rows === []): ?>
<div class="au-empty">
  <p class="au-empty-t"><?= $anyFilter ? 'Nothing matches that' : 'No entries here yet' ?></p>
  <p><?= $anyFilter
    ? 'Try a different word, or clear the filters to see everything.'
    : 'Changes will be listed here as they are made.' ?></p>
  <?php if ($anyFilter): ?>
    <a class="au-empty-btn" href="<?= url('admin/audit.php?view=' . e($view)) ?>">Clear the filters</a>
  <?php endif; ?>
</div>
<?php else: ?>
<p class="au-count">
  <?php if ($total > count($rows)): ?>
    Showing the <?= count($rows) ?> most recent of <?= $total ?> matching.
  <?php else: ?>
    <?= $total ?> <?= $total === 1 ? 'entry' : 'entries' ?><?= $anyFilter ? ' matching' : '' ?>.
  <?php endif; ?>
</p>

<div class="au-log">
<?php foreach ($byDay as $dayKey => $dayRows):
  $ts = strtotime($dayKey); ?>
  <section class="au-day">
    <header class="au-day-h">
      <h2><?= e($dayKey) ?></h2>
      <span><?= e(date('D j M', $ts !== false ? $ts : time())) ?></span>
      <b><?= count($dayRows) ?></b>
    </header>
    <ol class="au-entries">
    <?php foreach ($dayRows as $item):
      // A collapsed run keeps the first entry's row and carries the count.
      $isRun = is_array($item) && isset($item['sig']);
      $r = $isRun ? $item['row'] : $item;
      $runN = $isRun ? (int)$item['n'] : 1;
      $who2 = actor_name($r, $people);
      [$verb, $detail] = audit_sentence($r, $people, $vehicles);
      $isSystem = (string)$r['actor_type'] === 'system';
      $from = substr((string)$r['created_at'], 11, 8);
      // Rows arrive newest first, so the first row of a run is its end and the
      // last is its start. A range therefore reads oldest to newest.
      $to = $isRun ? substr((string)$item['to'], 11, 8) : '';
      $span = ($runN > 1 && $to !== '' && $to !== $from) ? $to . ' → ' . $from : $from;
      if ($runN > 1) {
        $detail = $runN === 1 ? 'once' : $runN . ' times';
      } ?>
      <li class="au-entry<?= $isSystem ? ' is-system' : '' ?><?= $runN > 1 ? ' is-run' : '' ?>">
        <time class="au-when" datetime="<?= e(str_replace(' ', 'T', (string)$r['created_at'])) ?>">
          <b><?= e($span) ?></b>
          <?php if ($runN > 1): ?><span class="au-run">×<?= $runN ?></span><?php endif; ?>
        </time>
        <div class="au-what">
          <p class="au-verb"><?= e($verb) ?></p>
          <?php if ($detail !== ''): ?><p class="au-detail"><?= e($detail) ?></p><?php endif; ?>
        </div>
        <div class="au-who-col">
          <span class="au-actor"><?= e($who2) ?></span>
          <span class="au-role"><?= e(audit_state((string)$r['actor_type'])) ?></span>
        </div>
        <div class="au-from">
          <span class="au-ip"><?= e((string)($r['ip_address'] ?? '')) ?></span>
          <span class="au-agent"><?= e(audit_device((string)($r['user_agent'] ?? ''))) ?></span>
        </div>
      </li>
    <?php endforeach; ?>
    </ol>
  </section>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'Audit Log | Admin';
$navActive = 'audit.php';
require APP_ROOT . '/views/layouts/admin.php';