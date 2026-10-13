<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

// Public driver sign-up is retired.
//
// The owner's model is that the office creates the driver and the driver then
// completes three steps - password, profile, licence - before the account can
// sign in. An open registration form contradicts that: it lets anyone mint a
// driver account with no office involvement, and it was the one route into the
// portal that bypassed the licence requirement entirely.
//
// Reaching the login page instead of a form explains why. The old form's code
// is preserved in git history if it is ever needed.
redirect('driver/index.php');