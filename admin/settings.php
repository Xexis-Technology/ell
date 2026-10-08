<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
$admin = require_role('admin');

/**
 * Settings is now two things that were previously conflated: the outside
 * services this site depends on, and the numbers the office works by.
 * Everything about how the site presents itself to a search engine moved to
 * CMS, where the rest of that story already lives.
 */
$msg = '';
$isErr = false;

// Operations rules. Plain numbers, no secrets, stored the way every other
// setting is stored.
const OPS_KEYS = [
    'pricing_mode' => ['Pricing mode', 'per_mile|hourly', 'How a fare is worked out: by the mile, or by the hour.'],
    'tax_percent' => ['Tax %', 'number', 'Added to the fare at checkout. 0 to charge no tax.'],
    'currency' => ['Currency', 'text', 'The code shown on every invoice, e.g. USD.'],
    'pickup_cutoff_hours' => ['Pickup cutoff (hours)', 'number', 'How late a customer can still book.'],
    'lead_time_hours' => ['Minimum notice (hours)', 'number', 'The earliest a car can be booked for.'],
    'payout_interval_days' => ['Payout interval (days)', 'number', 'How often drivers get paid.'],
    'maps_enabled' => ['Show the map picker', '0|1', 'Whether the booking form offers a pin instead of plain address fields.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'save_ops') {
        foreach (OPS_KEYS as $k => [$label, $kind, $why]) {
            if (!array_key_exists($k, $_POST)) continue;
            $val = trim((string)$_POST[$k]);
            if ($kind === 'number' && $val !== '' && !is_numeric($val)) {
                $msg = 'That value has to be a number.';
                $isErr = true;
                break;
            }
            if ($kind === 'per_mile|hourly') $val = $val === 'hourly' ? 'hourly' : 'per_mile';
            if ($kind === '0|1') $val = $val === '1' ? '1' : '0';
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=NOW()')->execute([$k, $val]);
        }
        if (!$isErr) {
            audit($pdo, 'admin', (int)$admin['id'], 'settings.operations', 'settings', null, array_keys(OPS_KEYS));
            $msg = 'Settings saved.';
        }
        header('Location: ' . url('admin/settings.php?msg=' . urlencode($msg) . ($isErr ? '&err=1' : '')));
        exit;
    }

    if ($op === 'save_keys') {
        $slug = (string)($_POST['slug'] ?? '');
        if (!isset(Integrations::SPEC[$slug])) {
            $msg = 'Unknown integration.';
            $isErr = true;
        } else {
            $pairs = [];
            foreach (Integrations::SPEC[$slug]['fields'] as [$key, $label, $secret]) {
                // Only a field the form actually rendered may be written.
                if (!array_key_exists($key, $_POST)) continue;
                $pairs[$key] = trim((string)$_POST[$key]);
            }
            $res = Integrations::env_write($pairs);
            if (!$res['ok']) {
                $msg = (string)$res['error'];
                $isErr = true;
            } else {
                // A save makes the stored answer out of date in the way that
                // matters: the keys changed, so the old "Working" is no longer
                // evidence about what is on disk now.
                Integrations::remember($pdo, $slug, 'idle', 'Keys saved. Test to confirm the service answers.');
                // Key names only. The values never reach the audit trail.
                audit($pdo, 'admin', (int)$admin['id'], 'integration.keys', 'integration', null, [
                    'integration' => $slug,
                    'changed' => $res['changed'],
                    'cleared' => $res['cleared'],
                ]);
                $n = count($res['changed']) + count($res['cleared']);
                $msg = $n === 0 ? 'Nothing changed — the fields were left as they were.' : ($n === 1 ? '1 key saved.' : $n . ' keys saved.');
                $isErr = $msg === 'Nothing changed — the fields were left as they were.';
            }
        }
        header('Location: ' . url('admin/settings.php?' . http_build_query([
            'int' => $slug, 'dialog' => $slug, 'msg' => $msg, 'err' => $isErr ? 1 : null,
        ])));
        exit;
    }

    if ($op === 'test') {
        $slug = (string)($_POST['slug'] ?? '');
        if (!isset(Integrations::SPEC[$slug])) {
            header('Location: ' . url('admin/settings.php?msg=' . urlencode('Unknown integration.') . '&err=1'));
            exit;
        }
        $env = Integrations::env_read();
        $vals = [];
        foreach (Integrations::SPEC[$slug]['fields'] as [$key]) {
            $vals[$key] = (string)($env[$key] ?? '');
        }
        $res = Integrations::probe($slug, $vals);
        Integrations::remember($pdo, $slug, $res['state'], $res['note']);
        // Note only. What the provider said is safe to log; what was sent to
        // prove it is not.
        audit($pdo, 'admin', (int)$admin['id'], 'integration.tested', 'integration', null, ['integration' => $slug, 'result' => $res['state']]);
        header('Location: ' . url('admin/settings.php?' . http_build_query([
            'int' => $slug, 'tested' => $slug, 'msg' => $res['note'], 'state' => $res['state'],
        ])));
        exit;
    }

    $msg = 'Nothing to save.';
    $isErr = true;
    header('Location: ' . url('admin/settings.php?msg=' . urlencode($msg) . '&err=1'));
    exit;
}

