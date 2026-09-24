<?php
declare(strict_types=1);

/** Dispatch: assign/reassign driver+vehicle with conflict checks + history (last.md §21, §99). */
final class DispatchService
{
    public static function vehicleAvailable(PDO $pdo, int $vehicleId, string $date, string $time, ?int $excludeBookingId = null): bool
    {
        $st = $pdo->prepare('SELECT status FROM vehicles WHERE id = ? LIMIT 1');
        $st->execute([$vehicleId]);
        $v = $st->fetch();
        if (!$v || $v['status'] !== 'active') return false;
        // Overlapping bookings on same date (2h buffer window check)
        $sql = 'SELECT 1 FROM bookings b JOIN dispatches d ON d.booking_id = b.id WHERE d.vehicle_id = ? AND b.pickup_date = ? AND b.status NOT IN ("cancelled","refunded","finish")';
        $params = [$vehicleId, $date];
        if ($excludeBookingId) {
            $sql .= ' AND b.id != ?';
            $params[] = $excludeBookingId;
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        if ($st->fetch()) return false;
        // Explicit blocks
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';
        $st = $pdo->prepare('SELECT 1 FROM vehicle_blocks WHERE vehicle_id = ? AND starts_at <= ? AND ends_at >= ? LIMIT 1');
        $st->execute([$vehicleId, $end, $start]);
        return !$st->fetch();
    }

    public static function driverAvailable(PDO $pdo, int $driverId, string $date, ?int $excludeBookingId = null): bool
    {
        $st = $pdo->prepare('SELECT status FROM drivers WHERE id = ? LIMIT 1');
        $st->execute([$driverId]);
        $d = $st->fetch();
        if (!$d || $d['status'] !== 'active') return false;
        $sql = 'SELECT 1 FROM bookings b JOIN dispatches d ON d.booking_id = b.id WHERE d.driver_id = ? AND b.pickup_date = ? AND b.status NOT IN ("cancelled","refunded","finish")';
        $params = [$driverId, $date];
        if ($excludeBookingId) {
            $sql .= ' AND b.id != ?';
            $params[] = $excludeBookingId;
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return !$st->fetch();
    }

    public static function assign(PDO $pdo, int $bookingId, ?int $driverId, ?int $vehicleId, int $adminId, ?string $tmpDriverName = null, ?string $tmpDriverPhone = null, ?array $tmpVehicle = null): array
    {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b) return ['error' => 'Booking not found.'];
        if ($driverId && !self::driverAvailable($pdo, $driverId, $b['pickup_date'], $bookingId)) {
            return ['error' => 'Driver is not available for this date.'];
        }
        if ($vehicleId && !self::vehicleAvailable($pdo, $vehicleId, $b['pickup_date'], $b['pickup_time'], $bookingId)) {
            return ['error' => 'Vehicle is not available for this date.'];
        }
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM dispatches WHERE booking_id = ? LIMIT 1');
            $st->execute([$bookingId]);
            $existing = $st->fetch();
            if ($existing) {
                $pdo->prepare('INSERT INTO dispatch_history (dispatch_id, booking_id, old_driver_id, new_driver_id, old_vehicle_id, new_vehicle_id, actor_type, actor_id, note) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$existing['id'], $bookingId, $existing['driver_id'], $driverId, $existing['vehicle_id'], $vehicleId, 'admin', $adminId, 'Reassignment']);
                $pdo->prepare('UPDATE dispatches SET driver_id = ?, temporary_driver_name = ?, temporary_driver_phone = ?, vehicle_id = ?, temporary_vehicle_snapshot = ?, status = "reassigned", reassigned_at = NOW(), updated_at = NOW() WHERE id = ?')
                    ->execute([$driverId, $tmpDriverName, $tmpDriverPhone, $vehicleId, $tmpVehicle ? json_encode($tmpVehicle) : null, $existing['id']]);
            } else {
                $pdo->prepare('INSERT INTO dispatches (booking_id, driver_id, temporary_driver_name, temporary_driver_phone, vehicle_id, temporary_vehicle_snapshot, status) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$bookingId, $driverId, $tmpDriverName, $tmpDriverPhone, $vehicleId, $tmpVehicle ? json_encode($tmpVehicle) : null, 'assigned']);
                $did = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO dispatch_history (dispatch_id, booking_id, old_driver_id, new_driver_id, old_vehicle_id, new_vehicle_id, actor_type, actor_id, note) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$did, $bookingId, null, $driverId, null, $vehicleId, 'admin', $adminId, 'Initial assignment']);
            }
            if ($b['status'] === 'confirmed' || $b['status'] === 'booking_received') {
                $pdo->prepare('UPDATE bookings SET status = "assigned", updated_at = NOW() WHERE id = ?')->execute([$bookingId]);
                $pdo->prepare('INSERT INTO booking_status_logs (booking_id, old_status, new_status, actor_type, actor_id, note) VALUES (?,?,?,?,?,?)')
                    ->execute([$bookingId, $b['status'], 'assigned', 'admin', $adminId, 'Dispatch assigned']);
            }
            $pdo->commit();
            audit($pdo, 'admin', $adminId, 'dispatch.assign', 'booking', $bookingId, ['driver' => $driverId, 'vehicle' => $vehicleId]);
            return ['ok' => true];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_error('dispatch assign failed: ' . $ex->getMessage());
            return ['error' => 'Could not assign dispatch.'];
        }
    }

