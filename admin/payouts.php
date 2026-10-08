<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');
$msg = '';
$isErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = (string)($_POST['op'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $tab = (string)($_POST['tab'] ?? 'queue');
    $st = $pdo->prepare('SELECT p.*, d.name AS dname FROM driver_payouts p JOIN drivers d ON d.id = p.driver_id WHERE p.id = ? LIMIT 1');
    $st->execute([$id]);
    $p = $st->fetch();

    if (!$p) {
        $msg = 'That payout request is no longer on file.';
        $isErr = true;
    } elseif ($op === 'approve') {
        if ($p['status'] !== 'requested') {
            $msg = 'Only a request waiting on review can be approved. This one is ' . $p['status'] . '.';
            $isErr = true;
        } else {
            $pdo->prepare('UPDATE driver_payouts SET status = "approved", reviewed_at = NOW(), reviewed_by = ? WHERE id = ?')->execute([(int)$admin['id'], $id]);
            audit($pdo, 'admin', (int)$admin['id'], 'payout.approve', 'payout', $id, ['amount' => $p['amount']]);
            $msg = 'Approved ' . $p['dname'] . "'s payout of $" . money($p['amount']) . '. Send it, then record it paid.';
        }
    } elseif ($op === 'reject') {
        $notes = substr(trim((string)($_POST['notes'] ?? '')), 0, 500);
        if ($p['status'] !== 'requested') {
            $msg = 'Only a request waiting on review can be turned down. This one is ' . $p['status'] . '.';
            $isErr = true;
        } elseif ($notes === '') {
            // The old form took a 120px input in a table cell and allowed an empty
            // value, so a request could be refused with nothing on the record for
            // the driver to read.
            $msg = 'Tell the chauffeur why, so they know what to do next.';
            $isErr = true;
        } else {
            $pdo->prepare('UPDATE driver_payouts SET status = "rejected", reviewed_at = NOW(), reviewed_by = ?, notes = ? WHERE id = ?')->execute([(int)$admin['id'], $notes, $id]);
            audit($pdo, 'admin', (int)$admin['id'], 'payout.reject', 'payout', $id, ['notes' => $notes]);
            $msg = $p['dname'] . "'s payout turned down. They can see the reason.";
        }
    } elseif ($op === 'paid') {
        $paidIds = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['earning_ids'] ?? '')))));

        if ($p['status'] !== 'approved') {
            $msg = 'Approve a payout before recording it paid. This one is ' . $p['status'] . '.';
            $isErr = true;
        } elseif (!$paidIds) {
            $msg = 'Nothing is marked to settle. Open the request and choose the trips it covers.';
            $isErr = true;
        } else {
            $ph = implode(',', array_fill(0, count($paidIds), '?'));
            $check = $pdo->prepare("SELECT id, driver_amount, status FROM driver_earnings WHERE id IN ($ph) AND driver_id = ?");
            $check->execute(array_merge($paidIds, [(int)$p['driver_id']]));
            $lines = $check->fetchAll();

            $covering = array_values(array_filter($lines, static fn($r) => $r['status'] === 'unpaid'));
            $covered = round(array_sum(array_map(static fn($r) => (float)$r['driver_amount'], $covering)), 2);
            $requestFor = round((float)$p['amount'], 2);
            $skipped = count($lines) - count($covering);

            if ($covered + 0.005 < $requestFor) {
                // The old code settled every unpaid trip for this driver whenever
                // any payout was marked paid, so a request for $300 also cleared a
                // later unpaid $400 the driver never asked for. Only the trips the
                // operator selected may be settled, and they must cover the request.
                $msg = 'The trips you picked add up to $' . money($covered) . ', but the request is for $' . money($requestFor) . '. Pick more trips, or lower the request.';
                $isErr = true;
            } else {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE driver_payouts SET status = "paid", paid_at = NOW(), reviewed_by = ? WHERE id = ?')->execute([(int)$admin['id'], $id]);
                $upd = $pdo->prepare('UPDATE driver_earnings SET status = "paid", paid_at = NOW() WHERE id = ? AND driver_id = ? AND status = "unpaid"');
                foreach ($covering as $r) $upd->execute([(int)$r['id'], (int)$p['driver_id']]);
                $pdo->commit();
                audit($pdo, 'admin', (int)$admin['id'], 'payout.paid', 'payout', $id, [
                    'amount' => $requestFor, 'earnings' => array_map(static fn($r) => (int)$r['id'], $covering),
                ]);
                $extra = $covered - $requestFor > 0.005 ? ' $' . money($covered - $requestFor) . ' of the selected trips stays unpaid.' : '';
                $msg = 'Recorded $' . money($requestFor) . ' paid to ' . $p['dname'] . '. Settled ' . count($covering) . ' ' . (count($covering) === 1 ? 'trip' : 'trips') . '.' . $extra
                    . ($skipped > 0 ? ' ' . $skipped . ' already-settled ' . ($skipped === 1 ? 'trip was' : 'trips were') . ' skipped.' : '');
            }
        }
    }

    $msg = trim($msg);
    header('Location: ' . url('admin/payouts.php?tab=' . $tab . ($msg !== '' ? '&' . ($isErr ? 'err=1&' : '') . 'msg=' . urlencode($msg) : '')));
    exit;
}

