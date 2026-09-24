<?php
declare(strict_types=1);

/**
 * Pricing engine (last.md §16, §98).
 * Active mode = Per-Mile OR Hourly. Server-side calculation only.
 * Order: base -> additional charges -> subtotal -> coupon -> tax -> total.
 */
final class PricingService
{
    public static function activeMode(PDO $pdo): string
    {
        $mode = (string)(setting($pdo, 'pricing_mode', 'per_mile'));
        return $mode === 'hourly' ? 'hourly' : 'per_mile';
    }

    public static function taxPercent(PDO $pdo): float
    {
        return (float)(setting($pdo, 'tax_percent', '0'));
    }

    /** Validate + calculate a quote. Never trusts client totals. Returns breakdown. */
    public static function calculate(PDO $pdo, array $input): array
    {
        $errors = [];
        $service = $input['service_type'] ?? '';
        if (!in_array($service, ['point_to_point','airport','hourly'], true)) {
            $errors['service_type'] = 'Invalid service.';
        }
        $vehicleId = (int)($input['vehicle_id'] ?? 0);
        $st = $pdo->prepare('SELECT v.*, pr.per_mile_rate, pr.hourly_rate FROM vehicles v LEFT JOIN pricing_rates pr ON pr.vehicle_id = v.id AND pr.active = 1 WHERE v.id = ? AND v.status = "active" LIMIT 1');
        $st->execute([$vehicleId]);
        $vehicle = $st->fetch();
        if (!$vehicle) $errors['vehicle_id'] = 'Selected vehicle is unavailable.';

        $passengers = (int)($input['passengers'] ?? 1);
        $luggage = (int)($input['luggage'] ?? 0);
        if ($vehicle && $passengers > (int)$vehicle['passenger_capacity']) {
            $errors['passengers'] = 'Exceeds vehicle passenger capacity (' . $vehicle['passenger_capacity'] . ').';
        }
        if ($vehicle && $luggage > (int)$vehicle['luggage_capacity']) {
            $errors['luggage'] = 'Exceeds vehicle luggage capacity (' . $vehicle['luggage_capacity'] . ').';
        }

        $stops = $input['stops'] ?? [];
        if (count($stops) > 6) $errors['stops'] = 'Maximum 6 stops allowed.';

        $mode = self::activeMode($pdo);
        $mileage = isset($input['mileage']) ? (float)$input['mileage'] : null;
        $hours = isset($input['hours']) ? (float)$input['hours'] : null;
        if ($mode === 'per_mile') {
            // mileage may be null when maps disabled -> awaiting_pricing path
            if ($mileage !== null && $mileage < 0) $errors['mileage'] = 'Invalid mileage.';
        } else {
            if ($service === 'hourly' || $mode === 'hourly') {
                if ($hours === null || $hours < 2) $errors['hours'] = 'Hourly minimum is 2 hours.';
            }
        }

        if ($errors) return ['errors' => $errors];

        $lines = [];
        $base = 0.0;
        if ($mode === 'per_mile') {
            $rate = (float)($vehicle['per_mile_rate'] ?? 0);
            $miles = $mileage ?? 0;
            $base = round($rate * $miles, 2);
            $lines[] = ['charge_type' => 'base_mileage', 'description' => sprintf('Mileage: %.2f mi @ $%s/mi', $miles, money($rate)), 'quantity' => $miles, 'unit_price' => $rate, 'total' => $base, 'source' => 'pricing_engine'];
        } else {
            $rate = (float)($vehicle['hourly_rate'] ?? 0);
            $h = max(2, $hours ?? 2);
            $base = round($rate * $h, 2);
            $lines[] = ['charge_type' => 'base_hourly', 'description' => sprintf('Hourly: %.2f hr @ $%s/hr', $h, money($rate)), 'quantity' => $h, 'unit_price' => $rate, 'total' => $base, 'source' => 'pricing_engine'];
        }

        // Add-ons (fixed catalogue)
        $addonPrices = ['meet_greet' => 25.00, 'child_seat' => 15.00, 'booster_seat' => 10.00];
        foreach (['meet_greet','child_seat','booster_seat'] as $addon) {
            if (!empty($input['addons'][$addon])) {
                $amt = $addonPrices[$addon];
                $lines[] = ['charge_type' => $addon, 'description' => ucwords(str_replace('_',' ', $addon)), 'quantity' => 1, 'unit_price' => $amt, 'total' => $amt, 'source' => 'pricing_engine'];
            }
        }

        // Extra stops beyond... each additional stop charged per additional_charge_types
        $extraStopAmt = (float)(self::chargeAmount($pdo, 'extra_stop') ?? 20.00);
        foreach ($stops as $i => $s) {
            if (trim((string)$s) === '') continue;
            $lines[] = ['charge_type' => 'extra_stop', 'description' => 'Extra stop ' . ($i+1), 'quantity' => 1, 'unit_price' => $extraStopAmt, 'total' => $extraStopAmt, 'source' => 'pricing_engine'];
        }

        // Manual additional charges supplied by admin (toll, parking, airport_fee, custom_fee...)
        foreach ($input['manual_charges'] ?? [] as $mc) {
            $amt = round((float)($mc['amount'] ?? 0), 2);
            if ($amt <= 0) continue;
            $lines[] = ['charge_type' => preg_replace('/[^a-z_]/','', (string)($mc['code'] ?? 'custom_fee')), 'description' => substr((string)($mc['label'] ?? 'Additional charge'),0,255), 'quantity' => 1, 'unit_price' => $amt, 'total' => $amt, 'source' => 'admin_manual'];
        }

        $subtotal = round(array_sum(array_column($lines, 'total')), 2);

        // Coupon
        $discount = 0.0;
        $couponId = null;
        $couponCode = trim((string)($input['coupon_code'] ?? ''));
        if ($couponCode !== '') {
            $c = self::validateCoupon($pdo, $couponCode, $subtotal, isset($input['customer_id']) ? (int)$input['customer_id'] : null, isset($input['guest_email']) ? (string)$input['guest_email'] : null);
            if (isset($c['error'])) {
                $errors['coupon_code'] = $c['error'];
                return ['errors' => $errors];
            }
            $couponId = (int)$c['coupon']['id'];
            $discount = $c['discount'];
            $lines[] = ['charge_type' => 'coupon', 'description' => 'Coupon ' . $couponCode, 'quantity' => 1, 'unit_price' => -$discount, 'total' => -$discount, 'source' => 'coupon'];
        }

        $taxable = max(0, $subtotal - $discount);
        $tax = round($taxable * self::taxPercent($pdo) / 100, 2);
        if ($tax > 0) {
            $lines[] = ['charge_type' => 'tax', 'description' => 'Tax (' . self::taxPercent($pdo) . '%)', 'quantity' => 1, 'unit_price' => $tax, 'total' => $tax, 'source' => 'tax'];
        }
        $total = round($taxable + $tax, 2);

        return [
            'mode' => $mode,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'coupon_id' => $couponId,
        ];
    }