$msg = (string)($_GET['msg'] ?? '');
$isErr = isset($_GET['err']);
$justTested = (string)($_GET['tested'] ?? '');

$env = Integrations::env_read();
$ops = [];
foreach (OPS_KEYS as $k => [$label, $kind, $why]) $ops[$k] = (string)setting($pdo, $k, '');

// Build each card's state from the last real test.
$cards = [];
foreach (Integrations::SPEC as $slug => $conf) {
    $last = Integrations::last_result($pdo, $slug);
    // A result older than a day is a memory, not an answer. Say so instead of
    // showing a green lamp that may no longer be true.
    $state = $last['state'];
    $shown = $state;
    if ($state === 'ok' && $last['stale']) $shown = 'idle';
    $setCount = 0;
    foreach ($conf['fields'] as [$key]) if (trim((string)($env[$key] ?? '')) !== '') $setCount++;
    $cards[$slug] = [
        'conf' => $conf,
        'last' => $last,
        'state' => $state,
        'shown' => $shown,
        'note' => $slug === $justTested ? $msg : $last['note'],
        'set' => $setCount,
        'total' => count($conf['fields']),
    ];
}

$working = count(array_filter($cards, fn($c) => $c['shown'] === 'ok'));
$broken = count(array_filter($cards, fn($c) => $c['shown'] === 'bad'));
$untested = count(array_filter($cards, fn($c) => $c['shown'] === 'idle' || $c['shown'] === 'off'));

ob_start();
?>
<?php if ($msg): ?><div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<header class="st-head">
  <div>
    <p class="eyebrow">System</p>
    <h1 class="font-display">Settings</h1>
    <p class="st-sub">The three services this site cannot work without, and the numbers the office works by.
      <strong>Test</strong> contacts each provider for real — a saved key is not the same as a working one.</p>
  </div>
  <p class="st-tally">
    <span class="st-count is-ok"><b><?= $working ?></b> working</span>
    <span class="st-count is-bad"><b><?= $broken ?></b> not working</span>
    <span class="st-count is-wait"><b><?= $untested ?></b> to test</span>
  </p>
</header>

<?php if (!Integrations::env_writable()): ?>
<div class="st-note is-bad" style="margin-top:1.25rem">
  <i class="fa-solid fa-lock" aria-hidden="true"></i>
  <span>Keys are saved in <code>.env</code>, and the web server cannot write to that file on this machine.
    Edit it directly, or give the file write permission, and the forms below will work.</span>
</div>
<?php endif; ?>

<div class="st-grid">
<?php foreach ($cards as $slug => $c):
  $conf = $c['conf'];
  $state = $c['shown'];
  $word = Integrations::STATES[$state] ?? 'Unknown';
  $wasStale = $c['last']['state'] === 'ok' && $c['last']['stale'];
