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

    if ($op === 'unsubscribe') {
        $st = $pdo->prepare('SELECT email FROM newsletter_subscribers WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $gone = $st->fetchColumn();
        if ($gone === false) {
            $msg = 'That address is no longer on the list.';
            $isErr = true;
        } else {
            $pdo->prepare('UPDATE newsletter_subscribers SET status = "unsubscribed", unsubscribed_at = NOW() WHERE id = ?')->execute([$id]);
            audit($pdo, 'admin', (int)$admin['id'], 'newsletter.unsubscribed', 'newsletter_subscriber', $id, ['email' => $gone]);
            $msg = $gone . ' removed from the newsletter.';
        }
    } else {
        $msg = 'Nothing to do.';
        $isErr = true;
    }

    header('Location: ' . url('admin/notifications.php?' . http_build_query(array_filter([
        'view' => (string)($_POST['view'] ?? 'messages'), 'msg' => $msg, 'err' => $isErr ? 1 : null,
    ]))));
    exit;
}

$msg = (string)($_GET['msg'] ?? '');
$isErr = isset($_GET['err']);

// Two subjects that share no records and no filtering: what the site tried to
// send, and who subscribed to hear from it. Tabs, because you never need both
// at once. Status and template stay filters inside Messages — a failed and a
// delivered message are the same record in two states.
$view = (string)($_GET['view'] ?? 'messages');
if (!in_array($view, ['messages', 'newsletter'], true)) $view = 'messages';

// Whether this system can send at all. The log is worthless without it, so the
// page says so once at the top instead of leaving 31 identical failures to be
// read one by one.
$mailReady = NotificationService::configured($pdo);
$mailCfg = require APP_ROOT . '/config/mail.php';

// ---- filters ---------------------------------------------------------------
$status = (string)($_GET['st'] ?? '');
if (!in_array($status, ['queued', 'sent', 'failed'], true)) $status = '';
$template = (string)($_GET['tp'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));

// ---- log -------------------------------------------------------------------
$sql = 'SELECT n.id, n.recipient_type, n.recipient_id, n.booking_id, n.template, n.email,
               n.status, n.error_message, n.created_at, n.sent_at, b.booking_number
        FROM notifications n LEFT JOIN bookings b ON b.id = n.booking_id';
