<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
if (current_user('admin')) redirect('admin/dashboard.php');
$error = '';
$notice = '';

/**
 * Sign in with a passkey.
 *
 * A passkey sign-in names the account by credential, so the email field is not
 * involved - which is the point. The two challenges below are single-use and
 * expire in minutes; a captured response cannot be replayed.
 */
$st = $pdo->prepare("SELECT COUNT(*) FROM admin_passkeys WHERE admin_id IN (SELECT id FROM admins WHERE status = 'active')");
$st->execute();
$passkeyCount = (int)$st->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'pk_begin') {
        passkey_sweep_challenges($pdo);
        header('Content-Type: application/json');
        echo json_encode([
            'challenge' => b64url_encode(random_bytes(32)),
            'rpId' => passkey_rp_id(),
        ]);
        exit;
    }

    if ($op === 'pk_finish') {
        header('Content-Type: application/json');

        $payload = json_decode((string)($_POST['payload'] ?? ''), true);
        $challenge = (string)($_POST['challenge'] ?? '');
        if (!is_array($payload) || $challenge === '') {
            echo json_encode(['ok' => false, 'error' => 'That sign-in could not be read.']);
            exit;
        }

        // Look the credential up directly. It is the account identifier.
        $credId = (string)($payload['id'] ?? '');
        $st = $pdo->prepare(
            'SELECT k.*, a.name, a.email, a.status FROM admin_passkeys k
             JOIN admins a ON a.id = k.admin_id
             WHERE k.credential_id = ? LIMIT 1'
        );
        $st->execute([$credId]);
        $cred = $st->fetch();
        if (!$cred || $cred['status'] !== 'active') {
            echo json_encode(['ok' => false, 'error' => 'That passkey is not registered here.']);
            exit;
        }

        $r = passkey_verify_assertion($cred, $payload, $challenge, passkey_expected_origin());
        if (!$r['ok']) {
            audit($pdo, 'admin', (int)$cred['admin_id'], 'passkey.rejected', 'admin', (int)$cred['admin_id'], ['reason' => $r['reason'] ?? '']);
            echo json_encode(['ok' => false, 'error' => (string)$r['reason']]);
            exit;
        }

        $pdo->prepare('UPDATE admin_passkeys SET sign_counter = ?, last_used_at = NOW() WHERE id = ?')
            ->execute([(int)$r['new_counter'], (int)$cred['id']]);

        rate_limit_clear('admin:' . ($_SERVER['REMOTE_ADDR'] ?? 'x'));
        login_user('admin', ['id' => (int)$cred['admin_id'], 'name' => $cred['name'], 'email' => $cred['email'], 'status' => 'active']);
        $pdo->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([(int)$cred['admin_id']]);
        audit($pdo, 'admin', (int)$cred['admin_id'], 'auth.login', 'admin', (int)$cred['admin_id'], ['via' => 'passkey']);
        echo json_encode(['ok' => true, 'redirect' => url('admin/dashboard.php')]);
        exit;
    }

    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $pw = $_POST['password'] ?? '';
    $key = 'admin:' . ($_SERVER['REMOTE_ADDR'] ?? 'x');
    if (!rate_limit_check($key)) {
        $error = 'Too many attempts. Try again later.';
    } else {
        $st = $pdo->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u && $u['status'] === 'active' && password_verify($pw, $u['password_hash'])) {
            rate_limit_clear($key);
            login_user('admin', $u);
            $pdo->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
            audit($pdo, 'admin', (int)$u['id'], 'auth.login', 'admin', (int)$u['id'], null);
            redirect('admin/dashboard.php');
        }
        rate_limit_hit($key);
        $error = 'Invalid credentials.';
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Admin Sign in | Exotic Lane Limo</title><script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script><link rel="stylesheet" href="<?= asset('css/app.css?v=20261008a') ?>"></head>
<body class="font-ui">
<div class="min-h-screen flex items-center justify-center px-4 py-10">
  <div class="w-full max-w-4xl bg-[#0F0F0E] border border-[#262628] rounded-3xl overflow-hidden grid md:grid-cols-2">
    <div class="relative min-h-[240px] md:min-h-full">
      <div class="absolute inset-0 bg-gradient-to-br from-[#181819] to-[#AB8868]"></div>
      <img src="https://images.unsplash.com/photo-1502877338535-766e1452684a?auto=format&fit=crop&w=800&q=60" alt="Luxury car at night" class="absolute inset-0 w-full h-full object-cover" loading="lazy" decoding="async" onerror="this.style.display='none'">
      <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C]/90 via-[#0A0A0C]/30 to-transparent"></div>
      <div class="absolute bottom-0 p-6">
        <p class="font-display text-2xl text-[#F3D4A6]">Exotic Lane Limo</p>
        <p class="font-display text-xl text-[#F9F9F9] mt-1">Operations console</p>
        <p class="text-xs text-[#E5E5E3] mt-2">Bookings · dispatch · payouts — one secure sign-in.</p>
      </div>
    </div>
    <div class="p-6 md:p-10">
      <p class="font-display text-xl text-[#F3D4A6] text-center">Exotic Lane Limo</p>
      <h1 class="font-display text-3xl text-[#F9F9F9] text-center mt-2">Login to your account</h1>
      <p class="mt-3 text-center"><span class="inline-flex items-center gap-2 text-[11px] tracking-widest border border-[#A3322F] text-[#f3c1bd] rounded-full px-3 py-1">ADMIN ONLY — NOT FOR CUSTOMERS OR DRIVERS</span></p>
      <p class="text-xs text-[#AB8868] text-center mt-2">Welcome back. Enter your details to sign in.</p>
      <p class="text-xs text-center mt-1"><a class="underline" href="<?= url('auth/login.php') ?>">Customer? Sign in here</a> · <a class="underline" href="<?= url('driver/index.php') ?>">Driver? Go to driver portal</a></p>
      <?php if ($error): ?><div class="alert alert-err mt-4"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="space-y-4 mt-6" id="pw-form"><?= csrf_field() ?>
        <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" class="input rounded-full" required autocomplete="email" placeholder="Enter your email"></div>
        <div><label class="label" for="password">Password</label><input id="password" type="password" name="password" class="input rounded-full" required autocomplete="current-password" placeholder="Enter your password"></div>
        <p class="text-right text-xs"><a class="underline" href="<?= url('admin/forgot-password.php') ?>">Forgot password?</a></p>
        <button class="btn-gold rounded-full w-full py-3 text-sm font-semibold">Login</button>
      </form>

      <?php if ($passkeyCount > 0): ?>
      <div class="login-split" id="pk-divider" hidden>
        <span>or</span>
      </div>
      <div id="pk-area" hidden>
        <button type="button" id="pk-btn" class="pk-login">
          <i class="fa-solid fa-fingerprint" aria-hidden="true"></i>
          <span>Sign in with a passkey</span>
        </button>
        <p class="pk-login-msg" id="pk-msg" role="status" aria-live="polite" hidden></p>
      </div>
      <?php endif; ?>

      <p class="text-[11px] text-[#AB8868] text-center mt-4">Rate-limited sign-in · every login is audit-logged.</p>
      <p class="text-xs text-[#AB8868] text-center mt-2"><a class="underline" href="<?= url('index.php') ?>">← Back to site</a></p>
    </div>
  </div>
</div>
<?php if ($passkeyCount > 0): ?>
<script>
/*
 * Passkey sign-in. Two round trips: the server issues a single-use challenge,
 * the device signs it, and the signed response is verified server-side before
 * anyone is logged in.
 */
(function () {
  const btn = document.getElementById('pk-btn');
  if (!btn) return;
  const box = document.getElementById('pk-msg');
  const divider = document.getElementById('pk-divider');

  const b64uToBuf = (s) => {
    const pad = '='.repeat((4 - (s.length % 4)) % 4);
    const raw = atob(s.replace(/-/g, '+').replace(/_/g, '/') + pad);
    return Uint8Array.from(raw, c => c.charCodeAt(0));
  };
  const bufToB64u = (buf) => {
    let s = '';
    for (const b of new Uint8Array(buf)) s += String.fromCharCode(b);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };
  const say = (t, k) => {
    box.hidden = false;
    box.className = 'pk-login-msg is-' + k;
    box.textContent = t;
  };

  if (!window.PublicKeyCredential) {
    btn.disabled = true;
    say('This browser cannot use passkeys. Sign in with your password.', 'bad');
    return;
  }
  // Offer the passkey button only where the browser can actually prompt, so a
  // dead control is never shown.
  if (window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
    Promise.resolve(window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable())
      .then((ok) => {
        divider.hidden = false;
        document.getElementById('pk-area').hidden = false;
        if (!ok) say('No fingerprint or face unlock on this device, but a passkey from another device will still work.', 'idle');
      })
      .catch(() => { divider.hidden = false; document.getElementById('pk-area').hidden = false; });
  } else {
    divider.hidden = false;
    document.getElementById('pk-area').hidden = false;
  }

  const post = async (body) => {
    const res = await fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: new URLSearchParams(body).toString(),
      credentials: 'same-origin',
    });
    return res.json();
  };

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    say('Waiting for your device…', 'busy');
    try {
      const { challenge, rpId } = await post({ op: 'pk_begin' });
      const cred = await navigator.credentials.get({
        publicKey: {
          challenge: b64uToBuf(challenge),
          rpId: rpId,
          timeout: 120000,
          userVerification: 'preferred',
        },
      });
      if (!cred) throw new Error('No passkey was used.');

      const res = await post({
        op: 'pk_finish',
        challenge: challenge,
        payload: JSON.stringify({
          id: cred.id,
          rawId: bufToB64u(new Uint8Array(cred.rawId)),
          type: cred.type,
          response: {
            clientDataJSON: bufToB64u(new Uint8Array(cred.response.clientDataJSON)),
            authenticatorData: bufToB64u(new Uint8Array(cred.response.authenticatorData)),
            signature: bufToB64u(new Uint8Array(cred.response.signature)),
            userHandle: cred.response.userHandle ? bufToB64u(new Uint8Array(cred.response.userHandle)) : '',
          },
        }),
      });

      if (!res.ok) throw new Error(res.error || 'That passkey was not accepted.');
      window.location.href = res.redirect;
    } catch (err) {
      const m = err && err.name === 'NotAllowedError'
        ? 'That was cancelled. Sign in with your password below.'
        : (err && err.message) || 'Something went wrong.';
      say(m, /cancelled/i.test(m) ? 'idle' : 'bad');
      btn.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>
</body></html>