?>
  <article class="st-card is-<?= e($state) ?>">
    <div class="st-top">
      <span class="st-mark" aria-hidden="true"><i class="fa-solid <?= e($conf['icon']) ?>"></i></span>
      <div class="st-who">
        <h2 class="st-name"><?= e($conf['label']) ?></h2>
        <p class="st-blurb"><?= e($conf['blurb']) ?></p>
        <span class="st-lamp is-<?= e($state) ?>"><?= e($word) ?></span>
      </div>
    </div>

    <?php if ($c['note'] !== ''): ?>
    <p class="st-said"><?= e($c['note']) ?><?php if ($c['last']['age'] !== null): ?>
      <span class="st-when<?= $wasStale ? ' st-stale' : '' ?>">tested <?= e((string)$c['last']['age']) ?><?= $wasStale ? ' — too old to trust' : '' ?></span>
    <?php endif; ?></p>
    <?php elseif ($state === 'idle' && !$wasStale): ?>
    <p class="st-said">No test has been run for this service yet.</p>
    <?php endif; ?>

    <?php
    // Only the gaps are listed. A row of ticks for fields already filled is
    // noise; the operator's next move is always the field still empty.
    $missing = [];
    foreach ($conf['fields'] as [$key, $label, $secret]) {
        if (trim((string)($env[$key] ?? '')) === '') $missing[] = [$key, $label];
    }
    ?>
    <div class="st-keys">
      <?php if ($missing === []): ?>
        <span class="st-keys-all"><i class="fa-solid fa-check" aria-hidden="true"></i> Every field is filled in</span>
      <?php else: ?>
        <span class="st-keys-k">Still needed</span>
        <?php foreach ($missing as [$key, $label]): ?>
          <span class="st-key" title="<?= e($label) ?>"><?= e($key) ?></span>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="st-foot">
      <button type="button" class="st-open" data-open-modal="st-<?= e($slug) ?>">
        <i class="fa-solid fa-sliders" aria-hidden="true"></i> Configure <?= e($conf['label']) ?>
      </button>
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="test">
        <input type="hidden" name="slug" value="<?= e($slug) ?>">
        <button class="st-test" type="submit">
          <i class="fa-solid fa-plug-circle-check" aria-hidden="true"></i> Test
        </button>
      </form>
    </div>
  </article>
<?php endforeach; ?>
</div>

<?php foreach ($cards as $slug => $c):
  $conf = $c['conf'];
  $state = $c['shown'];
  $last = $c['last'];
  $opened = (string)($_GET['dialog'] ?? '') === $slug || (string)($_GET['int'] ?? '') === $slug;
