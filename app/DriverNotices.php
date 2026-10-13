<?php
declare(strict_types=1);

/**
 * The driver's own log, in one place.
 *
 * The Notices page and the unread badge on the mobile header both need to know
 * what a driver has been told. They are built here rather than in the page so
 * the two cannot drift - a badge counting something the page does not list is
 * worse than no badge at all.
 *
 * There is no notifications table for this. The `notifications` table in the
 * schema is an outbound email outbox, and filing a chauffeur's paperwork and
 * payout history into a mail queue would be a misuse of it. Everything below is
 * read from rows that already record a driver's life: the jobs they were given,
 * the money that moved, the paperwork the office checked, and the edits they
 * made themselves.
 *
 * Four kinds, because four different things are true:
 *   assigned - work arrived
 *   done     - it completed, or money landed
 *   action   - something needs the driver to do something
 *   info     - the file changed, either way
 */
final class DriverNotices
{
    public const KINDS = ['assigned', 'done', 'action', 'info'];

    public const LABELS = [
        'assigned' => 'Assigned',
        'done' => 'Done',
        'action' => 'Action needed',
        'info' => 'Update',
    ];

    public const ICONS = [
        'assigned' => 'fa-route',
        'done' => 'fa-check',
        'action' => 'fa-triangle-exclamation',
        'info' => 'fa-circle-info',
    ];