$msg = (string)($_GET['msg'] ?? '');
$isErr = isset($_GET['err']);
$tab = in_array($_GET['tab'] ?? '', ['queue', 'history'], true) ? $_GET['tab'] : 'queue';
$openId = (int)($_GET['open'] ?? 0);

$payoutDays = (int)(setting($pdo, 'payout_interval_days', '7'));

// ---- the queue: what is waiting, in the order it must be done --------------
$queue = $pdo->query('SELECT p.*, d.name AS dname, d.reference AS dref,
        a.name AS reviewer
    FROM driver_payouts p
    JOIN drivers d ON d.id = p.driver_id
    LEFT JOIN admins a ON a.id = p.reviewed_by
    WHERE p.status IN ("requested","approved")
    ORDER BY FIELD(p.status, "requested", "approved"), p.requested_at ASC')->fetchAll();

// ---- history ---------------------------------------------------------------
$hRows = $pdo->query('SELECT p.*, d.name AS dname, d.reference AS dref, a.name AS reviewer
    FROM driver_payouts p
    JOIN drivers d ON d.id = p.driver_id
    LEFT JOIN admins a ON a.id = p.reviewed_by
    WHERE p.status IN ("paid","rejected")
    ORDER BY COALESCE(p.paid_at, p.reviewed_at, p.requested_at) DESC
    LIMIT 200')->fetchAll();

$sums = $pdo->query('SELECT
    COALESCE(SUM(CASE WHEN status = "requested" THEN amount ELSE 0 END),0) waiting_value,
    COUNT(CASE WHEN status = "requested" THEN 1 END) waiting_n,
    COALESCE(SUM(CASE WHEN status = "approved" THEN amount ELSE 0 END),0) approved_value,
    COUNT(CASE WHEN status = "approved" THEN 1 END) approved_n,
    COALESCE(SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END),0) paid_value,
    COUNT(CASE WHEN status = "paid" THEN 1 END) paid_n,
    COUNT(CASE WHEN status = "rejected" THEN 1 END) rejected_n
    FROM driver_payouts')->fetch();

// What is owed across the fleet but has not been requested — the reason the
// queue is short, and the number an owner actually wants before approving.
$owed = $pdo->query('SELECT COUNT(*) AS rows_n, COALESCE(SUM(driver_amount),0) AS value
    FROM driver_earnings WHERE status = "unpaid"')->fetch();

// Open one request: which unpaid trips would cover it, oldest first.
$openPay = null;
$openLines = [];
if ($openId) {
    $st = $pdo->prepare('SELECT p.*, d.name AS dname FROM driver_payouts p JOIN drivers d ON d.id = p.driver_id WHERE p.id = ? LIMIT 1');
    $st->execute([$openId]);
    $openPay = $st->fetch();
    if ($openPay) {
        $l = $pdo->prepare('SELECT e.id, e.booking_id, e.driver_amount, e.earned_at, b.booking_number
            FROM driver_earnings e JOIN bookings b ON b.id = e.booking_id
            WHERE e.driver_id = ? AND e.status = "unpaid"
            ORDER BY e.earned_at ASC, e.id ASC');
        $l->execute([(int)$openPay['driver_id']]);
        $openLines = $l->fetchAll();
    }
}

$fmtWhen = static function (?string $ts, string $fallback = ''): string {
    $t = $ts ? strtotime($ts) : false;
    if ($t === false) return $fallback !== '' ? $fallback : '—';
    $days = (int)floor((time() - $t) / 86400);
    if ($days <= 0) return 'today';
    if ($days === 1) return 'yesterday';
    if ($days < 30) return $days . ' days ago';
    return date('j M', $t);
};

ob_start();
?>
<?php if ($msg !== ''): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="page-head">
  <div>
    <p class="eyebrow">Money</p>
    <h1 class="font-display text-3xl mt-1">Chauffeur payouts</h1>
  </div>
</div>

<?php if (!$queue && !$hRows): ?>
  <div class="po-blank">
    <p class="po-blank-h">No payout requests</p>
    <p>A chauffeur requests a payout from their own page once every <?= $payoutDays ?> days, and can only do so when they have unpaid trips. Requests you approve or turn down are kept here.</p>
    <div class="po-blank-acts">
      <a class="po-btn" href="<?= url('admin/earnings.php') ?>">See who has unpaid trips</a>
      <a class="po-btn po-btn-quiet" href="<?= url('admin/drivers.php') ?>">Open chauffeurs</a>
    </div>
  </div>
<?php else: ?>

<!-- the desk: three states, and where the money currently sits -->
<section class="po-desk" aria-label="Payout position">
  <div class="po-step po-step-now">
    <p class="po-step-k">Waiting on you</p>
    <p class="po-step-v"><?= (int)$sums['waiting_n'] ?></p>
    <p class="po-step-s">$<?= money((float)$sums['waiting_value']) ?> requested</p>
  </div>
  <div class="po-step po-step-sent">
    <p class="po-step-k">Approved, not sent</p>
    <p class="po-step-v"><?= (int)$sums['approved_n'] ?></p>
    <p class="po-step-s">$<?= money((float)$sums['approved_value']) ?> cleared to go out</p>
  </div>
  <div class="po-step">
    <p class="po-step-k">Paid out</p>
    <p class="po-step-v">$<?= money((float)$sums['paid_value']) ?></p>
    <p class="po-step-s"><?= (int)$sums['paid_n'] ?> <?= (int)$sums['paid_n'] === 1 ? 'payout' : 'payouts' ?> sent<?= (int)$sums['rejected_n'] > 0 ? ' &middot; ' . (int)$sums['rejected_n'] . ' turned down' : '' ?></p>
  </div>
  <div class="po-step po-step-owed<?= (float)$owed['value'] > 0 ? ' is-owed' : '' ?>">
    <p class="po-step-k">Unpaid trips, not requested</p>
    <p class="po-step-v">$<?= money((float)$owed['value']) ?></p>
    <p class="po-step-s">across <?= (int)$owed['rows_n'] ?> <?= (int)$owed['rows_n'] === 1 ? 'trip' : 'trips' ?></p>
  </div>
</section>

<?php if ((float)$owed['value'] > 0 && (int)$sums['waiting_n'] === 0): ?>
  <p class="po-flag">No one has asked for a payout, but $<?= money((float)$owed['value']) ?> is sitting unpaid. A chauffeur requests their own from their page &mdash; you cannot raise one for them.</p>
<?php endif; ?>

<nav class="pz-tabs" aria-label="Payout sections">
  <a class="pz-tab<?= $tab === 'queue' ? ' is-on' : '' ?>" href="<?= url('admin/payouts.php?tab=queue') ?>" <?= $tab === 'queue' ? 'aria-current="page"' : '' ?>>
    <span class="pz-tab-l">Queue</span>
    <span class="pz-tab-s">Waiting on you</span>
    <?php if (count($queue)): ?><span class="pz-tab-n"><?= count($queue) ?></span><?php endif; ?>
  </a>
  <a class="pz-tab<?= $tab === 'history' ? ' is-on' : '' ?>" href="<?= url('admin/payouts.php?tab=history') ?>" <?= $tab === 'history' ? 'aria-current="page"' : '' ?>>
    <span class="pz-tab-l">History</span>
    <span class="pz-tab-s">Sent and turned down</span>
    <?php if (count($hRows)): ?><span class="pz-tab-n"><?= count($hRows) ?></span><?php endif; ?>
  </a>
</nav>

<?php if ($tab === 'queue'): ?>
  <div class="po-col">
    <?php if (!$queue): ?>
      <p class="pz-empty">Nothing is waiting. New requests land here as chauffeurs ask for them.</p>
    <?php else: ?>
      <p class="po-lede">Requested first, then approved, then sent. A request cannot be recorded as paid until it has been approved.</p>
      <ol class="po-queue">
        <?php foreach ($queue as $q):
          $rqid = (int)$q['id'];
          $stage = $q['status'] === 'requested' ? 1 : 2;
          $waitingDays = (int)floor((time() - strtotime((string)$q['requested_at'])) / 86400);
          $opening = $openId === $rqid; ?>
          <li class="po-item<?= $stage === 1 ? ' is-first' : ' is-second' ?>">
            <div class="po-item-head">
              <span class="po-stage" aria-hidden="true"><?= $stage ?></span>
              <div class="po-who">
                <span class="po-name"><?= e($q['dname']) ?></span>
                <?php if ($q['dref']): ?><code class="po-ref"><?= e($q['dref']) ?></code><?php endif; ?>
                <?php if ($waitingDays >= 3 && $stage === 1): ?><span class="po-age">waiting <?= $waitingDays ?> days</span><?php endif; ?>
              </div>
              <span class="po-amt">$<?= money($q['amount']) ?></span>
            </div>

            <dl class="po-meta">
              <div><dt>Asked for</dt><dd><?= e($fmtWhen($q['requested_at'])) ?></dd></div>
              <div><dt>To</dt><dd><code class="po-dest"><?= e(trim((string)($q['destination_reference'] ?? '')) ?: 'no destination given') ?></code></dd></div>
              <?php if ($q['reviewed_at']): ?>
                <div><dt><?= $stage === 2 ? 'Approved' : 'Reviewed' ?></dt><dd><?= e($fmtWhen($q['reviewed_at'])) ?><?= $q['reviewer'] ? ' by ' . e($q['reviewer']) : '' ?></dd></div>
              <?php endif; ?>
            </dl>

            <?php if ($stage === 1): ?>
              <div class="po-acts">
                <form method="post" class="po-inline">
                  <?= csrf_field() ?><input type="hidden" name="op" value="approve"><input type="hidden" name="id" value="<?= $rqid ?>"><input type="hidden" name="tab" value="queue">
                  <button class="po-do">Approve</button>
                </form>
                <form method="post" class="po-inline po-reject">
                  <?= csrf_field() ?><input type="hidden" name="op" value="reject"><input type="hidden" name="id" value="<?= $rqid ?>"><input type="hidden" name="tab" value="queue">
                  <label class="pz-sr" for="po-why-<?= $rqid ?>">Why you are turning it down</label>
                  <input id="po-why-<?= $rqid ?>" name="notes" class="input" placeholder="Tell them why &mdash; they read this" required>
                  <button class="po-no">Turn down</button>
                </form>
              </div>
            <?php else: ?>
              <div class="po-acts">
                <a class="po-do" href="<?= url('admin/payouts.php?tab=queue&open=' . $rqid) ?>">Record as paid</a>
                <span class="po-hint">Send the money first, then record it here.</span>
              </div>
            <?php endif; ?>

            <?php if ($opening): ?>
              <div class="po-settle">
                <p class="po-settle-h">Choose the trips this payout settles</p>
                <?php if (!$openLines): ?>
                  <p class="po-settle-empty">This chauffeur has no unpaid trips left. The request may already have been settled by an earlier payout.</p>
                <?php else: ?>
                  <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="op" value="paid"><input type="hidden" name="id" value="<?= $rqid ?>"><input type="hidden" name="tab" value="queue">
                    <input type="hidden" name="earning_ids" id="po-eids" data-want="<?= e(number_format((float)$openPay['amount'], 2, '.', '')) ?>" value="">
                    <ul class="po-lines">
                      <?php foreach ($openLines as $l):
                        $amt = (float)$l['driver_amount'];
                        $fits = $amt <= (float)$openPay['amount'] + 0.005; ?>
                        <li>
                          <label class="po-line">
                            <input type="checkbox" class="po-pick" value="<?= (int)$l['id'] ?>" data-amt="<?= e(number_format($amt, 2, '.', '')) ?>">
                            <span class="po-line-b"><?= e($l['booking_number']) ?></span>
                            <span class="po-line-w"><?= e(date('j M', strtotime((string)$l['earned_at']))) ?></span>
                            <span class="po-line-v">$<?= money($amt) ?></span>
                            <?php if (!$fits): ?><span class="po-line-warn">over the request</span><?php endif; ?>
                          </label>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                    <p class="po-settle-tot">
                      Chosen <b id="po-chosen">$0.00</b> of $<?= money($openPay['amount']) ?> requested
                      <span class="po-settle-gap" id="po-gap"></span>
                    </p>
                    <div class="po-settle-acts">
                      <button class="po-do">Record $<?= money($openPay['amount']) ?> paid</button>
                      <a class="po-toggle" href="<?= url('admin/payouts.php?tab=queue') ?>">Cancel</a>
                    </div>
                  </form>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>

<?php else: ?>
  <div class="po-col">
    <?php if (!$hRows): ?>
      <p class="pz-empty">Nothing has been sent or turned down yet.</p>
    <?php else: ?>
      <div class="table-wrap pz-table-wrap">
        <table class="data pz-table po-table">
          <thead><tr><th>Chauffeur</th><th>Amount</th><th>Outcome</th><th>When</th><th>Reviewed by</th><th>Note</th></tr></thead>
          <tbody>
          <?php foreach ($hRows as $r):
            $rejected = $r['status'] === 'rejected'; ?>
            <tr>
              <th scope="row"><span class="po-name"><?= e($r['dname']) ?></span><?php if ($r['dref']): ?><code class="po-ref"><?= e($r['dref']) ?></code><?php endif; ?></th>
              <td class="pz-num<?= $rejected ? '' : ' po-got' ?>" data-l="Amount">$<?= money($r['amount']) ?></td>
              <td data-l="Outcome"><?= status_pill($r['status']) ?></td>
              <td class="po-when" data-l="When"><?= e($fmtWhen($rejected ? $r['reviewed_at'] : $r['paid_at'])) ?></td>
              <td class="po-when" data-l="Reviewed by"><?= e((string)($r['reviewer'] ?? '—')) ?></td>
              <td class="po-note" data-l="Note"><?= e(trim((string)($r['notes'] ?? '')) ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php endif; ?>

<script>
// Only behaviour this page needs: keep the hidden earning_ids field in step with
// the ticks, and show whether the picks still cover the requested amount.
(() => {
  const box = document.getElementById('po-eids');
  if (!box) return;
  const picks = [...document.querySelectorAll('.po-pick')];
  const chosen = document.getElementById('po-chosen');
  const gap = document.getElementById('po-gap');
  const want = () => parseFloat((box.dataset.want || '0'));
  const sync = () => {
    const ids = [];
    let sum = 0;
    picks.forEach((p) => { if (p.checked) { ids.push(p.value); sum += parseFloat(p.dataset.amt || '0'); } });
    box.value = ids.join(',');
    if (chosen) chosen.textContent = '$' + sum.toFixed(2);
    if (gap) {
      // The server accepts a selection that covers the request; anything over is
      // simply left unpaid. Marking that red here would contradict it.
      const short = want() - sum;
      if (short > 0.005) {
        gap.textContent = '— $' + short.toFixed(2) + ' still uncovered';
        gap.className = 'po-settle-gap is-short';
      } else if (short < -0.005) {
        gap.textContent = '— covered, $' + Math.abs(short).toFixed(2) + ' of these trips stays unpaid';
        gap.className = 'po-settle-gap is-over';
      } else {
        gap.textContent = '— covered';
        gap.className = 'po-settle-gap is-ok';
      }
    }
  };
  picks.forEach((p) => p.addEventListener('change', sync));
  sync();
})();
</script>

<?php
$content = ob_get_clean();
$pageTitle = 'Payouts | Admin';
$navActive = 'payouts.php';
require APP_ROOT . '/views/layouts/admin.php';