$where = [];
$params = [];
if ($status !== '') { $where[] = 'n.status = ?'; $params[] = $status; }
if ($template !== '') { $where[] = 'n.template = ?'; $params[] = $template; }
if ($q !== '') { $where[] = '(n.email LIKE ? OR n.template LIKE ? OR b.booking_number LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY n.id DESC LIMIT 300';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$st = $pdo->query('SELECT status, COUNT(*) c, MAX(created_at) last_at FROM notifications GROUP BY status');
$counts = ['queued' => 0, 'sent' => 0, 'failed' => 0];
$lastAt = ['queued' => null, 'sent' => null, 'failed' => null];
foreach ($st->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['c'];
    $lastAt[$r['status']] = $r['last_at'];
}
$total = array_sum($counts);

// Failures share causes far more often than they differ. One line per cause
// beats 31 rows that say the same thing.
$causes = [];
foreach ($rows as $r) {
    if ($r['status'] !== 'failed') continue;
    $why = trim((string)($r['error_message'] ?? ''));
    if ($why === '') $why = 'Failed for an unrecorded reason.';
    $causes[$why] = ($causes[$why] ?? 0) + 1;
}
arsort($causes);

$st = $pdo->query('SELECT template, COUNT(*) c FROM notifications GROUP BY template ORDER BY c DESC');
$templates = $st->fetchAll();

// ---- newsletter ------------------------------------------------------------
$subs = $pdo->query('SELECT id, email, status, created_at, unsubscribed_at FROM newsletter_subscribers ORDER BY (status = "active") DESC, id DESC LIMIT 300')->fetchAll();
$subActive = 0;
$subGone = 0;
foreach ($subs as $s) {
    if ($s['status'] === 'active') $subActive++; else $subGone++;
}

/** Template keys read as code all day; say what they mean in plain words. */
function nt_label(string $tpl): string
{
    return ucwords(str_replace(['-', '_'], ' ', $tpl));
}

ob_start();
?>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<header class="nt-head">
  <div>
    <p class="eyebrow">Content</p>
    <h1 class="font-display">Outbound</h1>
    <p class="nt-sub"><?= $view === 'messages'
        ? 'Every message this site tried to send, and whether it reached a person. Failures are grouped by what caused them.'
        : 'The people who asked to hear from this site. Removing an address stops the newsletter reaching them; it does not touch their bookings.' ?></p>
  </div>
  <p class="nt-figures">
    <span><b><?= $total ?></b> logged</span>
    <?php if ($counts['sent'] > 0): ?><span><b><?= $counts['sent'] ?></b> delivered</span><?php endif; ?>
    <?php if ($counts['failed'] > 0): ?><span class="is-bad"><b><?= $counts['failed'] ?></b> failed</span><?php endif; ?>
    <span><b><?= $subActive ?></b> subscribers</span>
  </p>
</header>

<nav class="nt-tabs" aria-label="Sections">
  <?php foreach ([
      'messages' => ['Messages', $total, 'Every message the site tried to send'],
      'newsletter' => ['Newsletter', $subActive, 'People who subscribed to hear from the site'],
  ] as $key => [$label, $n, $why]): ?>
  <a class="nt-tab<?= $view === $key ? ' is-on' : '' ?>" href="<?= url('admin/notifications.php?view=' . $key) ?>"
     <?= $view === $key ? 'aria-current="page"' : '' ?>>
    <span class="nt-tab-l"><?= e($label) ?></span>
    <span class="nt-tab-n"><?= $n ?></span>
    <span class="nt-tab-s"><?= e($why) ?></span>
  </a>
  <?php endforeach; ?>
</nav>

<?php if ($view === 'messages'): ?>
<?php if (!$mailReady): ?>
  <section class="nt-block">
    <div class="nt-block-mark"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></div>
    <div class="nt-block-txt">
      <h2>Nothing is being delivered</h2>
      <p>
        This site has no mail server set up, so <?= $counts['failed'] === 0 ? 'any' : 'all ' . $counts['failed'] ?> of
        <?= $total === 0 ? 'the messages it has tried to send' : 'the messages it has tried to send' ?> failed.
        Customers are not being emailed. Booking confirmations, driver updates and refund receipts are all being written to this log and going nowhere.
      </p>
      <p class="nt-block-fix">
        Set <code>MAIL_HOST</code> and <code>MAIL_FROM_ADDRESS</code> in <code>.env</code>, then reload. Until then
        nothing on this page can be retried into success &mdash; the failure is the missing server, not the message.
      </p>
    </div>
    <div class="nt-block-side">
      <span class="nt-state nt-state-off">mail off</span>
      <span class="nt-side-k">host</span><span class="nt-side-v"><?= $mailCfg['host'] !== '' ? e($mailCfg['host']) : 'not set' ?></span>
      <span class="nt-side-k">from</span><span class="nt-side-v"><?= $mailCfg['from_address'] !== '' ? e($mailCfg['from_address']) : 'not set' ?></span>
    </div>
  </section>
<?php else: ?>
  <p class="nt-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>
    Mail is configured through <b><?= e($mailCfg['host']) ?></b>. Failed messages can be re-sent.</p>
<?php endif; ?>

<?php if ($causes): ?>
  <section class="nt-causes">
    <h2 class="nt-sec-h">Why messages failed</h2>
    <ul class="nt-cause-list">
      <?php foreach ($causes as $why => $n): ?>
      <li class="nt-cause">
        <span class="nt-cause-n"><?= $n ?></span>
        <span class="nt-cause-why"><?= e((string)$why) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<nav class="nt-filters" aria-label="Filter messages">
  <form method="get" class="nt-search" action="<?= url('admin/notifications.php') ?>">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Email, template or booking number" aria-label="Search messages">
    <?php if ($status): ?><input type="hidden" name="st" value="<?= e($status) ?>"><?php endif; ?>
    <?php if ($template): ?><input type="hidden" name="tp" value="<?= e($template) ?>"><?php endif; ?>
    <button type="submit">Search</button>
  </form>
  <div class="nt-chips">
    <?php foreach (['' => 'All', 'failed' => 'Failed', 'sent' => 'Delivered', 'queued' => 'Queued'] as $k => $label):
      $n = $k === '' ? $total : $counts[$k]; ?>
    <a class="nt-chip<?= $status === $k ? ' is-on' : '' ?>"
       href="<?= url('admin/notifications.php?' . http_build_query(array_filter(['st' => $k, 'tp' => $template, 'q' => $q]))) ?>"
       <?= $status === $k ? 'aria-current="true"' : '' ?>><?= e($label) ?><b><?= $n ?></b></a>
    <?php endforeach; ?>
  </div>
  <div class="nt-chips nt-chips-tpl">
    <span class="nt-tpl-k">Template</span>
    <a class="nt-chip<?= $template === '' ? ' is-on' : '' ?>" href="<?= url('admin/notifications.php?' . http_build_query(array_filter(['tp' => '', 'st' => $status, 'q' => $q]))) ?>">any</a>
    <?php foreach ($templates as $t): ?>
    <a class="nt-chip<?= $template === $t['template'] ? ' is-on' : '' ?>" href="<?= url('admin/notifications.php?' . http_build_query(array_filter(['tp' => $t['template'], 'st' => $status, 'q' => $q]))) ?>"><?= e(nt_label((string)$t['template'])) ?><b><?= (int)$t['c'] ?></b></a>
    <?php endforeach; ?>
  </div>
</nav>

<?php if (!$rows): ?>
  <div class="nt-empty-wrap">
    <p class="nt-blank"><?= $q !== '' || $status !== '' || $template !== '' ? 'No message matches that.' : 'Nothing has been sent yet.' ?></p>
    <p class="nt-blank-sub">
      <?php if ($q !== '' || $status !== '' || $template !== ''): ?>
        <a href="<?= url('admin/notifications.php') ?>">Show every message</a>.
      <?php else: ?>
        Messages appear here the moment the site tries to contact a customer &mdash; a booking confirmation, a driver update, a refund receipt.
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>
  <p class="nt-lede">Each row links straight to the recipient. Only the template is kept, never the message itself, so a failed one cannot be re-sent exactly as it was written.</p>
  <ol class="nt-list">
    <?php
    foreach ($rows as $r):
      $ok = $r['status'] === 'sent';
      $when = strtotime((string)$r['created_at']);
      $ago = max(0, (int)floor((time() - $when) / 86400));
      $bn = (string)($r['booking_number'] ?? '');
      // The stored row has a template key, not the rendered message, so there
      // is nothing honest to resend. Put the owner on the address instead.
      $subject = rawurlencode('About your Exotic Lane booking' . ($bn !== '' ? ' ' . $bn : ''));
      $mailTo = 'mailto:' . rawurlencode((string)$r['email']) . '?subject=' . $subject;
    ?>
    <li class="nt-row<?= $ok ? ' is-sent' : ' is-failed' ?>">
      <span class="nt-state nt-state-<?= e((string)$r['status']) ?>"><?= $ok ? 'sent' : e((string)$r['status']) ?></span>
      <div class="nt-row-main">
        <span class="nt-row-top">
          <span class="nt-tpl"><?= e(nt_label((string)$r['template'])) ?></span>
          <code class="nt-tpl-key"><?= e((string)$r['template']) ?></code>
        </span>
        <span class="nt-row-to">
          <a href="<?= $mailTo ?>"><?= e((string)$r['email']) ?></a>
          <span class="nt-role">to a <?= e((string)$r['recipient_type']) ?></span>
        </span>
        <span class="nt-row-meta">
          <?php if ($bn !== ''): ?>
            <a href="<?= url('admin/bookings.php?action=view&n=' . urlencode($bn)) ?>">booking <?= e($bn) ?></a>
          <?php elseif ($r['booking_id']): ?>
            <span class="nt-dim">booking #<?= (int)$r['booking_id'] ?> (not on file)</span>
          <?php else: ?>
            <span class="nt-dim">no booking</span>
          <?php endif; ?>
          <span class="nt-dim">&middot; <?= $ago === 0 ? 'today' : ($ago === 1 ? 'yesterday' : $ago . ' days ago') ?></span>
        </span>
      </div>
      <div class="nt-row-act">
        <?php if (!$ok): ?>
          <a class="nt-mail" href="<?= e($mailTo) ?>"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Email them</a>
        <?php else: ?>
          <span class="nt-sent-at"><?= $r['sent_at'] ? e(date('j M, H:i', strtotime((string)$r['sent_at']))) : '' ?></span>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ol>
<?php endif; ?>

<?php else: ?>
<section class="nt-news">
  <header class="nt-news-head">
    <h2 class="nt-sec-h">Who receives it</h2>
    <p class="nt-news-sub"><?= $subActive ?> active<?= $subGone > 0 ? ' &middot; ' . $subGone . ' removed' : '' ?></p>
  </header>
  <?php if (!$mailReady): ?>
    <p class="nt-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>
      You can manage this list now, but no issue will actually reach anyone until mail is set up.</p>
  <?php endif; ?>
  <?php if (!$subs): ?>
    <p class="nt-blank">Nobody has signed up yet.</p>
    <p class="nt-blank-sub">Addresses from the newsletter form on the site land here.</p>
  <?php else: ?>
    <ul class="nt-subs">
      <?php foreach ($subs as $s):
        $active = $s['status'] === 'active'; ?>
      <li class="nt-addr<?= $active ? '' : ' is-off' ?>">
        <span class="nt-addr-mail"><?= e($s['email']) ?></span>
        <span class="nt-addr-since">
          <?= $active
              ? 'joined ' . e(date('j M Y', strtotime((string)$s['created_at'])))
              : 'removed ' . e(date('j M Y', strtotime((string)($s['unsubscribed_at'] ?: $s['created_at'])))) ?>
        </span>
        <?php if ($active): ?>
        <form method="post" class="nt-addr-form">
          <?= csrf_field() ?>
          <input type="hidden" name="op" value="unsubscribe">
          <input type="hidden" name="view" value="newsletter">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button class="nt-unsub" data-confirm="Remove <?= e((string)$s['email']) ?> from the newsletter? They stop receiving it straight away.">Remove</button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'Outbound | Admin';
$navActive = 'notifications.php';
require APP_ROOT . '/views/layouts/admin.php';