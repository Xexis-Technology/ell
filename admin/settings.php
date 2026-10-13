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

    // ---- passkeys ----------------------------------------------------------
    // Three endpoints, each returning JSON the browser's WebAuthn call feeds
    // straight back. Kept together so the challenge lifecycle is in one place.

    if ($op === 'passkey_register_begin') {
        passkey_sweep_challenges($pdo);
$challenge = b64url_encode(random_bytes(32));
        $cid = passkey_put_challenge($pdo, 'register', $challenge, (int)$admin['id']);

        // Already-registered credentials are excluded so the same device cannot
        // quietly enrol the same key twice.
        $st = $pdo->prepare('SELECT credential_id FROM admin_passkeys WHERE admin_id = ?');
        $st->execute([(int)$admin['id']]);
        $exclude = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $exclude[] = ['type' => 'public-key', 'id' => (string)$r['credential_id']];
        }

        // user.id is the admin's id, base64url, and must be stable - it is how
        // the device knows which account it is offering a passkey for.
        $userId = b64url_encode((string)(int)$admin['id']);

        header('Content-Type: application/json');
        echo json_encode([
            'challengeId' => $cid,
            'publicKey' => [
                'challenge' => $challenge,
                'rp' => ['id' => passkey_rp_id(), 'name' => 'Exotic Lane Limo'],
                'user' => ['id' => $userId, 'name' => (string)$admin['email'], 'displayName' => (string)$admin['name']],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],    // ES256, first choice
                    ['type' => 'public-key', 'alg' => -257],  // RS256, for older devices
                ],
                'timeout' => 120000,
                'attestation' => 'none',
                'authenticatorSelection' => [
                    'residentKey' => 'preferred',
                    'userVerification' => 'preferred',
                ],
                'excludeCredentials' => $exclude,
            ],
        ]);
        exit;
    }

    if ($op === 'passkey_register_finish') {
        $cid = (string)($_POST['challengeId'] ?? '');
        $challenge = passkey_take_challenge($pdo, $cid, 'register');
        if ($challenge === null) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'This setup attempt expired. Try again.']);
            exit;
        }
        $payload = json_decode((string)($_POST['payload'] ?? ''), true);
        if (!is_array($payload)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'The device sent nothing readable.']);
            exit;
        }

        $res = passkey_credential_from_attestation([
            'attestationObject' => (string)($payload['response']['attestationObject'] ?? ''),
        ]);

        // A retry, or a double-click, must not enrol the same key twice. The
        // database has a unique index on credential_id, but a clear message
        // beats a driver error, so check first.
        if ($res['ok']) {
            $dup = $pdo->prepare('SELECT label FROM admin_passkeys WHERE credential_id = ? LIMIT 1');
            $dup->execute([b64url_encode((string)$res['credential_id'])]);
            if ($dup->fetch()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'That passkey is already on this account.']);
                exit;
            }
        }

        // The challenge must be the one this browser was issued. Skipping this
        // would let an attacker enrol their own passkey by replaying a capture.
        $clientData = json_decode(b64url_decode((string)($payload['response']['clientDataJSON'] ?? '')), true);
        if (!is_array($clientData) || (string)($clientData['challenge'] ?? '') !== $challenge) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'This setup attempt did not match. Try again.']);
            exit;
        }
        if ((string)($clientData['type'] ?? '') !== 'webauthn.create') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'That was not a passkey setup.']);
            exit;
        }
        if ((string)($clientData['origin'] ?? '') !== passkey_expected_origin()) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'That came from a different website.']);
            exit;
        }

        if (!$res['ok']) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => (string)$res['reason']]);
            exit;
        }

        $credId = b64url_encode((string)$res['credential_id']);
        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '') $label = (string)($_SERVER['HTTP_USER_AGENT'] ?? '') !== '' ? 'This device' : 'Passkey';
        $label = mb_substr($label, 0, 120);
        $transports = '';
        if (isset($payload['response']['transmission']) && is_array($payload['response']['transmission'])) {
            $transports = mb_substr(implode(',', array_slice($payload['response']['transmission'], 0, 6)), 0, 190);
        }

        try {
            $algId = -7;
            foreach (PASSKEY_ALGS as $id => $a) {
                if ($a['name'] === (string)$res['alg']) { $algId = (int)$id; break; }
            }

            $pdo->prepare('INSERT INTO admin_passkeys
                    (admin_id, credential_id, public_key, alg_id, alg_name, label, transports)
                  VALUES (?,?,?,?,?,?,?)')
                ->execute([
                    (int)$admin['id'], $credId, (string)$res['pem'],
                    $algId, (string)$res['alg'], $label,
                    $transports !== '' ? $transports : null,
                ]);
        } catch (Throwable $ex) {
            if (str_contains($ex->getMessage(), 'uq_passkey_cred')) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'That passkey is already registered.']);
                exit;
            }
            throw $ex;
        }

        audit($pdo, 'admin', (int)$admin['id'], 'passkey.registered', 'admin', (int)$admin['id'], ['label' => $label, 'alg' => $res['alg']]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'label' => $label]);
        exit;
    }

    if ($op === 'passkey_rename' || $op === 'passkey_remove') {
        $credId = trim((string)($_POST['credentialId'] ?? ''));
        if ($op === 'passkey_remove') {
            // Last passkey out means no way back in without the password.
            $st = $pdo->prepare('SELECT COUNT(*) FROM admin_passkeys WHERE admin_id = ?');
            $st->execute([(int)$admin['id']]);
            $n = (int)$st->fetchColumn();
            $st = $pdo->prepare('SELECT COUNT(*) FROM admin_passkeys WHERE admin_id = ? AND credential_id = ?');
            $st->execute([(int)$admin['id'], $credId]);
            if ((int)$st->fetchColumn() > 0 && $n <= 1) {
                $msg = 'This is your only passkey. Remove it and you sign in with your password only.';
                $isErr = true;
            } else {
                $st = $pdo->prepare('DELETE FROM admin_passkeys WHERE admin_id = ? AND credential_id = ?');
                $st->execute([(int)$admin['id'], $credId]);
                audit($pdo, 'admin', (int)$admin['id'], 'passkey.removed', 'admin', (int)$admin['id'], null);
                $msg = 'Passkey removed.';
            }
        } else {
            $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 120);
            $pdo->prepare('UPDATE admin_passkeys SET label = ? WHERE admin_id = ? AND credential_id = ?')
                ->execute([$label, (int)$admin['id'], $credId]);
            $msg = 'Passkey renamed.';
        }
        header('Location: ' . url('admin/settings.php?' . http_build_query(['msg' => $msg, 'err' => $isErr ? 1 : null])));
        exit;
    }

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