    public static function chargeAmount(PDO $pdo, string $code): ?float
    {
        $st = $pdo->prepare('SELECT amount FROM additional_charge_types WHERE code = ? AND active = 1 LIMIT 1');
        $st->execute([$code]);
        $r = $st->fetch();
        return $r ? (float)$r['amount'] : null;
    }

    /** Returns ['coupon'=>..., 'discount'=>...] or ['error'=>...] */
    public static function validateCoupon(PDO $pdo, string $code, float $subtotal, ?int $customerId = null, ?string $guestEmail = null): array
    {
        $st = $pdo->prepare('SELECT * FROM coupons WHERE code = ? AND active = 1 LIMIT 1');
        $st->execute([$code]);
        $c = $st->fetch();
        if (!$c) return ['error' => 'Invalid coupon.'];
        $now = date('Y-m-d H:i:s');
        if ($c['starts_at'] && $c['starts_at'] > $now) return ['error' => 'Coupon not yet valid.'];
        if ($c['expires_at'] && $c['expires_at'] < $now) return ['error' => 'Coupon expired.'];
        if ($c['usage_limit'] !== null && (int)$c['used_count'] >= (int)$c['usage_limit']) return ['error' => 'Coupon usage limit reached.'];
        if ((float)$c['minimum_subtotal'] > $subtotal) return ['error' => 'Minimum subtotal $' . money($c['minimum_subtotal']) . ' required.'];
        if ($c['customer_limit'] !== null) {
            $used = 0;
            if ($customerId) {
                $st = $pdo->prepare('SELECT COUNT(*) c FROM coupon_redemptions WHERE coupon_id = ? AND customer_id = ?');
                $st->execute([$c['id'], $customerId]);
                $used = (int)$st->fetch()['c'];
            } elseif ($guestEmail) {
                $st = $pdo->prepare('SELECT COUNT(*) c FROM coupon_redemptions WHERE coupon_id = ? AND guest_email = ?');
                $st->execute([$c['id'], strtolower($guestEmail)]);
                $used = (int)$st->fetch()['c'];
            }
            if ($used >= (int)$c['customer_limit']) return ['error' => 'This coupon has already been used the maximum times for this account.'];
        }
        $discount = $c['type'] === 'percent' ? round($subtotal * (float)$c['value'] / 100, 2) : min((float)$c['value'], $subtotal);
        return ['coupon' => $c, 'discount' => round($discount, 2)];
    }

    public static function consumeCoupon(PDO $pdo, int $couponId, int $bookingId, ?int $customerId = null, ?string $guestEmail = null): void
    {
        $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')->execute([$couponId]);
        try {
            $pdo->prepare('INSERT INTO coupon_redemptions (coupon_id, booking_id, customer_id, guest_email) VALUES (?,?,?,?)')
                ->execute([$couponId, $bookingId, $customerId, $guestEmail ? strtolower($guestEmail) : null]);
        } catch (Throwable $ex) {
            log_error('coupon redemption record failed: ' . $ex->getMessage());
        }
    }
}
