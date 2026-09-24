<?php
declare(strict_types=1);

/**
 * Booking engine (last.md §11-13, §96, §98, §103).
 * Single transactional creation with server-side pricing snapshot.
 */
final class BookingService
{
    public const VALID_STATUSES = ['pending_payment','payment_failed','awaiting_pricing','pricing_finalized','booking_received','confirmed','assigned','on_the_way','arrived','at_pickup_location','on_board','finish','cancelled','refunded'];

    /** Create booking + stops + charges snapshot atomically. */
    public static function create(PDO $pdo, array $input, ?int $customerId): array
    {
        // Basic validation
        $errors = [];
        foreach (['service_type','pickup_location','destination_location','pickup_date','pickup_time','vehicle_id'] as $f) {
            if (empty($input[$f])) $errors[$f] = 'This field is required.';
        }
        if (!in_array($input['trip_type'] ?? 'one_way', ['one_way','round_trip'], true)) {
            $errors['trip_type'] = 'Invalid trip type.';
        }
        // Date/time + lead time
        if (!isset($errors['pickup_date']) && !isset($errors['pickup_time'])) {
            $dt = strtotime(($input['pickup_date'] ?? '') . ' ' . ($input['pickup_time'] ?? ''));
            if (!$dt || $dt < time()) $errors['pickup_date'] = 'Pickup must be in the future.';
            else {
                $leadH = (float)(setting($pdo, 'lead_time_hours', '2'));
                if ($dt < time() + $leadH * 3600) $errors['pickup_date'] = 'Minimum lead time is ' . $leadH . ' hours.';
            }
        }
        $stops = array_values(array_filter(array_map('trim', (array)($input['stops'] ?? [])), fn($s) => $s !== ''));
        if (count($stops) > 6) $errors['stops'] = 'Maximum 6 stops allowed.';
        if ($errors) return ['errors' => $errors];

        $mapsCfg = require APP_ROOT . '/config/maps.php';
        // Maps enabled can also be overridden by DB setting
        $mapsEnabled = $mapsCfg['enabled'] || setting($pdo, 'maps_enabled', '0') === '1';

        $needsPricing = !$mapsEnabled && empty($input['mileage']) && PricingService::activeMode($pdo) === 'per_mile';

        $calc = null;
        if (!$needsPricing) {
            $calc = PricingService::calculate($pdo, $input + ['stops' => $stops, 'customer_id' => $customerId, 'guest_email' => $input['guest_email'] ?? null]);
            if (isset($calc['errors'])) return ['errors' => $calc['errors']];
        }

        try {
            $pdo->beginTransaction();
            $bookingNumber = generate_booking_number($pdo);
            $pickupDt = ($input['pickup_date'] ?? '') . ' ' . ($input['pickup_time'] ?? '');

            $status = $needsPricing ? 'awaiting_pricing' : 'pending_payment';
            $st = $pdo->prepare('INSERT INTO bookings (booking_number, customer_id, guest_name, guest_email, guest_phone, service_type, trip_type, airport_direction, pickup_location, destination_location, pickup_date, pickup_time, original_pickup_datetime, passengers, luggage, vehicle_id, mileage, hours, status, pricing_status, payment_status, subtotal, discount, tax, total, currency, coupon_id, addons_json, pricing_finalized_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([
                $bookingNumber,
                $customerId,
                $input['guest_name'] ?? null,
                $input['guest_email'] ?? null,
                $input['guest_phone'] ?? null,
                $input['service_type'],
                $input['trip_type'] ?? 'one_way',
                $input['airport_direction'] ?? null,
                $input['pickup_location'],
                $input['destination_location'],
                $input['pickup_date'],
                $input['pickup_time'],
                date('Y-m-d H:i:s', strtotime($pickupDt)),
                (int)($input['passengers'] ?? 1),
                (int)($input['luggage'] ?? 0),
                (int)$input['vehicle_id'],
                isset($input['mileage']) && $input['mileage'] !== '' ? (float)$input['mileage'] : null,
                isset($input['hours']) && $input['hours'] !== '' ? (float)$input['hours'] : null,
                $status,
                $calc ? 'finalized' : 'awaiting',
                'pending',
                $calc['subtotal'] ?? 0,
                $calc['discount'] ?? 0,
                $calc['tax'] ?? 0,
                $calc['total'] ?? 0,
                setting($pdo, 'currency', 'USD'),
                $calc['coupon_id'] ?? null,
                json_encode($input['addons'] ?? []),
                $calc ? date('Y-m-d H:i:s') : null,
            ]);
            $bookingId = (int)$pdo->lastInsertId();

            $si = $pdo->prepare('INSERT INTO booking_stops (booking_id, stop_order, location) VALUES (?,?,?)');
            foreach ($stops as $i => $s) {
                $si->execute([$bookingId, $i + 1, $s]);
            }

            if ($calc) {
                $ci = $pdo->prepare('INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, total, source) VALUES (?,?,?,?,?,?,?)');
                foreach ($calc['lines'] as $l) {
                    $ci->execute([$bookingId, $l['charge_type'], $l['description'], $l['quantity'], $l['unit_price'], $l['total'], $l['source']]);
                }
                if (!empty($calc['coupon_id'])) PricingService::consumeCoupon($pdo, (int)$calc['coupon_id'], $bookingId, $customerId, $input['guest_email'] ?? null);
            }

            $pdo->prepare('INSERT INTO booking_status_logs (booking_id, old_status, new_status, actor_type, actor_id, note) VALUES (?,?,?,?,?,?)')
                ->execute([$bookingId, null, $status, $customerId ? 'customer' : 'system', $customerId, 'Booking created']);

            $pdo->commit();
            $actorType = $customerId ? 'customer' : 'system';
            audit($pdo, $actorType, $customerId, 'booking.created', 'booking', $bookingId, ['booking_number' => $bookingNumber]);
            return ['booking_id' => $bookingId, 'booking_number' => $bookingNumber, 'status' => $status, 'total' => $calc['total'] ?? 0];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Duplicate booking number race -> caller may retry
            if (str_contains($ex->getMessage(), 'uq_bookings_number') || $ex->getCode() === '23000') {
                log_error('booking number race, retry advised');
            }
            log_error('BookingService::create failed: ' . $ex->getMessage());
            return ['errors' => ['_general' => 'Could not create booking. Please try again.']];
        }
    }

    public static function setStatus(PDO $pdo, int $bookingId, string $newStatus, string $actorType, ?int $actorId, ?string $note = null): bool
    {
        if (!in_array($newStatus, self::VALID_STATUSES, true)) return false;
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT status FROM bookings WHERE id = ? FOR UPDATE');
            $st->execute([$bookingId]);
            $row = $st->fetch();
            if (!$row) {
                $pdo->rollBack();
                return false;
            }
            $old = $row['status'];
            $pdo->prepare('UPDATE bookings SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatus, $bookingId]);
            $pdo->prepare('INSERT INTO booking_status_logs (booking_id, old_status, new_status, actor_type, actor_id, note) VALUES (?,?,?,?,?,?)')
                ->execute([$bookingId, $old, $newStatus, $actorType, $actorId, $note]);
            $pdo->commit();
            audit($pdo, $actorType, $actorId, 'booking.status', 'booking', $bookingId, ['from' => $old, 'to' => $newStatus]);
            return true;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_error('setStatus failed: ' . $ex->getMessage());
            return false;
        }
    }

    /** Customer pickup-time update before cutoff (default 2h). */
    public static function updatePickupTime(PDO $pdo, int $bookingId, string $newDate, string $newTime, string $actorType, ?int $actorId): array
    {
        $cutoffH = (float)(setting($pdo, 'pickup_cutoff_hours', '2'));
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b) return ['error' => 'Booking not found.'];
        if (in_array($b['status'], ['finish','cancelled','refunded'], true)) return ['error' => 'Booking can no longer be changed.'];
        $currentPickup = strtotime($b['pickup_date'] . ' ' . $b['pickup_time']);
        if ($currentPickup - time() < $cutoffH * 3600) return ['error' => 'Changes are closed within ' . $cutoffH . ' hours of pickup.'];
        $newTs = strtotime($newDate . ' ' . $newTime);
        if (!$newTs || $newTs < time()) return ['error' => 'New pickup must be in the future.'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE bookings SET pickup_date = ?, pickup_time = ?, updated_at = NOW() WHERE id = ?')->execute([$newDate, $newTime, $bookingId]);
            $pdo->prepare('INSERT INTO booking_time_changes (booking_id, old_pickup_datetime, new_pickup_datetime, changed_by_type, changed_by_id) VALUES (?,?,?,?,?)')
                ->execute([$bookingId, date('Y-m-d H:i:s', $currentPickup), date('Y-m-d H:i:s', $newTs), $actorType, $actorId]);
            $pdo->commit();
            audit($pdo, $actorType, $actorId, 'booking.pickup_time', 'booking', $bookingId, ['new' => $newDate . ' ' . $newTime]);
            return ['ok' => true];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['error' => 'Could not update pickup time.'];
        }
    }

    /** Admin finalize price for awaiting_pricing bookings (audited revision). */
    public static function finalizePricing(PDO $pdo, int $bookingId, array $input, int $adminId): array
    {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b) return ['error' => 'Booking not found.'];
        $calc = PricingService::calculate($pdo, [
            'service_type' => $b['service_type'],
            'vehicle_id' => $input['vehicle_id'] ?? $b['vehicle_id'],
            'passengers' => $b['passengers'],
            'luggage' => $b['luggage'],
            'stops' => [], // stops already stored; extra-stop lines preserved below
            'mileage' => $input['mileage'] ?? $b['mileage'],
            'hours' => $input['hours'] ?? $b['hours'],
            'addons' => json_decode((string)($b['addons_json'] ?? '[]'), true) ?: [],
            'manual_charges' => $input['manual_charges'] ?? [],
            'coupon_code' => $input['coupon_code'] ?? '',
            'customer_id' => $b['customer_id'] ? (int)$b['customer_id'] : null,
            'guest_email' => $b['guest_email'],
        ]);
        if (isset($calc['errors'])) return ['error' => implode(' ', $calc['errors'])];
        try {
            $pdo->beginTransaction();
            $oldTotal = (float)$b['total'];
            $pdo->prepare('UPDATE bookings SET vehicle_id = ?, mileage = ?, hours = ?, subtotal = ?, discount = ?, tax = ?, total = ?, coupon_id = ?, status = "pricing_finalized", pricing_status = "finalized", pricing_finalized_at = NOW(), updated_at = NOW() WHERE id = ?')
                ->execute([$input['vehicle_id'] ?? $b['vehicle_id'], $input['mileage'] ?? $b['mileage'], $input['hours'] ?? $b['hours'], $calc['subtotal'], $calc['discount'], $calc['tax'], $calc['total'], $calc['coupon_id'], $bookingId]);
            $pdo->prepare('DELETE FROM booking_charges WHERE booking_id = ? AND source = "pricing_engine"')->execute([$bookingId]);
            $ci = $pdo->prepare('INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, total, source) VALUES (?,?,?,?,?,?,?)');
            foreach ($calc['lines'] as $l) {
                $ci->execute([$bookingId, $l['charge_type'], $l['description'], $l['quantity'], $l['unit_price'], $l['total'], $l['source']]);
            }
            if (!empty($calc['coupon_id'])) PricingService::consumeCoupon($pdo, (int)$calc['coupon_id'], $bookingId, $b['customer_id'] ? (int)$b['customer_id'] : null, $b['guest_email']);
            $pdo->prepare('INSERT INTO pricing_revisions (booking_id, admin_id, old_total, new_total, reason) VALUES (?,?,?,?,?)')
                ->execute([$bookingId, $adminId, $oldTotal, $calc['total'], substr((string)($input['reason'] ?? 'finalize'), 0, 255)]);
            $pdo->prepare('INSERT INTO booking_status_logs (booking_id, old_status, new_status, actor_type, actor_id, note) VALUES (?,?,?,?,?,?)')
                ->execute([$bookingId, $b['status'], 'pricing_finalized', 'admin', $adminId, 'Price finalized']);
            $pdo->commit();
            audit($pdo, 'admin', $adminId, 'booking.pricing_finalized', 'booking', $bookingId, ['total' => $calc['total']]);
            return ['ok' => true, 'total' => $calc['total']];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_error('finalizePricing failed: ' . $ex->getMessage());
            return ['error' => 'Could not finalize pricing.'];
        }
    }
}
