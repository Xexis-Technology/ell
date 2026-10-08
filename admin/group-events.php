<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');

// The pipeline this page works. These three are a real progression; declined and
// closed are terminal states, not further steps, so they get their own control.
$STAGES = ['new', 'quoted', 'confirmed'];
$STAGE_LABEL = ['new' => 'New', 'quoted' => 'Quoted', 'confirmed' => 'Confirmed', 'declined' => 'Declined', 'closed' => 'Closed'];
$STAGE_RANK = ['new' => 0, 'quoted' => 1, 'confirmed' => 2, 'declined' => 3, 'closed' => 4];

$plural = static fn(int $n, string $unit): string => $n . ' ' . $unit . ($n === 1 ? '' : 's');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = (string)($_POST['op'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $keep = static fn(string $extraMsg, bool $extraErr): string => url('admin/group-events.php?' . http_build_query(
        array_filter([
            'i' => $id ?: null,
            'f' => trim((string)($_POST['f'] ?? '')),
            'k' => trim((string)($_POST['k'] ?? '')),
            'q' => trim((string)($_POST['q'] ?? '')),
        ]) + ['msg' => $extraMsg, 'err' => $extraErr ? 1 : null]
    ));

    $st = $pdo->prepare('SELECT id, event_type, contact_name, status, quote_amount FROM group_event_inquiries WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        header('Location: ' . $keep('That inquiry is no longer on file.', true));
        exit;
    }

    $msg = '';
    $isErr = false;

    if ($op === 'stage' || $op === 'close') {
        $allowed = $op === 'stage' ? $STAGES : ['declined', 'closed'];
        $want = (string)($_POST['status'] ?? '');
        if (!in_array($want, $allowed, true)) {
            $msg = 'That stage is not one this inquiry can move to.';
            $isErr = true;
        } elseif ($row['status'] === $want) {
            $msg = 'This inquiry is already marked ' . strtolower($STAGE_LABEL[$want]) . '.';
            $isErr = true;
        } else {
            $pdo->prepare('UPDATE group_event_inquiries SET status = ? WHERE id = ?')->execute([$want, $id]);
            audit($pdo, 'admin', (int)$admin['id'], 'inquiry.stage', 'inquiry', $id, ['from' => $row['status'], 'to' => $want]);
            $msg = $row['contact_name'] . ' is now ' . strtolower($STAGE_LABEL[$want]) . '.';
        }
    } elseif ($op === 'quote') {
        $raw = trim((string)($_POST['quote_amount'] ?? ''));
        $notes = trim((string)($_POST['admin_notes'] ?? ''));
        if ($raw !== '' && !is_numeric($raw)) {
            $msg = 'A quote has to be an amount, like 2400 or 2400.50.';
            $isErr = true;
        } elseif ($raw !== '' && (float)$raw < 0) {
            $msg = 'A quote cannot be negative. To record money going back, refund the payment instead.';
            $isErr = true;
        } elseif ($raw !== '' && (float)$raw > 99999999.99) {
            $msg = 'That quote is larger than any booking can be. Check the amount.';
            $isErr = true;
        } else {
            $amount = $raw === '' ? null : round((float)$raw, 2);
            $pdo->prepare('UPDATE group_event_inquiries SET quote_amount = ?, admin_notes = ? WHERE id = ?')
                ->execute([$amount, $notes, $id]);
            audit($pdo, 'admin', (int)$admin['id'], 'inquiry.quote', 'inquiry', $id, ['quote_amount' => $amount]);
            $msg = 'Quote saved for ' . $row['contact_name'] . '.';
        }
    } else {
        $msg = 'Nothing to save.';
        $isErr = true;
    }

    header('Location: ' . $keep($msg, $isErr));
    exit;
}

$msg = (string)($_GET['msg'] ?? '');
$isErr = isset($_GET['err']);
$filter = (string)($_GET['f'] ?? '');
if (!array_key_exists($filter, $STAGE_LABEL)) $filter = '';
$kindFilter = (string)($_GET['k'] ?? '');
if (!in_array($kindFilter, ['group_event', 'direct_contract'], true)) $kindFilter = '';
$q = trim((string)($_GET['q'] ?? ''));

// ---- fleet capacity --------------------------------------------------------
// Multi-vehicle work is decided by whether the cars can seat the party, so the
// roster is read once and the biggest cars allocated first.
$caps = [];
$st = $pdo->prepare('SELECT passenger_capacity FROM vehicles WHERE status = "active" AND passenger_capacity > 0 ORDER BY passenger_capacity DESC');
$st->execute();
foreach ($st->fetchAll() as $v) $caps[] = (int)$v['passenger_capacity'];

$manifest = static function (?int $people, ?int $wanted) use ($caps): array {
    if ($people === null || $people <= 0 || $wanted === null || $wanted <= 0) {
        return ['known' => false, 'fits' => null, 'cars' => [], 'seats' => 0, 'short' => 0];
    }
    $take = min($wanted, count($caps));
    $chosen = array_slice($caps, 0, $take);
    $seats = array_sum($chosen);
    $shown = min($wanted, 24);
    return [
        'known' => true,
        'fits' => $seats >= $people,
        'cars' => array_slice($chosen, 0, $shown),
        'clipped' => $wanted > count($caps) || $wanted > 24,
        'wanted' => $wanted,
        'seats' => $seats,
        'short' => max(0, $people - $seats),
    ];
};

// ---- index -----------------------------------------------------------------
$sql = 'SELECT id, kind, status, event_type, event_dates, vehicle_count, estimated_passengers,
        locations, contact_name, contact_email, quote_amount, created_at
        FROM group_event_inquiries';
$params = [];
$where = [];
if ($filter !== '') { $where[] = 'status = ?'; $params[] = $filter; }
if ($kindFilter !== '') { $where[] = 'kind = ?'; $params[] = $kindFilter; }
if ($q !== '') {
    $where[] = '(event_type LIKE ? OR contact_name LIKE ? OR contact_email LIKE ? OR locations LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$now = time();
foreach ($rows as &$r) {
    $r['age'] = max(0, (int)floor(($now - strtotime((string)$r['created_at'])) / 86400));
    $r['man'] = $manifest($r['estimated_passengers'] !== null ? (int)$r['estimated_passengers'] : null,
                         $r['vehicle_count'] !== null ? (int)$r['vehicle_count'] : null);
}
unset($r);

// Oldest unanswered first inside a stage; settled inquiries sink to the bottom.
usort($rows, static function (array $a, array $b) use ($STAGE_RANK): int {
    $ra = $STAGE_RANK[$a['status']] ?? 9;
    $rb = $STAGE_RANK[$b['status']] ?? 9;
    if ($ra !== $rb) return $ra <=> $rb;
    return $b['age'] <=> $a['age'];
});

// ---- selected --------------------------------------------------------------
// The index query is deliberately narrow, so the sheet must be read in full â€”
// schedule, special requests, phone and notes are not in the list columns.
$selId = (int)($_GET['i'] ?? 0);
$sel = null;
if ($selId > 0) {
    $st = $pdo->prepare('SELECT * FROM group_event_inquiries WHERE id = ? LIMIT 1');
    $st->execute([$selId]);
    $sel = $st->fetch() ?: null;
}
$missing = $selId > 0 && $sel === null;
if ($sel !== null) {
    $sel['age'] = max(0, (int)floor(($now - strtotime((string)$sel['created_at'])) / 86400));
    $sel['man'] = $manifest($sel['estimated_passengers'] !== null ? (int)$sel['estimated_passengers'] : null,
                            $sel['vehicle_count'] !== null ? (int)$sel['vehicle_count'] : null);
}

// ---- figures ---------------------------------------------------------------
$st = $pdo->query('SELECT status, COUNT(*) c, COALESCE(SUM(quote_amount),0) v FROM group_event_inquiries GROUP BY status');
$byStatus = [];
$total = 0;
foreach ($st->fetchAll() as $s) {
    $byStatus[$s['status']] = ['c' => (int)$s['c'], 'v' => (float)$s['v']];
    $total += (int)$s['c'];
}
$countOf = static fn(string $s): int => $byStatus[$s]['c'] ?? 0;
$valueOf = static fn(string $s): float => $byStatus[$s]['v'] ?? 0.0;
$live = $countOf('new') + $countOf('quoted') + $countOf('confirmed');

// Inquiries the fleet cannot actually seat, among live ones.
$unservable = 0;
foreach ($rows as $r) if (in_array($r['status'], $STAGES, true) && $r['man']['known'] && $r['man']['fits'] === false) $unservable++;

ob_start();
?>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<header class="ge-head">
  <div>
    <p class="eyebrow">Content</p>
    <h1 class="font-display">The inquiry desk</h1>
    <p class="ge-sub">Weddings, corporate days and direct contracts all land here. Oldest waiting answers first &mdash; a quote sent late is a booking lost.</p>
  </div>
  <p class="ge-figures">
    <span><b><?= $live ?></b> still live</span>
    <span><b><?= $countOf('new') ?></b> need a quote</span>
    <?php if ($countOf('quoted') > 0): ?><span><b>$<?= money($valueOf('quoted')) ?></b> quoted</span><?php endif; ?>
    <?php if ($countOf('confirmed') > 0): ?><span><b>$<?= money($valueOf('confirmed')) ?></b> won</span><?php endif; ?>
    <?php if ($live === 0): ?><span>Nothing live &mdash; the desk is clear</span><?php endif; ?>
  </p>
</header>

<?php if ($unservable > 0): ?>
  <p class="ge-warn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    <?= $unservable ?> live <?= $unservable === 1 ? 'inquiry asks' : 'inquiries ask' ?> for more <?= $unservable === 1 ? 'seats' : 'seats' ?> than the fleet can put on the road. Open <?= $unservable === 1 ? 'it' : 'them' ?> to see the manifest.</p>
<?php endif; ?>

<nav class="ge-filters" aria-label="Filter inquiries">
  <form method="get" class="ge-search" action="<?= url('admin/group-events.php') ?>">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Occasion, name, email or place" aria-label="Search inquiries">
    <?php if ($filter): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
    <?php if ($kindFilter): ?><input type="hidden" name="k" value="<?= e($kindFilter) ?>"><?php endif; ?>
    <?php if ($sel): ?><input type="hidden" name="i" value="<?= (int)$sel['id'] ?>"><?php endif; ?>
    <button type="submit">Search</button>
    <?php if ($q !== '' || $filter !== '' || $kindFilter !== ''): ?><a href="<?= url('admin/group-events.php') ?>">Clear</a><?php endif; ?>
  </form>
  <div class="ge-chips">
    <?php
    $chips = ['' => 'All'] + $STAGE_LABEL;
    foreach ($chips as $key => $label):
        $n = $key === '' ? $total : $countOf($key);
        $url = 'admin/group-events.php?' . http_build_query(array_filter([
            'f' => $key, 'k' => $kindFilter, 'q' => $q, 'i' => $sel ? (int)$sel['id'] : null,
        ]));
    ?>
    <a class="ge-chip<?= $filter === $key ? ' is-on' : '' ?>" href="<?= url($url) ?>"<?= $filter === $key ? ' aria-current="true"' : '' ?>><?= e($label) ?><b><?= $n ?></b></a>
    <?php endforeach; ?>
    <span class="ge-chip-sep" aria-hidden="true"></span>
    <?php foreach (['group_event' => 'Events', 'direct_contract' => 'Contracts'] as $key => $label): ?>
    <a class="ge-chip<?= $kindFilter === $key ? ' is-on' : '' ?>" href="<?= url('admin/group-events.php?' . http_build_query(array_filter(['k' => $key, 'f' => $filter, 'q' => $q, 'i' => $sel ? (int)$sel['id'] : null]))) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</nav>
<?php if ($missing): ?>
  <p class="ge-blank ge-gone">That inquiry is no longer on the desk. <?= $rows ? 'The cards below are what is here now.' : 'Nothing is on the desk at all right now.' ?></p>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="ge-empty-wrap">
    <p class="ge-blank"><?= $q !== '' || $filter !== '' || $kindFilter !== '' ? 'Nothing on the desk matches that.' : 'The desk is clear.' ?></p>
    <p class="ge-blank-sub">
      <?php if ($q !== ''): ?>
        Try part of a name, an occasion or a place.
      <?php elseif ($filter !== '' || $kindFilter !== ''): ?>
        Nothing in <?= $filter !== '' ? strtolower($STAGE_LABEL[$filter]) : ($kindFilter === 'direct_contract' ? 'contracts' : 'events') ?> right now. <a href="<?= url('admin/group-events.php') ?>">Show the whole desk</a>.
      <?php else: ?>
        Inquiries from the group, event and direct contract forms arrive here as soon as someone sends one.
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>
  <ul class="ge-cards">
    <?php foreach ($rows as $r):
      $settled = in_array($r['status'], ['declined', 'closed'], true);
      $ageCls = $settled ? '' : ($r['age'] >= 8 ? ' is-cold' : ($r['age'] >= 4 ? ' is-old' : ''));
      $shortBy = (!$settled && $r['man']['known'] && $r['man']['fits'] === false) ? (int)$r['man']['short'] : 0;
    ?>
    <li>
      <a class="ge-card<?= $settled ? ' is-settled' : '' ?><?= $shortBy > 0 ? ' is-short' : '' ?>"
         href="<?= url('admin/group-events.php?' . http_build_query(array_filter(['i' => (int)$r['id'], 'f' => $filter, 'k' => $kindFilter, 'q' => $q]))) ?>">
        <span class="ge-card-top">
          <span class="ge-kind ge-kind-<?= e((string)$r['kind']) ?>"><?= $r['kind'] === 'direct_contract' ? 'Contract' : 'Event' ?></span>
          <span class="ge-stage ge-stage-<?= e((string)$r['status']) ?>"><?= e($STAGE_LABEL[(string)$r['status']] ?? $r['status']) ?></span>
        </span>
        <span class="ge-occasion"><?= e((string)$r['event_type']) ?></span>
        <span class="ge-who"><?= e($r['contact_name']) ?></span>
        <span class="ge-card-foot">
          <span class="ge-party">
            <?php if ($r['estimated_passengers']): ?><?= (int)$r['estimated_passengers'] ?> pax<?php endif; ?>
            <?php if ($r['vehicle_count']): ?><span class="ge-dot" aria-hidden="true">Â·</span><?= (int)$r['vehicle_count'] ?> <?= (int)$r['vehicle_count'] === 1 ? 'car' : 'cars' ?><?php endif; ?>
            <?php if (!$r['estimated_passengers'] && !$r['vehicle_count']): ?><span class="ge-dim">headcount not given</span><?php endif; ?>
          </span>
          <?php if ($r['quote_amount'] !== null): ?><span class="ge-quote">$<?= money($r['quote_amount']) ?></span><?php endif; ?>
        </span>
        <span class="ge-card-foot2">
          <?php if ($shortBy > 0): ?>
            <span class="ge-seat"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> needs <?= $plural($shortBy, 'seat') ?> more</span>
          <?php elseif ($settled): ?>
            <span class="ge-age">settled <?= $r['age'] === 0 ? 'today' : $plural($r['age'], 'day') . ' ago' ?></span>
          <?php else: ?>
            <span class="ge-age<?= $ageCls ?>"><?= $r['age'] === 0 ? 'arrived today' : $plural($r['age'], 'day') . ' waiting' ?></span>
          <?php endif; ?>
          <?php if ($r['event_dates']): ?><span class="ge-when"><?= e((string)$r['event_dates']) ?></span><?php endif; ?>
        </span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($sel):
    $sid = (int)$sel['id'];
    $settled = in_array($sel['status'], ['declined', 'closed'], true);
    $man = $sel['man'];
    $stageAt = $STAGE_RANK[$sel['status']] ?? 9;
    $initial = mb_strtoupper(mb_substr(trim((string)$sel['contact_name']), 0, 1));
?>
<dialog class="modal is-wide ge-dlg" id="ge-dlg" data-autopen aria-labelledby="ge-dlg-t">
  <div class="md-head">
    <div>
      <h2 class="md-title" id="ge-dlg-t"><?= e((string)$sel['event_type']) ?></h2>
      <p class="md-sub">
        <?= $sel['kind'] === 'direct_contract' ? 'Direct contract' : 'Group &amp; event' ?> request
        &middot; came in <?= $sel['age'] === 0 ? 'today' : $plural($sel['age'], 'day') . ' ago' ?>
        <?php if ($sel['event_dates']): ?> &middot; dates <b><?= e((string)$sel['event_dates']) ?></b><?php endif; ?>
      </p>
    </div>
    <button type="button" class="md-x" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>

  <div class="md-body ge-dlg-body">
    <div class="ge-dlg-id">
      <span class="ge-avatar" aria-hidden="true"><?= e($initial !== '' ? $initial : '?') ?></span>
      <div class="ge-id-txt">
        <p class="ge-contact">
          <a href="mailto:<?= e($sel['contact_email']) ?>"><?= e($sel['contact_email']) ?></a>
          <?php if ($sel['contact_phone']): ?><span class="ge-contact-nb">&middot; <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string)$sel['contact_phone'])) ?>"><?= e($sel['contact_phone']) ?></a></span><?php endif; ?>
        </p>
        <p class="ge-received"><?= e((string)$sel['contact_name']) ?></p>
      </div>
      <span class="ge-stage ge-stage-<?= e((string)$sel['status']) ?>"><?= e($STAGE_LABEL[(string)$sel['status']] ?? $sel['status']) ?></span>
    </div>

    <?php if ($man['known']):
      $spare = max(0, (int)$man['seats'] - (int)$sel['estimated_passengers']);
      $people = (int)$sel['estimated_passengers'];
      $wantCars = (int)$man['wanted'];
      $alt = $man['fits']
          ? $wantCars . ' ' . ($wantCars === 1 ? 'car' : 'cars') . ' carrying ' . $people . ' people, ' . $spare . ' seats to spare'
          : 'The ' . $wantCars . ' largest ' . ($wantCars === 1 ? 'car seats' : 'cars seat') . ' ' . $man['seats'] . ', short of ' . $people;
    ?>
    <div class="ge-man<?= $man['fits'] ? ' is-ok' : ' is-short' ?>">
      <div class="ge-man-head">
        <span class="ge-man-k">The manifest</span>
        <span class="ge-man-v">
          <?php if ($man['fits']): ?>
            <?= $spare === 0 ? 'Exactly full &mdash; every seat taken' : $spare . ' seats to spare' ?>
          <?php else: ?>
            Short by <?= $plural((int)$man['short'], 'seat') ?>
          <?php endif; ?>
        </span>
      </div>
      <div class="ge-cars" role="img" aria-label="<?= e($alt) ?>">
        <?php foreach ($man['cars'] as $seatCap): ?>
          <span class="ge-car"><b><?= $seatCap ?></b><small>seats</small></span>
        <?php endforeach; ?>
        <?php if ($man['short'] > 0): ?><span class="ge-car is-gap"><b><?= (int)$man['short'] ?></b><small>short</small></span><?php endif; ?>
      </div>
      <p class="ge-man-note">
        <?php if ($man['fits']): ?>
          The <?= $plural($wantCars, 'largest car') ?> on the fleet <?= $wantCars === 1 ? 'seats' : 'seat' ?> <?= (int)$man['seats'] ?> between <?= $wantCars === 1 ? 'it' : 'them' ?> &mdash; enough for <?= $people ?>.
        <?php elseif ($wantCars < count($caps)): ?>
          Even the <?= $plural($wantCars, 'largest car') ?> <?= $wantCars === 1 ? 'seats' : 'seat' ?> <?= (int)$man['seats'] ?>. Quote <?= $plural($wantCars + 1, 'car') ?>, or tell <?= e((string)$sel['contact_name']) ?> what can be offered.
        <?php else: ?>
          Every active car together <?= $wantCars === 1 ? 'seats' : 'seat' ?> <?= (int)$man['seats'] ?>, still short. This one needs a partner operator &mdash; tell <?= e((string)$sel['contact_name']) ?> what you can cover.
        <?php endif; ?>
      </p>
    </div>
    <?php endif; ?>

    <div>
      <h3 class="ge-sec-h">What they asked for</h3>
      <?php
      $brief = [
          'Where' => (string)$sel['locations'],
          'Schedule' => (string)$sel['schedule'],
          'Special requests' => (string)$sel['special_requirements'],
      ];
      $any = false;
      foreach ($brief as $_) if (trim((string)$_) !== '') $any = true;
      ?>
      <?php if (!$any): ?>
        <p class="ge-blank">The request came in with the occasion and party size only.</p>
      <?php else: ?>
        <dl class="ge-fields">
          <?php foreach ($brief as $k => $v): if (trim((string)$v) === '') continue; ?>
          <div class="ge-field<?= $k === 'Where' ? ' is-wide' : '' ?>">
            <dt><?= e($k) ?></dt>
            <dd><?= nl2br(e($v)) ?></dd>
          </div>
          <?php endforeach; ?>
        </dl>
      <?php endif; ?>
    </div>

    <form method="post" class="ge-desk">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="stage">
      <input type="hidden" name="id" value="<?= $sid ?>">
      <input type="hidden" name="f" value="<?= e($filter) ?>">
      <input type="hidden" name="k" value="<?= e($kindFilter) ?>">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <span class="ge-desk-k">Stage</span>
      <div class="ge-rail" role="group" aria-label="Move this inquiry through the pipeline">
        <?php foreach ($STAGES as $n => $s):
          $done = $settled ? false : ($n < $stageAt);
          $on = $sel['status'] === $s;
        ?>
        <button class="ge-step<?= $on ? ' is-on' : '' ?><?= $done ? ' is-done' : '' ?>" name="status" value="<?= e($s) ?>"<?= $on ? ' aria-current="step"' : '' ?>>
          <span class="ge-step-n"><?= $n + 1 ?></span>
          <span class="ge-step-l"><?= e($STAGE_LABEL[$s]) ?></span>
        </button>
        <?php if ($n < count($STAGES) - 1): ?><i class="ge-link" aria-hidden="true"></i><?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php if ($settled): ?>
        <p class="ge-desk-why">This inquiry is <?= e(strtolower($STAGE_LABEL[(string)$sel['status']])) ?>. Pick a stage above to put it back in play, or close it below.</p>
      <?php endif; ?>
    </form>

    <form method="post" class="ge-quoteform">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="quote">
      <input type="hidden" name="id" value="<?= $sid ?>">
      <input type="hidden" name="f" value="<?= e($filter) ?>">
      <input type="hidden" name="k" value="<?= e($kindFilter) ?>">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <div class="ge-quote-top">
        <span class="ge-desk-k">Your quote</span>
        <label class="pz-sr" for="quote-<?= $sid ?>">Quote amount in dollars</label>
        <span class="ge-amount">
          <span class="ge-amount-sign" aria-hidden="true">$</span>
          <input id="quote-<?= $sid ?>" name="quote_amount" type="number" step="0.01" min="0" inputmode="decimal"
                 class="ge-amount-in" placeholder="0.00" value="<?= e((string)($sel['quote_amount'] ?? '')) ?>">
        </span>
      </div>
      <label class="pz-sr" for="notes-<?= $sid ?>">Notes kept with this inquiry</label>
      <textarea id="notes-<?= $sid ?>" name="admin_notes" class="input ge-notes" rows="3"
                placeholder="Cars quoted, what you agreed, anything to remember"><?= e((string)($sel['admin_notes'] ?? '')) ?></textarea>
      <div class="ge-quote-acts">
        <button class="ge-save">Save quote and notes</button>
        <span class="ge-save-why">Keeps the stage where it is. Send the quote from your email.</span>
      </div>
    </form>

    <?php if (!$settled): ?>
    <form method="post" class="ge-close">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="close">
      <input type="hidden" name="id" value="<?= $sid ?>">
      <input type="hidden" name="f" value="<?= e($filter) ?>">
      <input type="hidden" name="k" value="<?= e($kindFilter) ?>">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <button class="ge-x ge-x-declined" name="status" value="declined">They said no</button>
      <button class="ge-x" name="status" value="closed">Nothing more to do</button>
      <span class="ge-close-why">Closing keeps the inquiry on file with everything above it.</span>
    </form>
    <?php endif; ?>
  </div>
</dialog>
<noscript><style>#ge-dlg{display:block;position:static;max-width:none;margin:1rem auto}</style></noscript>
<script>
(function () {
  var d = document.getElementById('ge-dlg');
  if (!d) return;
  // Closing should not leave ?i= behind, or a refresh reopens what was just dismissed.
  d.addEventListener('close', function () {
    var u = new URL(location.href);
    u.searchParams.delete('i');
    history.replaceState(null, '', u.pathname + (u.search ? u.search : ''));
  });
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'Inquiries | Admin';
$navActive = 'group-events.php';
require APP_ROOT . '/views/layouts/admin.php';
