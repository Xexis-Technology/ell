<?php
declare(strict_types=1);

/** Waiting time rules + pending-invoice collection (last.md §17). Never auto-charges Stripe. */
final class WaitingService
{
    public static function ruleFor(PDO $pdo, string $category): array
    {
        $st = $pdo->prepare('SELECT * FROM waiting_rules WHERE category = ? AND active = 1 LIMIT 1');
        $st->execute([$category]);
        $r = $st->fetch();
        return $r ?: ['category' => $category, 'free_minutes' => 15, 'charge_interval_minutes' => 10, 'charge_per_interval' => 15.00];
    }

    public static function calculateCharge(PDO $pdo, string $category, int $waitedMinutes): array
    {
        $rule = self::ruleFor($pdo, $category);
        $free = (int)$rule['free_minutes'];
        $billable = max(0, $waitedMinutes - $free);
        $interval = max(1, (int)$rule['charge_interval_minutes']);
        $per = (float)$rule['charge_per_interval'];
        $units = (int)ceil($billable / $interval);
        return ['free' => $free, 'billable' => $billable, 'units' => $units, 'charge' => round($units * $per, 2), 'rate' => $per];
    }

    public static function closeSession(PDO $pdo, int $bookingId, string $category, int $waitedMinutes, int $adminId): array
    {
        $calc = self::calculateCharge($pdo, $category, $waitedMinutes);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO waiting_sessions (booking_id, category, started_at, ended_at, free_minutes, billable_minutes, rate, charge, status) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$bookingId, $category, date('Y-m-d H:i:s', time() - $waitedMinutes * 60), date('Y-m-d H:i:s'), $calc['free'], $calc['billable'], $calc['rate'], $calc['charge'], 'closed']);
            $sid = (int)$pdo->lastInsertId();
            if ($calc['charge'] > 0) {
                $pdo->prepare('INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, total, source) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$bookingId, 'waiting_time', 'Waiting charge (' . $category . ')', $calc['units'], $calc['rate'], $calc['charge'], 'waiting']);
                $pdo->prepare('UPDATE bookings SET subtotal = subtotal + ?, total = total + ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$calc['charge'], $calc['charge'], $bookingId]);
            }
            $pdo->commit();
            audit($pdo, 'admin', $adminId, 'waiting.closed', 'booking', $bookingId, $calc);
            return ['ok' => true, 'session_id' => $sid, 'charge' => $calc['charge']];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['error' => 'Could not close waiting session.'];
        }
    }
}
