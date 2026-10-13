<?php
declare(strict_types=1);

/**
 * Whether a driver is cleared to take a ride, and what is still missing.
 *
 * The rule the owner set: a driver may only sign in if their driving licence is
 * on file. That makes "am I cleared?" the most important fact in the portal and
 * the one that was most invisible - nothing in the driver UI previously said
 * it, so a driver who had uploaded nothing and a driver who was fully cleared
 * saw the same screen.
 *
 * Every piece of this is derived from rows that already exist rather than kept
 * in a separate progress table. A setup checklist stored separately drifts from
 * the thing it describes; this cannot, because it is computed from the same
 * fields the rest of the system reads.
 */
final class DriverPortal
{
    /**
     * The three steps a driver completes after their first sign-in.
     *
     * The password is NOT one of them. The office sets the first password when
     * the driver is added to the crew, so the driver can sign in and then change
     * it to their own from Account. These three are what a driver must put on
     * file before they are allowed to take a ride.
     */
public const STEPS = [
    1 => ['key' => 'profile', 'title' => 'Set your profile', 'blurb' => 'Your name, a mobile number for urgent calls, the email your rides are sent to, and your date of birth.'],
    2 => ['key' => 'licence', 'title' => 'Your licence and clearance dates', 'blurb' => 'Photograph your driving licence, then give the dates the office has to hold on file.'],
    3 => ['key' => 'picture', 'title' => 'Take your picture', 'blurb' => 'A photo of your face with the front camera, for the guest manifest.'],
];

    /**
     * Where each step currently stands for this driver.
     *
     * @return array<int,array{key:string,title:string,blurb:string,done:bool,current:string,missing:string}>
     */
    public static function steps(PDO $pdo, array $driver): array
    {
        $id = (int)$driver['id'];
        $fresh = self::fresh($pdo, $id);

        $done = [
            'profile' => trim((string)($fresh['phone'] ?? '')) !== ''
                && trim((string)($fresh['name'] ?? '')) !== ''
                && trim((string)($fresh['email'] ?? '')) !== ''
                && trim((string)($fresh['date_of_birth'] ?? '')) !== '',
            'licence' => self::has_licence_upload($pdo, $id)
                && trim((string)($fresh['license_number'] ?? '')) !== ''
                && trim((string)($fresh['license_expiry'] ?? '')) !== ''
                && trim((string)($fresh['medical_cert_expiry'] ?? '')) !== ''
                && trim((string)($fresh['background_check_date'] ?? '')) !== ''
                && trim((string)($fresh['drug_test_date'] ?? '')) !== '',
            'picture' => self::has_picture_upload($pdo, $id),
        ];

        // "current" is the first step not finished - the one the driver is on.
        $current = null;
        foreach (array_keys(self::STEPS) as $n) {
            if (!$done[self::STEPS[$n]['key']]) { $current = $n; break; }
        }

        $missing = [
            'profile' => self::profile_missing_note($fresh),
            'licence' => self::licence_missing_note($fresh, self::has_licence_upload($pdo, $id)),
            'picture' => 'Take your picture with the front camera.',
        ];

        $out = [];
        foreach (self::STEPS as $n => $s) {
            $out[$n] = [
                'key' => $s['key'],
                'title' => $s['title'],
                'blurb' => $s['blurb'],
                'done' => $done[$s['key']],
                'missing' => $missing[$s['key']],
            ] + ($current !== null ? ['current' => ($n === $current)] : []);
        }
        // Every step complete: no step is current.
        if ($current === null) {
            foreach ($out as &$s) $s['current'] = false;
            unset($s);
        }
        return $out;
    }

    /** The driver row as the database holds it, not as the session remembers it. */
    public static function fresh(PDO $pdo, int $driverId): array
    {
        $st = $pdo->prepare('SELECT * FROM drivers WHERE id = ? LIMIT 1');
        $st->execute([$driverId]);
        return (array)$st->fetch();
    }

