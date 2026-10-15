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
    } elseif ($op === 'contact-handled' || $op === 'contact-reopen') {
        /* One inbox needs one way to clear a row and one way to undo a misclick.
           Reopening exists because "I marked the wrong one" is the predictable
           second-day mistake, and without it the only recovery is a database
           edit nobody should have to make. */
        $handled = $op === 'contact-handled';
        $st = $pdo->prepare('SELECT name FROM contact_submissions WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $who = $st->fetchColumn();
        if ($who === false) {
            $msg = 'That message no longer exists.';
            $isErr = true;
        } else {
            $pdo->prepare('UPDATE contact_submissions
                           SET status = ?, handled_at = ' . ($handled ? 'NOW()' : 'NULL') . ',
                               handled_by = ' . ($handled ? '?' : 'NULL') . '
                           WHERE id = ?')->execute($handled ? ['handled', (int)$admin['id'], $id] : ['new', $id]);
            audit($pdo, 'admin', (int)$admin['id'],
                  $handled ? 'contact.handled' : 'contact.reopened',
                  'contact_submission', $id, ['name' => $who]);
            $msg = ($handled ? 'Marked as handled: ' : 'Reopened: ') . $who;
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

// Three subjects that share no records and no filtering: what the site tried to
// send, who subscribed to hear from it, and what people wrote in to say. Tabs,
// because you never need more than one at a time. Status and template stay
// filters inside Messages — a failed and a delivered message are the same
// record in two states.
//
// 'contact' is inbound and the other two are outbound, which is why this page
// is really two pages sharing a header: the log of what went out, and the
// inbox of what came back.
$view = (string)($_GET['view'] ?? 'messages');
if (!in_array($view, ['messages', 'newsletter', 'contact'], true)) $view = 'messages';

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

// ---- user contact ----------------------------------------------------------
// The whole point of storing these is the message body, so this is the one tab
// that renders full text rather than a template key. Nothing here can be
// re-sent, only answered - which is why the action is "mark handled" and not
// "resend".
$contactStatus = (string)($_GET['cs'] ?? '');
if (!in_array($contactStatus, ['new', 'handled'], true)) $contactStatus = '';

$sqlC = 'SELECT c.id, c.name, c.email, c.phone, c.message, c.status, c.created_at,
                c.handled_at, c.handled_by, a.name AS handler
         FROM contact_submissions c LEFT JOIN admins a ON a.id = c.handled_by';
$whereC = [];
$paramsC = [];
if ($contactStatus !== '') { $whereC[] = 'c.status = ?'; $paramsC[] = $contactStatus; }
// Reuse the one search box: on this tab it matches the person or the words
// they wrote, which is what you actually have when someone rings to chase.
if ($q !== '') {
    $whereC[] = '(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.message LIKE ?)';
    array_push($paramsC, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($whereC) $sqlC .= ' WHERE ' . implode(' AND ', $whereC);
// Newest first, and unanswered first: an inbox where a handled row from this
// morning outranks a message that arrived an hour ago is an inbox you stop
// reading.
$sqlC .= " ORDER BY (c.status = 'new') DESC, c.id DESC LIMIT 300";
$stC = $pdo->prepare($sqlC);
$stC->execute($paramsC);
$contacts = $stC->fetchAll();

$stC = $pdo->query('SELECT status, COUNT(*) c FROM contact_submissions GROUP BY status');
$cAll = 0; $cNew = 0; $cDone = 0;
foreach ($stC->fetchAll() as $r) {
    $n = (int)$r['c'];
    $cAll += $n;
    if ($r['status'] === 'new') $cNew = $n; else $cDone += $n;
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
    <p class="eyebrow"><?= $view === 'contact' ? 'Inbound' : 'Content' ?></p>
    <h1 class="font-display"><?= $view === 'contact' ? 'User Contact' : 'Outbound' ?></h1>
    <p class="nt-sub"><?= $view === 'messages'
        ? 'Every message this site tried to send, and whether it reached a person. Failures are grouped by what caused them.'
        : ($view === 'contact'
            ? 'What people wrote through the contact form on the site. Answer them, then mark it handled so it stops asking.'
            : 'The people who asked to hear from this site. Removing an address stops the newsletter reaching them; it does not touch their bookings.') ?></p>
  </div>
  <?php if ($view === 'contact'): ?>
  <p class="nt-figures">
    <span><b><?= $cAll ?></b> received</span>
    <span<?= $cNew > 0 ? ' class="is-bad"' : '' ?>><b><?= $cNew ?></b> to answer</span>
    <?php if ($cDone > 0): ?><span><b><?= $cDone ?></b> handled</span><?php endif; ?>
  </p>
  <?php else: ?>
  <p class="nt-figures">
    <span><b><?= $total ?></b> logged</span>
    <?php if ($counts['sent'] > 0): ?><span><b><?= $counts['sent'] ?></b> delivered</span><?php endif; ?>
    <?php if ($counts['failed'] > 0): ?><span class="is-bad"><b><?= $counts['failed'] ?></b> failed</span><?php endif; ?>
    <span><b><?= $subActive ?></b> subscribers</span>
  </p>
  <?php endif; ?>
</header>

<nav class="nt-tabs" aria-label="Sections">
  <?php foreach ([
      'messages'   => ['Messages', $total, 'Every message the site tried to send'],
      'newsletter' => ['Newsletter', $subActive, 'People who subscribed to hear from the site'],
      'contact'    => ['User Contact', $cNew, 'Messages sent through the contact form'],
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

<?php elseif ($view === 'newsletter'): ?>
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

<?php else: ?>
<section class="nt-news">
  <header class="nt-news-head">
    <h2 class="nt-sec-h"><?= $cNew > 0 ? 'Waiting on you' : 'All caught up' ?></h2>
    <p class="nt-news-sub"><?= $cNew > 0 ? $cNew . ' message' . ($cNew === 1 ? '' : 's') . ' not answered yet' : 'Nothing needs an answer' ?></p>
  </header>

  <nav class="nt-filters" aria-label="Filter contact messages">
    <form method="get" class="nt-search" action="<?= url('admin/notifications.php') ?>">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <input type="hidden" name="view" value="contact">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, email, phone or words in the message" aria-label="Search contact messages">
      <?php if ($contactStatus): ?><input type="hidden" name="cs" value="<?= e($contactStatus) ?>"><?php endif; ?>
      <button type="submit">Search</button>
    </form>
    <div class="nt-chips">
      <?php foreach (['' => 'All', 'new' => 'To answer', 'handled' => 'Handled'] as $k => $label):
        $n = $k === '' ? $cAll : ($k === 'new' ? $cNew : $cDone); ?>
      <a class="nt-chip<?= $contactStatus === $k ? ' is-on' : '' ?>"
         href="<?= url('admin/notifications.php?' . http_build_query(array_filter(['view' => 'contact', 'cs' => $k, 'q' => $q]))) ?>"
         <?= $contactStatus === $k ? 'aria-current="true"' : '' ?>><?= e($label) ?><b><?= $n ?></b></a>
      <?php endforeach; ?>
    </div>
  </nav>

  <?php if (!$contacts): ?>
    <div class="nt-empty-wrap">
      <p class="nt-blank"><?= $q !== '' || $contactStatus !== '' ? 'No message matches that.' : 'Nobody has written in yet.' ?></p>
      <p class="nt-blank-sub">
        <?php if ($q !== '' || $contactStatus !== ''): ?>
          <a href="<?= url('admin/notifications.php?view=contact') ?>">Show every message</a>.
        <?php else: ?>
          Anything sent through the contact form on the site arrives here, in full.
        <?php endif; ?>
      </p>
    </div>
  <?php else: ?>
    <ul class="nt-inbox">
      <?php foreach ($contacts as $c):
        $done = $c['status'] === 'handled';
        $mailTo = 'mailto:' . rawurlencode((string)$c['email']) . '?subject=' . rawurlencode('Re: your message to Exotic Lane');
        $when = (int)strtotime((string)$c['created_at']);
        $ago = max(0, (int)floor((time() - $when) / 86400));
      ?>
      <li class="nt-msg<?= $done ? ' is-done' : '' ?>">
        <div class="nt-msg-main">
          <span class="nt-msg-top">
            <span class="nt-msg-name"><?= e((string)$c['name']) ?></span>
            <a class="nt-msg-mail" href="<?= e($mailTo) ?>"><?= e((string)$c['email']) ?></a>
            <?php if (!empty($c['phone'])): ?>
              <a class="nt-msg-tel" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string)$c['phone'])) ?>"><?= e((string)$c['phone']) ?></a>
            <?php endif; ?>
            <span class="nt-dim">&middot; <?= $ago === 0 ? 'today' : ($ago === 1 ? 'yesterday' : $ago . ' days ago') ?></span>
          </span>
          <?php /* The one tab that shows what was actually written. Collapsed by
                 default: a wall of full messages is unreadable, and the point of
                 an inbox is triage, not reading everything at once. */ ?>
          <details class="nt-msg-body">
            <summary><span class="nt-msg-excerpt"><?= e(mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$c['message'])), 0, 120, '…')) ?></span></summary>
            <p><?= nl2br(e((string)$c['message'])) ?></p>
          </details>
          <?php if ($done): ?>
            <span class="nt-msg-done">
              handled <?= $c['handled_at'] ? e(date('j M, H:i', strtotime((string)$c['handled_at']))) : '' ?><?= $c['handler'] ? ' by ' . e((string)$c['handler']) : '' ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="nt-row-act">
          <a class="nt-mail" href="<?= e($mailTo) ?>"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Reply</a>
          <form method="post" class="nt-addr-form">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="<?= $done ? 'contact-reopen' : 'contact-handled' ?>">
            <input type="hidden" name="view" value="contact">
            <input type="hidden" name="q" value="<?= e($q) ?>">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="nt-unsub" <?= $done ? '' : 'data-confirm="Mark this as handled? It leaves the to-answer list."' ?>><?= $done ? 'Reopen' : 'Mark handled' ?></button>
          </form>
        </div>
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