    public const DRIVER_FLOW = ['assigned','on_the_way','arrived','at_pickup_location','on_board','finish'];
    public const BOOKING_MAP = ['assigned' => 'assigned','on_the_way' => 'on_the_way','arrived' => 'arrived','at_pickup_location' => 'at_pickup_location','on_board' => 'on_board','finish' => 'finish'];

    public static function driverUpdateStatus(PDO $pdo, int $bookingId, int $driverId, string $newStatus): array
    {
        if (!in_array($newStatus, self::DRIVER_FLOW, true)) return ['error' => 'Invalid status.'];
        if (!driver_assigned_booking($pdo, $driverId, $bookingId)) return ['error' => 'Not authorized for this ride.'];
        $st = $pdo->prepare('SELECT status FROM bookings WHERE id = ? LIMIT 1');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b) return ['error' => 'Booking not found.'];
        $order = array_flip(self::DRIVER_FLOW);
        $curIdx = isset($order[$b['status']]) ? $order[$b['status']] : -1;
        $newIdx = $order[$newStatus];
        if ($newIdx !== $curIdx + 1 && !($curIdx === -1 && $newStatus === 'on_the_way')) {
            // allow assigned -> on_the_way as first step
            if (!($b['status'] === 'assigned' && $newStatus === 'on_the_way')) {
                return ['error' => 'Invalid status transition.'];
            }
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE bookings SET status = ?, completed_at = CASE WHEN ? = "finish" THEN NOW() ELSE completed_at END, updated_at = NOW() WHERE id = ?')
                ->execute([$newStatus, $newStatus, $bookingId]);
            $pdo->prepare('INSERT INTO driver_status_logs (booking_id, driver_id, old_status, new_status) VALUES (?,?,?,?)')
                ->execute([$bookingId, $driverId, $b['status'], $newStatus]);
            $pdo->prepare('INSERT INTO booking_status_logs (booking_id, old_status, new_status, actor_type, actor_id, note) VALUES (?,?,?,?,?,?)')
                ->execute([$bookingId, $b['status'], $newStatus, 'driver', $driverId, 'Driver status update']);
            $pdo->commit();
            audit($pdo, 'driver', $driverId, 'trip.status', 'booking', $bookingId, ['to' => $newStatus]);
            if ($newStatus === 'finish') {
                require_once __DIR__ . '/EarningsService.php';
                EarningsService::createForBooking($pdo, $bookingId, $driverId);
            }
            return ['ok' => true];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['error' => 'Could not update status.'];
        }
    }
}