?>
  <dialog class="modal st-dlg" id="st-<?= e($slug) ?>"<?= $opened ? ' data-autopen' : '' ?>>
    <form method="post" class="st-dlg-form">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="save_keys">
      <input type="hidden" name="slug" value="<?= e($slug) ?>">

      <header class="st-dlg-head">
        <span class="st-dlg-mark" aria-hidden="true"><i class="fa-solid <?= e($conf['icon']) ?>"></i></span>
        <div style="flex:1;min-width:0">
          <h2 class="st-name"><?= e($conf['label']) ?></h2>
          <p class="st-blurb" style="margin-top:.15rem"><?= e($conf['blurb']) ?></p>
        </div>
        <button type="button" class="st-x" data-close-modal aria-label="Close <?= e($conf['label']) ?> settings">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
      </header>

      <div class="st-dlg-body">
        <?php if ($last['note'] !== '' && (string)($_GET['int'] ?? '') === $slug): ?>
        <p class="st-note is-<?= e($state) ?>">
          <i class="fa-solid <?= $state === 'ok' ? 'fa-circle-check' : ($state === 'bad' ? 'fa-triangle-exclamation' : 'fa-circle-info') ?>" aria-hidden="true"></i>
          <span><?= e($last['note']) ?><?php if ($last['age'] !== null): ?> <span class="st-when" style="display:inline">tested <?= e((string)$last['age']) ?></span><?php endif; ?></span>
        </p>
        <?php endif; ?>

        <?php foreach ($conf['fields'] as [$key, $label, $secret, $placeholder, $why]):
          $d = Integrations::field_for_display($key, $secret, $env); ?>
        <div class="st-field">
          <label for="<?= e($key) ?>"><?= e($label) ?></label>
          <input id="<?= e($key) ?>" name="<?= e($key) ?>" type="<?= $secret ? 'password' : 'text' ?>"
                 value="<?= e($d['value']) ?>" placeholder="<?= e($placeholder) ?>"
                 autocomplete="off" spellcheck="false"
                 <?= $secret && $d['saved'] ? 'data-has-secret="1"' : '' ?>>
          <span class="st-why">
            <code style="font-family:var(--st-mono);font-size:.66rem;color:#8a8a8a"><?= e($key) ?></code> — <?= e($why) ?>
            <?php if ($secret && $d['saved']): ?>
              <span class="st-secret-state"><i class="fa-solid fa-check" aria-hidden="true"></i> saved on this server</span>
            <?php endif; ?>
          </span>
          <?php if ($secret && $d['saved']): ?>
          <label class="st-clear">
            <input type="checkbox" name="<?= e($key) ?>" value="<?= e(Integrations::CLEAR) ?>">
            Remove this key from the server
          </label>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($slug === 'maps'): ?>
        <p class="st-note">
          <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
          <span>Restrict this key in Google Cloud Console to the Maps JavaScript API and to this site's domain.
            An unrestricted key can be found and used by anyone who copies the page source.</span>
        </p>
        <?php elseif ($slug === 'mail'): ?>
        <p class="st-note">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          <span>Test opens the connection and signs in. It does not send anything, so testing never emails a customer.</span>
        </p>
        <?php elseif ($slug === 'stripe'): ?>
        <p class="st-note">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          <span>Test asks Stripe for this account's balance using the secret key. If the webhook secret is missing,
            cards are still charged but never confirmed automatically.</span>
        </p>
        <?php endif; ?>
      </div>

      <footer class="st-dlg-foot">
        <button class="st-btn st-btn-gold" type="submit">Save <?= e($conf['label']) ?> keys</button>
        <button class="st-btn st-btn-line" type="button" data-close-modal>Close</button>
        <span class="st-why" style="margin-left:auto;font-size:.72rem;color:var(--st-dim)">
          Saved to <code>.env</code>, never the database.
        </span>
      </footer>
    </form>
  </dialog>
<?php endforeach; ?>

<section class="st-ops">
  <h2 class="st-ops-h">How the office works</h2>
  <p class="st-ops-sub">Fares, notice and payout</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="save_ops">
    <div class="st-ops-grid">
      <?php foreach (OPS_KEYS as $k => [$label, $kind, $why]): ?>
      <div class="st-field">
        <label for="op-<?= e($k) ?>"><?= e($label) ?></label>
        <?php if ($kind === 'per_mile|hourly'): ?>
          <select id="op-<?= e($k) ?>" name="<?= e($k) ?>">
            <option value="per_mile"<?= $ops[$k] !== 'hourly' ? ' selected' : '' ?>>Per mile</option>
            <option value="hourly"<?= $ops[$k] === 'hourly' ? ' selected' : '' ?>>Per hour</option>
          </select>
        <?php elseif ($kind === '0|1'): ?>
          <select id="op-<?= e($k) ?>" name="<?= e($k) ?>">
            <option value="0"<?= $ops[$k] !== '1' ? ' selected' : '' ?>>Off</option>
            <option value="1"<?= $ops[$k] === '1' ? ' selected' : '' ?>>On</option>
          </select>
        <?php else: ?>
          <input id="op-<?= e($k) ?>" name="<?= e($k) ?>" type="<?= $kind === 'number' ? 'number' : 'text' ?>"
                 <?= $kind === 'number' ? 'step="0.01" min="0"' : '' ?>
                 value="<?= e($ops[$k]) ?>" placeholder="<?= e($why) ?>">
        <?php endif; ?>
        <span class="st-why"><?= e($why) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="st-acts">
      <button class="st-btn st-btn-gold" type="submit">Save settings</button>
      <span class="st-why" style="font-size:.76rem;color:var(--st-dim)">
        How search engines see the site lives in <a href="<?= url('admin/cms.php') ?>" style="color:var(--st-champagne)">SEO &amp; content</a>.
      </span>
    </div>
  </form>
</section>
<?php
$content = ob_get_clean();
$pageTitle = 'Settings | Admin';
$navActive = 'settings.php';
require APP_ROOT . '/views/layouts/admin.php';