    /**
     * Has this driver uploaded a given kind of document?
     *
     * One helper for both the licence and the picture rather than two near-copies.
     * 'licence' is accepted alongside 'license' so a record written by either
     * spelling still counts.
     */
    public static function has_upload(PDO $pdo, int $driverId, string $kind): bool
    {
        $types = $kind === 'licence' ? ['license', 'licence'] : [$kind];
        try {
            $in = implode(',', array_fill(0, count($types), '?'));
            $st = $pdo->prepare("SELECT COUNT(*) FROM driver_documents
                                 WHERE driver_id = ? AND doc_type IN ($in)
                                   AND file_path IS NOT NULL AND file_path <> ''");
            $st->execute(array_merge([$driverId], $types));
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function has_licence_upload(PDO $pdo, int $driverId): bool
    {
        return self::has_upload($pdo, $driverId, 'licence');
    }

    public static function has_picture_upload(PDO $pdo, int $driverId): bool
    {
        return self::has_upload($pdo, $driverId, 'photo');
    }

    /** Licence expiry, as days remaining. Null when no date is on file. */
    public static function licence_days(PDO $pdo, int $driverId): ?int
    {
        $d = self::fresh($pdo, $driverId);
        $exp = trim((string)($d['license_expiry'] ?? ''));
        if ($exp === '') return null;
        $ts = strtotime($exp);
        if ($ts === false) return null;
        return (int)floor(($ts - strtotime('today')) / 86400);
    }

    /**
     * Can this driver sign in and work?
     *
     * Once the office marks a chauffeur cleared, that decision stands. The desk
     * has looked at the file and made the call; re-litigating it because one
     * photograph is missing would send a driver the office trusts back to a
     * wizard they already finished, and would make "Clear to drive" not mean
     * anything.
     *
     * Before that - while the driver is still filling the form in - the gate is
     * what stops an uncleared chauffeur being handed work.
     *
     * @return array{cleared:bool,reason:string,tone:string}
     */
    public static function clearance(PDO $pdo, array $driver): array
    {
        $id = (int)$driver['id'];
        $d = self::fresh($pdo, $id);

        $status = (string)($d['status'] ?? '');
        if ($status === 'suspended') {
            return ['cleared' => false, 'tone' => 'bad', 'reason' => 'Your account is suspended. Speak to the office.'];
        }
        if ($status !== 'active') {
            return ['cleared' => false, 'tone' => 'wait', 'reason' => 'The office has not cleared you to drive yet.'];
        }

        // Cleared by the office. Advisory only from here on.
        $days = self::days_from((string)($d['license_expiry'] ?? ''));
        $medDays = self::days_from((string)($d['medical_cert_expiry'] ?? ''));
        if ($days !== null && $days < 0) {
            return ['cleared' => true, 'tone' => 'warn',
                'reason' => 'Your licence expired on ' . date('j M Y', (int)strtotime((string)$d['license_expiry'])) . ' — tell the office.'];
        }
        if ($medDays !== null && $medDays < 0) {
            return ['cleared' => true, 'tone' => 'warn',
                'reason' => 'Your medical certificate expired on ' . date('j M Y', (int)strtotime((string)$d['medical_cert_expiry'])) . ' — tell the office.'];
        }
        if ($days !== null && $days <= 30) {
            return ['cleared' => true, 'tone' => 'warn', 'reason' => $days === 0
                ? 'Your licence expires today.'
                : 'Your licence expires in ' . $days . ' ' . ($days === 1 ? 'day' : 'days') . '.'];
        }
        if ($medDays !== null && $medDays <= 45) {
            return ['cleared' => true, 'tone' => 'warn',
                'reason' => 'Your medical certificate expires in ' . $medDays . ' ' . ($medDays === 1 ? 'day' : 'days') . '.'];
        }

        // Nothing dated on file to count down to, or no licence at all: the
        // office cleared them regardless, so say so plainly rather than printing
        // an empty number of days.
        if ($days === null || (trim((string)($d['license_number'] ?? '')) === '' && !self::has_licence_upload($pdo, $id))) {
            return ['cleared' => true, 'tone' => 'ok', 'reason' => 'Cleared to drive.'];
        }
        return ['cleared' => true, 'tone' => 'ok', 'reason' => 'Cleared to drive · licence valid for another ' . $days . ' days.'];
    }

    /** Days from today to a Y-m-d date, or null when it is empty or unreadable. */
    private static function days_from(?string $date): ?int
    {
        $s = trim((string)$date);
        if ($s === '' || !strtotime($s)) return null;
        return (int)floor((strtotime($s) - strtotime('today')) / 86400);
    }

    private static function licence_missing_note(array $fresh, bool $hasDoc): string
    {
        if (!$hasDoc) return 'Photograph your driving licence with the camera.';
        if (trim((string)($fresh['license_number'] ?? '')) === '') return 'Add your licence number.';
        if (trim((string)($fresh['license_expiry'] ?? '')) === '') return 'Add the expiry date on your licence.';
        if (trim((string)($fresh['medical_cert_expiry'] ?? '')) === '') return 'Add your medical certificate expiry date.';
        if (trim((string)($fresh['background_check_date'] ?? '')) === '') return 'Add the date of your background check.';
        return 'Add the date of your drug test.';
    }

    /** The first missing piece of the profile, named so the driver knows what to do. */
    private static function profile_missing_note(array $fresh): string
    {
        if (trim((string)($fresh['name'] ?? '')) === '') return 'Dispatch has no name for you.';
        if (trim((string)($fresh['phone'] ?? '')) === '') return 'Add a mobile number for urgent calls.';
        if (trim((string)($fresh['email'] ?? '')) === '') return 'Add the email your rides are sent to.';
        return 'Add your date of birth.';
    }

    /**
     * Gate a driver page.
     *
     * A driver who is not cleared is sent to setup and no further. The setup
     * page itself must not call this, or clearing would be impossible.
     */
    public static function require_cleared(PDO $pdo, array $driver): void
    {
        $c = self::clearance($pdo, $driver);
        if (!$c['cleared']) redirect('driver/setup.php');
    }

    /**
 * Has this driver finished and sent their paperwork?
 *
 * Submission is recorded once, as an event. Deriving it from the fields instead
 * meant that anything which did not read back exactly - a document row that went
 * missing, a date stored in a different shape - dropped the driver back into a
 * wizard they had already completed, on whichever step looked least finished.
 * A flag cannot drift, so once it is set the wizard stays shut.
 */
    public static function submitted(PDO $pdo, array $driver): bool
    {
        $d = self::fresh($pdo, (int)$driver['id']);
        if (trim((string)($d['clearance_submitted_at'] ?? '')) !== '') return true;
        // Fallback for anything finished before this column existed.
        return count(array_filter(self::steps($pdo, $d), static fn($s) => $s['done'])) === 3;
    }

    /** Stamp the submission. Safe to call repeatedly - the first one wins. */
    public static function mark_submitted(PDO $pdo, int $driverId): void
    {
        $pdo->prepare('UPDATE drivers SET clearance_submitted_at = COALESCE(clearance_submitted_at, NOW()) WHERE id = ?')
            ->execute([$driverId]);
    }

    /**
     * Where a driver lands after signing in.
     *
     * Once their paperwork is on file they are held on the clearance screen, not
     * dropped back into the wizard, until the office clears them.
     */
    public static function landing(PDO $pdo, array $driver): string
    {
        if (self::clearance($pdo, $driver)['cleared']) return 'driver/dashboard.php';
        if (self::submitted($pdo, $driver)) return 'driver/setup.php';
        return 'driver/setup.php?s=' . self::setup_token($pdo, $driver);
    }

    /**
     * Opaque handle for a setup step, used in the URL instead of ?step=2.
     *
     * A bare ordinal in the address bar invites ?step=3 straight past the first
     * two, and it advertises the flow to anyone who looks. These are random per
     * session instead: a driver's handles cannot be guessed, and cannot be
     * replayed by another driver from a shared link. There is no secret to
     * configure and nothing to leak, because the map only ever lives in the
     * driver's own session.
     */
    public static function step_token(int $step): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['drv_step_tokens'][$step])) {
            $_SESSION['drv_step_tokens'][$step] = bin2hex(random_bytes(8));
        }
        return (string)$_SESSION['drv_step_tokens'][$step];
    }