// Passkeys registered on this admin account.
$st = $pdo->prepare('SELECT * FROM admin_passkeys WHERE admin_id = ? ORDER BY created_at');
$st->execute([(int)$admin['id']]);
$passkeys = $st->fetchAll(PDO::FETCH_ASSOC);
$hasPasskey = $passkeys !== [];
$onlyPasskey = $hasPasskey && count($passkeys) === 1;

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
    <p class="st-sub">How you sign in, the three services this site cannot work without, and the numbers the office works by.
      <strong>Test</strong> contacts each provider for real — a saved key is not the same as a working one.</p>
  </div>
  <p class="st-tally">
    <span class="st-count is-ok"><b><?= $working ?></b> working</span>
    <span class="st-count is-bad"><b><?= $broken ?></b> not working</span>
    <span class="st-count is-wait"><b><?= $untested ?></b> to test</span>
  </p>
</header>

<section class="pk-desk" aria-labelledby="pk-h">
  <header class="st-head">
    <div>
      <p class="eyebrow">Signing in</p>
      <h2 class="font-display" id="pk-h">Passkeys</h2>
      <p class="st-sub">A passkey is a key held by this device. Signing in proves you have it —
        there is no password to type, reuse or phish, and nothing secret is sent to this site.</p>
    </div>
    <button type="button" class="st-btn st-btn-gold" id="pk-add">
      <i class="fa-solid fa-fingerprint" aria-hidden="true"></i> Add a passkey
    </button>
  </header>

  <div class="pk-msg" id="pk-msg" role="status" aria-live="polite" hidden></div>

  <?php if (!$hasPasskey): ?>
  <div class="st-note" style="margin-top:1.15rem">
    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
    <span>No passkeys yet. Add one from the device you use every day, then keep your password as the way
      back in — a passkey on a phone you do not carry is not a backup.</span>
  </div>
  <?php else: ?>
  <ul class="pk-list">
    <?php foreach ($passkeys as $pk):
      $used = trim((string)($pk['last_used_at'] ?? '')); ?>
    <li class="pk-row">
      <span class="pk-mark" aria-hidden="true"><i class="fa-solid fa-key"></i></span>
      <div class="pk-what">
        <form method="post" class="pk-rename">
          <?= csrf_field() ?>
          <input type="hidden" name="op" value="passkey_rename">
          <input type="hidden" name="credentialId" value="<?= e((string)$pk['credential_id']) ?>">
          <label class="sr-only" for="pkname-<?= (int)$pk['id'] ?>">Name for this passkey</label>
          <input id="pkname-<?= (int)$pk['id'] ?>" name="label" value="<?= e((string)$pk['label']) ?>" maxlength="120">
          <button type="submit" class="pk-save">Save name</button>
        </form>
        <p class="pk-meta">
          <?= e((string)$pk['alg_name']) ?>
          <?php if ($used !== ''): ?>
            · last used <?= e(date('j M Y, H:i', (int)strtotime($used))) ?>
          <?php else: ?>
            · not used yet
          <?php endif; ?>
          · added <?= e(date('j M Y', (int)strtotime((string)$pk['created_at']))) ?>
        </p>
      </div>
      <form method="post" class="pk-del">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="passkey_remove">
        <input type="hidden" name="credentialId" value="<?= e((string)$pk['credential_id']) ?>">
        <button type="submit" class="pk-remove"
                data-confirm="<?= $onlyPasskey
                  ? 'This is your only passkey. Removing it means you sign in with your password only. Remove it?'
                  : 'Remove this passkey? You will need to use your password on this device again.' ?>">
          <i class="fa-solid fa-trash" aria-hidden="true"></i> Remove
        </button>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

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