    /**
     * Everything that has happened to this driver, newest first.
     *
     * @return array<int,array{kind:string,title:string,detail:string,when:int,href:string}>
     */
    public static function forDriver(PDO $pdo, int $driverId, int $limit = 150): array
    {
        $events = [];
        $add = static function (string $kind, string $title, string $detail, string $when, string $href = '') use (&$events): void {
            $ts = strtotime($when);
            if ($ts === false) return;
            $events[] = ['kind' => $kind, 'title' => $title, 'detail' => $detail, 'when' => $ts, 'href' => $href];
        };
        $meta = static fn(?string $raw): array => is_array($j = json_decode((string)$raw, true)) ? $j : [];

        // ---- work assigned ------------------------------------------------
        $st = $pdo->prepare('SELECT d.assigned_at, b.booking_number, b.pickup_date, b.pickup_time,
                                   b.pickup_location, b.destination_location, b.guest_name
                            FROM dispatches d JOIN bookings b ON b.id = d.booking_id
                           WHERE d.driver_id = ? ORDER BY d.assigned_at DESC LIMIT 200');
        $st->execute([$driverId]);
        foreach ($st->fetchAll() as $r) {
            $guest = trim((string)($r['guest_name'] ?? ''));
            $add('assigned',
                'Job ' . $r['booking_number'] . ($guest !== '' ? ' for ' . $guest : ''),
                trim((string)$r['pickup_location']) . ' → ' . trim((string)$r['destination_location'])
                    . ' · ' . date('D j M', (int)strtotime((string)$r['pickup_date'])) . ', ' . substr((string)$r['pickup_time'], 0, 5),
                (string)$r['assigned_at'],
                url('driver/rides.php'));
        }

        // ---- money ---------------------------------------------------------
        $st = $pdo->prepare('SELECT amount, status, requested_at, paid_at FROM driver_payouts
                            WHERE driver_id = ? ORDER BY requested_at DESC LIMIT 20');
        $st->execute([$driverId]);
        foreach ($st->fetchAll() as $r) {
            $amt = '$' . money((float)$r['amount']);
            $s = (string)$r['status'];
            $payoutUrl = url('driver/payout.php');
            if ($s === 'paid') {
                $add('done', 'Payout of ' . $amt . ' sent', 'Arrived in your account.', (string)($r['paid_at'] ?: $r['requested_at']), $payoutUrl);
            } elseif ($s === 'rejected') {
                $add('action', 'Payout of ' . $amt . ' was declined', 'The office turned this one down. Ask them why.', (string)$r['requested_at'], $payoutUrl);
            } elseif ($s === 'requested') {
                $add('info', 'You requested ' . $amt, 'Waiting on the office to review it.', (string)$r['requested_at']);
            } else {
                $add('info', 'Payout of ' . $amt . ' approved', 'The office approved it.', (string)$r['requested_at'], $payoutUrl);
            }
        }

        // ---- paperwork on file ----------------------------------------------
        $st = $pdo->prepare('SELECT doc_type, status, created_at FROM driver_documents
                            WHERE driver_id = ? AND file_path IS NOT NULL ORDER BY created_at DESC LIMIT 20');
        $st->execute([$driverId]);
        foreach ($st->fetchAll() as $r) {
            $kind = in_array($r['doc_type'], ['photo', 'selfie'], true) ? 'Picture' : 'Licence photo';
            $s = (string)$r['status'];
            $acct = url('driver/settings.php');
            if ($s === 'rejected') {
                $add('action', $kind . ' was turned down', 'Take it again and send a clearer one.', (string)$r['created_at'], $acct);
            } elseif ($s === 'verified') {
                $add('done', $kind . ' accepted', 'Checked and kept on your file.', (string)$r['created_at']);
            } else {
                $add('info', $kind . ' received', 'Waiting on the office to check it.', (string)$r['created_at']);
            }
        }

        // ---- what the driver and the office changed ------------------------
        $st = $pdo->prepare('SELECT actor_type, action, metadata, created_at FROM audit_logs
                            WHERE entity_type = "driver" AND entity_id = ? ORDER BY created_at DESC LIMIT 200');
        $st->execute([$driverId]);
        $acct = url('driver/settings.php');
        foreach ($st->fetchAll() as $r) {
            $a = (string)$r['action'];
            // The column is `metadata`; reading `data` returns nothing at all and
            // the whole office side of the page renders as a silently empty log.
            $m = $meta($r['metadata'] ?? null);

            if ($a === 'driver.status') {
                $to = (string)($m['to'] ?? '');
                if ($to === 'active') {
                    $add('done', 'The office cleared you to drive', 'You can take rides now.', (string)$r['created_at']);
                } elseif ($to === 'suspended') {
                    $add('action', 'The office suspended your account', 'Speak to the office about it.', (string)$r['created_at']);
                } elseif ($to === 'pending') {
                    $add('action', 'The office sent you back for clearance', 'Check your Account page.', (string)$r['created_at'], $acct);
                } else {
                    $add('info', 'Account marked ' . $to, '', (string)$r['created_at']);
                }
                continue;
            }
            if ($a === 'driver.password_reset') {
                $add('info', 'The office reset your sign-in', 'Your password was set from the desk.', (string)$r['created_at']);
                continue;
            }
            if (in_array($a, ['driver.password_changed', 'driver.email_changed', 'driver.phone_changed', 'driver.record_updated', 'driver.profile_set'], true)) {
                $add('info', match ($a) {
                    'driver.password_changed' => 'You changed your password',
                    'driver.email_changed' => 'You changed your sign-in email',
                    'driver.phone_changed' => 'You changed your mobile number',
                    default => 'You updated your record',
                }, '', (string)$r['created_at'], $acct);
                continue;
            }
            if (in_array($a, ['driver.licence_captured', 'driver.licence_replaced'], true)) {
                $add('info', 'You sent a licence photo', 'The office will check the new one.', (string)$r['created_at']);
                continue;
            }
            if (in_array($a, ['driver.photo_captured', 'driver.picture_replaced'], true)) {
                $add('info', 'You sent your picture', 'The office will check the new one.', (string)$r['created_at']);
                continue;
            }
            if (($r['actor_type'] ?? '') === 'admin' && $a === 'driver.field_edited') {
                $field = str_replace('_', ' ', (string)($m['field'] ?? ''));
                $add('info', 'Office edited your ' . ($field !== '' ? $field : 'record'), '', (string)$r['created_at']);
            }
        }

        usort($events, static fn($a, $b) => $b['when'] <=> $a['when']);
        return array_slice($events, 0, $limit);
    }

    /**
     * How many of those the driver has not looked at yet, and how many of them
     * need doing something.
     *
     * Counted from the same list the page renders, so opening the page always
     * empties the badge - there is nothing left in one that is not in the other.
     * The split matters: a driver who has four unread updates and one rejected
     * photo is looking at a different thing than one with four updates and
     * nothing wrong, and a badge that does not say which is not telling them
     * whether to open it.
     *
     * @return array{total:int,action:int}
     */
    public static function unseenBreakdown(PDO $pdo, int $driverId): array
    {
        try {
            $st = $pdo->prepare('SELECT notification_seen_at FROM drivers WHERE id = ? LIMIT 1');
            $st->execute([$driverId]);
            $seenAt = $st->fetchColumn();
        } catch (Throwable) {
            return ['total' => 0, 'action' => 0];
        }
        // Never opened it: everything is unread, but a badge of sixty on the first
        // login is noise, not news. Anything from the last fortnight counts.
        $since = $seenAt ? strtotime((string)$seenAt) : (time() - 14 * 86400);
        $total = 0;
        $action = 0;
        foreach (self::forDriver($pdo, $driverId) as $e) {
            if ($e['when'] <= $since) continue;
            $total++;
            if ($e['kind'] === 'action') $action++;
        }
        return ['total' => $total, 'action' => $action];
    }

    public static function unseen(PDO $pdo, int $driverId): int
    {
        return self::unseenBreakdown($pdo, $driverId)['total'];
    }

    /** Everything has now been seen. Called when the driver opens the page. */
    public static function markSeen(PDO $pdo, int $driverId): void
    {
        try {
            $pdo->prepare('UPDATE drivers SET notification_seen_at = NOW() WHERE id = ?')->execute([$driverId]);
        } catch (Throwable) {
            // A badge that cannot be cleared is better than a page that will not load.
        }
    }

    /** 99+ past three figures, the way a badge is expected to behave. */
    public static function badgeLabel(int $n): string
    {
        return $n > 99 ? '99+' : (string)$n;
    }
}