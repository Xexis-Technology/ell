<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

// Profile editing and document upload now live in one place: setup.php handles
// the three ordered steps, settings.php handles changes afterwards.
//
// This page used to be a separate form that accepted a licence upload without
// asking for the licence number or expiry - which quietly undid the rule that a
// driver is cleared only when both the number and the document are on file. Any
// old bookmark now lands on the account page instead.
require_role('driver');
redirect('driver/settings.php');