    /** Which step a handle refers to, or null when it is not one of ours. */
    public static function step_from_token(?string $token): ?int
    {
        $t = trim((string)$token);
        if ($t === '' || session_status() !== PHP_SESSION_ACTIVE) return null;
        $map = $_SESSION['drv_step_tokens'] ?? [];
        if (!is_array($map)) return null;
        foreach ($map as $step => $value) {
            if (hash_equals((string)$value, $t)) return (int)$step;
        }
        return null;
    }

    /**
     * Handle for wherever this driver should pick up: the first step they have
     * not finished, or the last one when everything is done. Used on landing so
     * that no setup URL is ever a bare path or an ordinal.
     */
    public static function setup_token(PDO $pdo, array $driver): string
    {
        $target = 3;
        foreach (self::steps($pdo, $driver) as $n => $s) {
            if ($s['current']) { $target = $n; break; }
        }
        return self::step_token($target);
    }

    /** Drivers whose licence has run out or is about to. Used by the admin desk. */
    public static function expiring(PDO $pdo, int $withinDays = 45): array
    {
        $st = $pdo->prepare(
            "SELECT id, name, email, license_number, license_expiry,
                    DATEDIFF(license_expiry, CURDATE()) AS days_left
               FROM drivers
              WHERE license_expiry IS NOT NULL AND license_expiry <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
              ORDER BY license_expiry ASC LIMIT 50"
        );
        $st->execute([$withinDays]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}