<script>
/*
 * Passkey enrolment. The server issues a single-use challenge, the browser
 * asks the device to sign it, and the response goes back to be verified
 * server-side. Nothing is trusted from the browser beyond what a signature
 * proves.
 */
(function () {
  const addBtn = document.getElementById('pk-add');
  const box = document.getElementById('pk-msg');
  if (!addBtn || !box) return;

  // CSRF: reuse the token the page already rendered, read once at load so a
  // missing form is caught here rather than as a null-reference later.
  const csrfInput = document.querySelector('input[name=_csrf]');
  const csrf = csrfInput ? csrfInput.value : '';

  const b64uToBuf = (s) => {
    const pad = '='.repeat((4 - (s.length % 4)) % 4);
    const raw = atob(s.replace(/-/g, '+').replace(/_/g, '/') + pad);
    return Uint8Array.from(raw, c => c.charCodeAt(0));
  };
  const bufToB64u = (buf) => {
    const bytes = new Uint8Array(buf);
    let s = '';
    for (const b of bytes) s += String.fromCharCode(b);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };
  const post = async (body) => {
    const res = await fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: new URLSearchParams(body).toString(),
      credentials: 'same-origin',
    });
    const text = await res.text();
    try { return JSON.parse(text); } catch (e) { return { ok: false, error: 'The server sent something unreadable.' }; }
  };
  const say = (text, kind) => {
    box.hidden = false;
    box.className = 'pk-msg is-' + kind;
    box.textContent = text;
  };

  if (!window.PublicKeyCredential) {
    addBtn.disabled = true;
    addBtn.title = 'This browser cannot use passkeys.';
    say('This browser does not support passkeys. Use a current version of Chrome, Safari, Edge or Firefox — or keep signing in with your password.', 'bad');
  }

  // Base64url for the binary fields the browser insists on sending as text.
  const conv = (obj) => {
    if (obj instanceof ArrayBuffer) return bufToB64u(new Uint8Array(obj));
    if (ArrayBuffer.isView(obj)) return bufToB64u(new Uint8Array(obj.buffer, obj.byteOffset, obj.byteLength));
    if (Array.isArray(obj)) return obj.map(conv);
    if (obj && typeof obj === 'object') {
      const out = {};
      for (const k in obj) out[k] = conv(obj[k]);
      return out;
    }
    return obj;
  };
  const deconv = (s) => (typeof s === 'string' ? b64uToBuf(s) : s);

  addBtn.addEventListener('click', async () => {
    if (!window.PublicKeyCredential) return;
    addBtn.disabled = true;
    say('Follow your device’s instructions to create the passkey.', 'busy');
    try {
      const begin = await post({ op: 'passkey_register_begin', _csrf: csrf });
      if (!begin.publicKey) throw new Error(begin.error || 'Could not start.');

      const pk = begin.publicKey;
      pk.challenge = deconv(pk.challenge);
      pk.user.id = deconv(pk.user.id);
      if (pk.excludeCredentials) pk.excludeCredentials.forEach(c => { c.id = deconv(c.id); });

      const cred = await navigator.credentials.create({ publicKey: pk });
      if (!cred) throw new Error('No passkey was created.');

      const finish = await post({
        op: 'passkey_register_finish',
        challengeId: begin.challengeId,
        _csrf: csrf,
        label: 'This device',
        payload: JSON.stringify(conv({
          id: cred.id,
          rawId: bufToB64u(new Uint8Array(cred.rawId)),
          type: cred.type,
          response: {
            clientDataJSON: bufToB64u(new Uint8Array(cred.response.clientDataJSON)),
            attestationObject: bufToB64u(new Uint8Array(cred.response.attestationObject)),
            transports: cred.response.getTransports ? cred.response.getTransports() : [],
          },
        })),
      });

      if (!finish.ok) throw new Error(finish.error || 'The server refused that passkey.');
      say('Passkey added. You can sign in with it from now on.', 'ok');
      setTimeout(() => window.location.reload(), 900);
    } catch (err) {
      // A cancelled prompt is not a failure worth shouting about.
      const m = err && err.name === 'NotAllowedError'
        ? 'That was cancelled, so nothing was changed.'
        : (err && err.message) || 'Something went wrong.';
      say(m, /cancelled/i.test(m) ? 'idle' : 'bad');
      addBtn.disabled = false;
    }
  });
})();
</script>
<?php
$content = ob_get_clean();
$pageTitle = 'Settings | Admin';
$navActive = 'settings.php';
require APP_ROOT . '/views/layouts/admin.php';