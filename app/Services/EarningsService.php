<?php
declare(strict_types=1);

/** Driver earnings 20/80 + payout eligibility every 7 days (last.md §23-24). */
final class EarningsService
{
    public const COMPANY_PCT = 20;
    public const DRIVER_PCT = 80;

    public static function createForBooking(PDO $pdo, int $bookingId, int $driverId): array
    {
        $st = $pdo->prepare('SELECT total FROM bookings WHERE id = ? LIMIT 1');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b) return ['error' => 'Booking not found.'];
        $gross = round((float)$b['total'], 2);
        $company = round($gross * self::COMPANY_PCT / 100, 2);
        $driver = round($gross - $company, 2);
        try {
            $st = $pdo->prepare('INSERT INTO driver_earnings (booking_id, driver_id, gross_amount, company_amount, driver_amount, status) VALUES (?,?,?,?,?,?)');
            $st->execute([$bookingId, $driverId, $gross, $company, $driver, 'unpaid']);
            audit($pdo, 'system', null, 'earnings.created', 'booking', $bookingId, ['gross' => $gross, 'driver' => $driver]);
            return ['ok' => true, 'driver_amount' => $driver];
        } catch (Throwable $ex) {
            if (str_contains($ex->getMessage(), 'uq_de_booking') || $ex->getCode() === '23000') {
                return ['ok' => true, 'duplicate' => true]; // retry-safe
            }
            log_error('earnings failed: ' . $ex->getMessage());
            return ['error' => 'Could not create earning record.'];
        }
    }

    public static function unpaidBalance(PDO $pdo, int $driverId): float
    {
        $st = $pdo->prepare('SELECT COALESCE(SUM(driver_amount),0) AS s FROM driver_earnings WHERE driver_id = ? AND status = "unpaid"');
        $st->execute([$driverId]);
        return round((float)($st->fetch()['s'] ?? 0), 2);
    }

    public static function payoutEligible(PDO $pdo, int $driverId): array
    {
        $days = (int)(setting($pdo, 'payout_interval_days', '7'));
        $st = $pdo->prepare('SELECT requested_at FROM driver_payouts WHERE driver_id = ? AND status IN ("requested","approved","paid") ORDER BY requested_at DESC LIMIT 1');
        $st->execute([$driverId]);
        $last = $st->fetch();
        if ($last && strtotime($last['requested_at']) > time() - $days * 86400) {
            return ['eligible' => false, 'reason' => 'Withdrawals are allowed once every ' . $days . ' days.'];
        }
        $balance = self::unpaidBalance($pdo, $driverId);
        if ($balance <= 0) return ['eligible' => false, 'reason' => 'No unpaid earnings available.'];
        return ['eligible' => true, 'balance' => $balance];
    }
}
