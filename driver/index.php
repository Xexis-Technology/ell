<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::pdo();
if (current_user('driver')) redirect(DriverPortal::landing($pdo, current_user('driver')));

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    $key = 'driver:' . ($_SERVER['REMOTE_ADDR'] ?? 'x');

    if (!rate_limit_check($key)) {
        $error = 'Too many attempts. Try again later.';
    } else {
        $st = $pdo->prepare('SELECT * FROM drivers WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();

        if ($u && trim((string)($u['password_hash'] ?? '')) !== '' && password_verify($pw, (string)$u['password_hash'])) {
            rate_limit_clear($key);

            // The owner's rule: a driver may only sign in if their driving
            // licence is on file. So the password is checked first, then
            // clearance - and the message says exactly what is missing rather
            // than pretending the credentials were wrong.
            $clear = DriverPortal::clearance($pdo, (array)$u);

            if ($clear['cleared']) {
                login_user('driver', $u);
                audit($pdo, 'driver', (int)$u['id'], 'auth.login', 'driver', (int)$u['id'], ['via' => 'password']);
                redirect('driver/dashboard.php');
            }

            if ((string)$u['status'] === 'inactive' || (string)$u['status'] === 'suspended') {
                $error = $clear['reason'];
            } else {
                // Password is right but they are not cleared. Let them in with
                // a limited session so they can finish setup - refusing
                // outright would leave no way to upload the licence at all.
                login_user('driver', $u);
                audit($pdo, 'driver', (int)$u['id'], 'auth.login.blocked', 'driver', (int)$u['id'], ['reason' => $clear['reason']]);
                // landing() is the single decision about where a driver belongs:
                // dashboard once cleared, otherwise the wizard - or the
                // clearance screen if their paperwork is already on file.
                redirect(DriverPortal::landing($pdo, (array)$u));
            }
        } else {
            rate_limit_hit($key);
            $error = 'That email and password do not match an account.';
        }
    }
}
ob_start();
?>
<div class="auth-shell">

  <!--
    The left panel is not a stock hero image. It is the beam a chauffeur needs
    before they are cleared: a headlight thrown across wet road at night, built
    from halftone dots. Same dots the reference uses, but pointed at the one
    thing this portal is actually about.
  -->
  <section class="auth-stage">
    <div class="beam" aria-hidden="true"></div>

    <div class="auth-stage-in">
      <p class="auth-mark">
        <span class="drv-mark-a" aria-hidden="true">E</span>
        <span class="auth-mark-t">Exotic Lane<em>Limo</em></span>
      </p>

      <div class="auth-stage-foot">
        <p class="drv-k">Driver access</p>
        <h1>The road starts with your licence.</h1>
        <p class="auth-stage-sub">You are given a ride once your profile, your driving licence and your picture are all on file.</p>
        <ol class="auth-steps">
          <li>Profile</li>
          <li>Licence</li>
          <li>Picture</li>
        </ol>
      </div>
    </div>
  </section>

  <section class="auth-panel">
    <div class="auth-panel-in">
      <header class="auth-panel-head">
        <h2>Sign in</h2>
        <p>Use the email and password the office gave you.</p>
      </header>

      <?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>

      <form method="post">
        <?= csrf_field() ?>
        <div class="drv-field">
          <label class="drv-label" for="email">Email</label>
          <input id="email" type="email" name="email" class="drv-input" required autocomplete="username"
                 inputmode="email" placeholder="you@example.com">
        </div>
        <div class="drv-field">
          <div class="drv-label-row">
            <label class="drv-label" for="password">Password</label>
            <a href="<?= url('driver/forgot-password.php') ?>">Forgot it?</a>
          </div>
          <input id="password" type="password" name="password" class="drv-input" required
                 autocomplete="current-password" placeholder="Your password">
        </div>
        <button class="drv-btn drv-btn-gold">Sign in</button>
      </form>

      <p class="auth-help">First time here? Sign in with the details from the office and we will take you through the rest.</p>
    </div>
  </section>

</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Driver sign in | Exotic Lane Limo';
require APP_ROOT . '/views/layouts/driver.php';